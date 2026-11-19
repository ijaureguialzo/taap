<?php

namespace App\Http\Controllers\Proxy;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentToken;
use App\Models\ModelProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Model Proxy (design.md D4): los agentes NO ven claves de proveedores.
 *
 *   agente -> Bearer <token de agente> -> /proxy/agents/{id}/v1/chat/completions
 *   orquestador -> clave real del provider -> upstream
 *
 * Passthrough de /v1/chat/completions (OpenAI) y /v1/messages (Anthropic).
 * v1 no re-streama: si el agente pide stream, se reescribe a no-stream y se
 * devuelve el JSON final (los eventos de UX llegan por la superficie nativa
 * del runtime, no por el modelo).
 */
class ModelProxyController extends Controller
{
    /** Host:port presentado al upstream (lo fija __invoke). */
    protected string $upstreamHost = '';

    public function __invoke(Request $request, Agent $agent): SymfonyResponse
    {
        $token = AgentToken::where('agent_id', $agent->id)->first();
        if (! $token || ! hash_equals($token->token_hash, hash('sha256', $this->bearer($request)))) {
            return response()->json(['error' => ['message' => 'token de agente inválido']], 401);
        }

        $provider = $agent->model?->provider;
        if (! $provider) {
            return response()->json(['error' => ['message' => 'el agente no tiene modelo configurado']], 422);
        }

        $upstream = rtrim($provider->base_url, '/').$this->upstreamPath($request, $agent);
        // El proxy debe presentar el Host del upstream (algunos servidores,
        // p. ej. uvicorn con --allowed-hosts, rechazan otro Host).
        $host = parse_url($upstream, PHP_URL_HOST) ?: '';
        $port = parse_url($upstream, PHP_URL_PORT);
        // `host.docker.internal` es solo una forma de llegar al host desde el
        // contenedor; el servidor upstream se ve a si mismo por 127.0.0.1
        // (algunos validadores de Host lo exigen asi).
        $host = $host === 'host.docker.internal' ? '127.0.0.1' : $host;
        $this->upstreamHost = $host.($port ? ':'.$port : '');
        $body = $request->all();
        $body['stream'] = false;

        // Modelos de razonamiento (Qwen3 etc.) pueden gastar el budget entero
        // en `reasoning_content` y dejar `content` null si no hay tope. Se
        // inyecta un max_tokens por defecto solo si el cliente no lo da.
        if ((! array_key_exists('max_tokens', $body) || $body['max_tokens'] === null)
            && (! array_key_exists('max_completion_tokens', $body) || $body['max_completion_tokens'] === null)) {
            // La linea del modelo puede fijar su tope (gana sobre el global)
            $model = $agent->model;
            $lineMax = $model->max_tokens;
            $body['max_tokens'] = max(
                (int) config('taap.proxy.default_max_tokens', 2048),
                (int) ($lineMax ?? 0),
            );
        }

        // Modelos de razonamiento (Qwen3 etc.): algunos routers exponen el
        // interruptor de pensamiento; con 'high' invoca tools de forma fiable.
        if (! array_key_exists('reasoning_effort', $body)) {
            $body['reasoning_effort'] = config('taap.proxy.default_reasoning_effort', 'high');
        }

        // Temperature baja por defecto: los modelos de razonamiento en
        // Qwen son bordeados en el muestreo (50% del tiempo piensan sin
        // invocar la tool); con 0 la decision de tool-calls es estable.
        if (! array_key_exists('temperature', $body) || $body['temperature'] === null) {
            $body['temperature'] = (float) config('taap.proxy.default_temperature', 0);
        }

        Log::info('ModelProxy: req '.$request->method().' '.$request->getPathInfo().' model='.$request->input('model').' stream='.var_export($request->input('stream'), true));
        Log::debug('ModelProxy: body '.json_encode($body));
        $headers = $this->upstreamHeaders($provider);
        if ($host !== '') {
            $headers['Host'] = $this->upstreamHost;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(1800)
                ->post($upstream, $body);
        } catch (\Throwable $e) {
            Log::warning('ModelProxy: error upstream: '.$e->getMessage());

            return response()->json(
                ['error' => ['message' => 'error de conexión con el proveedor']],
                502
            );
        }

        Log::info('ModelProxy: upstream rc='.$response->status());
        $data = $response->json();

        if ($response->successful()) {
            $data = $this->mergeReasoning($data);

            if ($request->input('stream')) {
                return $this->asSse($data);
            }

            return new JsonResponse($data, $response->status());
        }

        // Errores upstream: translúcido (el runtime los entiende)
        return new JsonResponse($response->json() ?? ['error' => ['message' => $response->body()]], $response->status());
    }

    protected function bearer(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    }

    /**
     * Modelos de razonamiento (Qwen3 etc.): si el upstream gasta el budget
     * entero en reasoning, `content` llega null. Clientes que no leen
     * `reasoning_content` (Hermes, Pi) verian respuesta vacía: fusionamos
     * el razonamiento en `content` cuando esta es vacía.
     *
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    protected function mergeReasoning(?array $data): ?array
    {
        $choice = $data['choices'][0] ?? null;
        $message = $choice['message'] ?? null;
        if (! is_array($message)) {
            return $data;
        }

        $content = trim((string) ($message['content'] ?? ''));
        $reasoning = trim((string) ($message['reasoning_content'] ?? ''));

        // Sin tool_calls: si el modelo solo pensó (content vacio), la
        // respuesta visible es el pensamiento. CON tool_calls NO se hace
        // el merge: un content no vacio junto a tool_calls hace que el
        // runtime (pi) trate el turno como finalizado y no ejecute la tool.
        if ($content === '' && $reasoning !== '' && empty($message['tool_calls'])) {
            $data['choices'][0]['message']['content'] = $reasoning;
        }

        return $data;
    }

    /**
     * /proxy/agents/{id}/v1/chat/completions -> /chat/completions
     * /proxy/agents/{id}/v1/messages          -> /v1/messages (Anthropic)
     */
    protected function upstreamPath(Request $request, Agent $agent): string
    {
        $path = $request->getPathInfo(); // /api/proxy/agents/3/v1/chat/completions
        $needle = '/proxy/agents/'.$agent->id;

        // El prefijo /api depende de como se monte la app: localizar el
        // segmento por valor, no por posicion.
        $pos = strrpos($path, $needle);
        if ($pos === false) {
            return $path;
        }

        $rest = substr($path, $pos + strlen($needle)); // /v1/chat/completions

        // El base_url del provider ya incluye su /v1: no duplicarlo
        return str_starts_with($rest, '/v1') ? substr($rest, 3) : $rest;
    }

    /**
     * v1 (design.md D4): el upstream va en modo no-stream; si el cliente
     * pidio stream, se reconvierte el JSON final a un chorrillo SSE minimo.
     *
     * @param  array<string, mixed>|null  $data
     */
    protected function asSse(?array $data): Response
    {
        $choice = $data['choices'][0] ?? null;
        $message = $choice['message'] ?? [];
        $id = $data['id'] ?? 'chatcmpl-'.Str::uuid()->toString();
        $model = $data['model'] ?? 'unknown';

        $delta = ['role' => 'assistant', 'content' => ''];
        if (! empty($message['reasoning_content'])) {
            $delta['reasoning_content'] = $message['reasoning_content'];
        }
        // tool_calls en el primer delta: si el modelo decide invocar una
        // herramienta, el delta debe llevarla (si se cae, el runtime ve un
        // mensaje de texto y el turno termina sin ejecutar nada).
        if (! empty($message['tool_calls'])) {
            $delta['tool_calls'] = $message['tool_calls'];
        }
        $finish = $choice['finish_reason'] ?? 'stop';
        $chunk = fn (array $d, ?string $finish) => [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $data['created'] ?? time(),
            'model' => $model,
            'choices' => [['index' => 0, 'delta' => $d, 'finish_reason' => $finish]],
        ];

        $events = [
            $chunk($delta, null),
            $chunk(['content' => (string) ($message['content'] ?? '')], null),
            $chunk([], $finish),
        ];

        $body = '';
        foreach ($events as $event) {
            $body .= 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
        }
        $body .= "data: [DONE]\n\n";

        return new Response($body, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function upstreamHeaders(ModelProvider $provider): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($provider->api_key) {
            $headers = match ($provider->type) {
                'anthropic' => $headers + [
                    'x-api-key' => $provider->api_key,
                    'anthropic-version' => '2023-06-01',
                ],
                default => $headers + ['Authorization' => 'Bearer '.$provider->api_key],
            };
        }

        return $headers;
    }
}

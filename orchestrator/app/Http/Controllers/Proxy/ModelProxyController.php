<?php

namespace App\Http\Controllers\Proxy;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $upstream = rtrim($provider->base_url, '/') . $this->upstreamPath($request, $agent);
        // El proxy debe presentar el Host del upstream (algunos servidores,
        // p. ej. uvicorn con --allowed-hosts, rechazan otro Host).
        $host = parse_url($upstream, PHP_URL_HOST) ?: '';
        $port = parse_url($upstream, PHP_URL_PORT);
        // `host.docker.internal` es solo una forma de llegar al host desde el
        // contenedor; el servidor upstream se ve a si mismo por 127.0.0.1
        // (algunos validadores de Host lo exigen asi).
        $host = $host === 'host.docker.internal' ? '127.0.0.1' : $host;
        $this->upstreamHost = $host . ($port ? ':' . $port : '');
        $body = $request->all();
        $body['stream'] = false;

        // Modelos de razonamiento (Qwen3 etc.) pueden gastar el budget entero
        // en `reasoning_content` y dejar `content` null si no hay tope. Se
        // inyecta un max_tokens por defecto solo si el cliente no lo da.
        if ((! isset($body['max_tokens']) || $body['max_tokens'] === null)
            && (! isset($body['max_completion_tokens']) || $body['max_completion_tokens'] === null)) {
            $body['max_tokens'] = (int) config('taap.proxy.default_max_tokens', 2048);
        }

        // Modelos de razonamiento (Qwen3 etc.): algunos routers exponen el
        // interruptor de pensamiento; lo bajamos para que el turno termine
        // con respuesta y no se quede solo en `reasoning_content`.
        if (! array_key_exists('reasoning_effort', $body)) {
            $body['reasoning_effort'] = config('taap.proxy.default_reasoning_effort', 'low');
        }

        Log::info('ModelProxy: req ' . $request->method() . ' ' . $request->getPathInfo() . ' model=' . $request->input('model') . ' stream=' . var_export($request->input('stream'), true));
        $headers = $this->upstreamHeaders($provider);
        if ($host !== '') {
            $headers['Host'] = $this->upstreamHost;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(1800)
                ->post($upstream, $body);
        } catch (\Throwable $e) {
            Log::warning('ModelProxy: error upstream: ' . $e->getMessage());

            return response()->json(
                ['error' => ['message' => 'error de conexión con el proveedor']],
                502
            );
        }

        Log::info('ModelProxy: upstream rc=' . $response->status());
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

        if ($content === '' && $reasoning !== '') {
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
        $needle = '/proxy/agents/' . $agent->id;

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
     */
    protected function asSse(?array $data): \Illuminate\Http\Response
    {
        $choice = $data['choices'][0] ?? null;
        $message = $choice['message'] ?? [];
        $id = $data['id'] ?? 'chatcmpl-' . \Illuminate\Support\Str::uuid()->toString();
        $model = $data['model'] ?? 'unknown';

        $delta = ['role' => 'assistant', 'content' => ''];
        if (! empty($message['reasoning_content'])) {
            $delta['reasoning_content'] = $message['reasoning_content'];
        }
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
            $chunk([], 'stop'),
        ];

        $body = '';
        foreach ($events as $event) {
            $body .= 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        }
        $body .= "data: [DONE]\n\n";

        return new \Illuminate\Http\Response($body, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /** @return array<string, string> */
    protected function upstreamHeaders($provider): array
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
                default => $headers + ['Authorization' => 'Bearer ' . $provider->api_key],
            };
        }

        return $headers;
    }
}

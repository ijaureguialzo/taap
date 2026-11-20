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
 *   agente -> token de agente -> /proxy/agents/{id}/v1/chat/completions (OpenAI)
 *                          o -> /proxy/agents/{id}/v1/messages   (Anthropic)
 *   orquestador -> clave real del provider -> upstream
 *
 * v1 no re-streama el upstream: se pide no-stream y, si el cliente pidió
 * stream, se sintetiza el stream en el formato de la API (OpenAI SSE para
 * chat/completions; Anthropic SSE para /v1/messages - el CLI de Claude Code
 * exige el shape Anthropic: spike add-claude-code-runtime 1.2).
 *
 * Endpoints de servicio que consulta el CLI Claude al arrancar:
 *   GET /v1/models (validación de modelo) y /api/hello (health).
 */
class ModelProxyController extends Controller
{
    /** Host:port presentado al upstream (lo fija __invoke). */
    protected string $upstreamHost = '';

    public function __invoke(Request $request, Agent $agent): SymfonyResponse
    {
        if (! $this->agentTokenValid($agent, $this->tokenOf($request))) {
            return response()->json(['error' => ['message' => 'token de agente inválido']], 401);
        }

        $provider = $agent->model?->provider;
        if (! $provider) {
            return response()->json(['error' => ['message' => 'el agente no tiene modelo configurado']], 422);
        }

        $isAnthropic = $provider->type === 'anthropic';
        $upstream = rtrim($provider->base_url, '/').$this->upstreamPath($request, $agent, $provider->type);
        // El proxy debe presentar el Host del upstream (algunos servidores,
        // p. ej. uvicorn con --allowed-hosts, rechazan otro Host).
        $host = parse_url($upstream, PHP_URL_HOST) ?: '';
        $port = parse_url($upstream, PHP_URL_PORT);
        // `host.docker.internal` es solo una forma de llegar al host desde el
        // contenedor; el servidor upstream se ve a si mismo por 127.0.0.1
        // (algunos validadores de Host lo exigen asi).
        $host = $host === 'host.docker.internal' ? '127.0.0.1' : $host;
        $this->upstreamHost = $host.($port ? ':'.$port : '');

        // Anthropic: el body se toma del JSON crudo (sin mezclar la query
        // string: el CLI llama con ?beta=true y la API real rechaza campos
        // top-level ajenos). OpenAI: el comportamiento v1.0 (all()).
        $body = $isAnthropic ? ($request->json()->all() ?? []) : $request->all();
        // Anthropic: el upstream NATIVO sabe SSE y el CLI consume su formato
        // tal cual: relay transparente (sin re-sintetizar). El resto de
        // clientes (OpenAI v1.0) va en no-stream: el proxy sintetiza el SSE.
        $wantsStream = (bool) $request->input('stream');
        $body['stream'] = $isAnthropic ? $wantsStream : false;

        // Modelos de razonamiento (Qwen3 etc.) pueden gastar el budget entero
        // en `reasoning_content` y dejar `content` null si no hay tope. Se
        // inyecta un max_tokens por defecto solo si el cliente no lo da.
        // (Anthropic REQUIERE max_tokens: se inyecta igual si falta.)
        if ((! array_key_exists('max_tokens', $body) || $body['max_tokens'] === null)
            && (! array_key_exists('max_completion_tokens', $body) || $body['max_completion_tokens'] === null)) {
            // La linea del modelo puede fijar su tope (gana sobre el global)
            $model = $agent->model;
            $body['max_tokens'] = max(
                (int) config('taap.proxy.default_max_tokens', 2048),
                (int) ($model->max_tokens ?? 0),
            );
        }

        if (! $isAnthropic) {
            // Modelos de razonamiento (Qwen3 etc.): algunos routers exponen
            // el interruptor de pensamiento; con 'high' invoca tools de
            // forma fiable. CAMPO OPENAI: no se envía a la API Anthropic
            // (spike 1.2: inyección condicional al tipo de proveedor).
            if (! array_key_exists('reasoning_effort', $body)) {
                $body['reasoning_effort'] = config('taap.proxy.default_reasoning_effort', 'high');
            }

            // Temperature baja por defecto: los modelos de razonamiento en
            // Qwen son bordeados en el muestreo (50% del tiempo piensan sin
            // invocar la tool); con 0 la decision de tool-calls es estable.
            // (A Anthropic no se le impone: el runtime fija su propio
            // muestreo y la API no conoce reasoning_effort.)
            if (! array_key_exists('temperature', $body) || $body['temperature'] === null) {
                $body['temperature'] = (float) config('taap.proxy.default_temperature', 0);
            }
        }

        Log::info('ModelProxy: req '.$request->method().' '.$request->getPathInfo().' model='.$request->input('model').' stream='.var_export($request->input('stream'), true));
        Log::debug('ModelProxy: body '.json_encode($body));
        $headers = $this->upstreamHeaders($provider);
        if ($host !== '') {
            $headers['Host'] = $this->upstreamHost;
        }
        if ($isAnthropic) {
            // Los campos betas del body (thinking, ...) solo son válidos
            // con el header beta del cliente: se reenvía (spike 1.2).
            if ($beta = $request->header('anthropic-beta')) {
                $headers['anthropic-beta'] = $beta;
            }
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
            // Relay nativo: si el upstream respondio su propio stream
            // (Anthropic, stream:true), se reenvian sus bytes SSE sin
            // transformar (el CLI consume ese formato de serie).
            if ($isAnthropic && $wantsStream
                && str_contains((string) $response->header('Content-Type'), 'text/event-stream')) {
                return new Response(
                    (string) $response->body(),
                    $response->status(),
                    [
                        'Content-Type' => (string) $response->header('Content-Type'),
                        'Cache-Control' => 'no-cache',
                    ],
                );
            }

            // mergeReasoning es OpenAI (choices[].message); con Anthropic el
            // JSON se pasa tal cual (sus bloques ya separan thinking/texto).
            $data = $isAnthropic ? $data : $this->mergeReasoning($data);

            if ($request->input('stream')) {
                return $isAnthropic
                    ? $this->asAnthropicSse($data)
                    : $this->asSse($data);
            }

            return new JsonResponse($data, $response->status());
        }

        // Errores upstream: translúcido (el runtime los entiende)
        return new JsonResponse($response->json() ?? ['error' => ['message' => $response->body()]], $response->status());
    }

    /**
     * POST /v1/responses (wire Responses de la API OpenAI; el runtime Codex
     * lo consume nativo — design add-codex-runtime D6bis).
     *
     * PASSTHROUGH: el body se reenvia tal cual (sin inyectar max_tokens,
     * reasoning_effort ni temperature: el esquema Responses es ajeno al
     * chat/completions y el runtime lo construye completo) y la respuesta
     * (JSON o SSE del upstream) se reemite sin transformar. Si el
     * upstream no implementa la ruta (404/405) el proxy devuelve un 501
     * legible en lugar de silenciar el request.
     */
    public function responses(Request $request, Agent $agent): SymfonyResponse
    {
        if (! $this->agentTokenValid($agent, $this->tokenOf($request))) {
            return response()->json(['error' => ['message' => 'token de agente inválido']], 401);
        }

        $provider = $agent->model?->provider;
        if (! $provider) {
            return response()->json(['error' => ['message' => 'el agente no tiene modelo configurado']], 422);
        }

        $upstream = rtrim($provider->base_url, '/').$this->upstreamPath($request, $agent, $provider->type);
        $host = parse_url($upstream, PHP_URL_HOST) ?: '';
        $port = parse_url($upstream, PHP_URL_PORT);
        $host = $host === 'host.docker.internal' ? '127.0.0.1' : $host;
        $this->upstreamHost = $host.($port ? ':'.$port : '');

        // Codex llama con stream=true y consume el SSE del upstream tal cual.
        $body = $request->json()->all() ?? [];

        Log::info('ModelProxy: req /v1/responses model='.$request->input('model').' stream='.var_export($request->input('stream'), true));

        $headers = $this->upstreamHeaders($provider);
        // SSE: algunos upstreams lo negocian por Accept.
        $headers['Accept'] = 'application/json, text/event-stream';
        if ($host !== '') {
            $headers['Host'] = $this->upstreamHost;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(1800)
                ->post($upstream, $body);
        } catch (\Throwable $e) {
            Log::warning('ModelProxy: error upstream (responses): '.$e->getMessage());

            return response()->json(
                ['error' => ['message' => 'error de conexión con el proveedor']],
                502
            );
        }

        // El upstream no tiene la ruta: error claro del proxy (spec).
        if ($response->status() === 404 || $response->status() === 405) {
            Log::warning('ModelProxy: upstream sin /v1/responses (rc='.$response->status().')');

            return response()->json([
                'error' => ['message' => 'el proveedor no soporta /v1/responses (wire Responses)'],
            ], 501);
        }

        Log::info('ModelProxy: upstream (responses) rc='.$response->status());

        // Relay transparente: bytes del upstream (SSE o JSON) sin tocar.
        return new Response(
            (string) $response->body(),
            $response->status(),
            [
                'Content-Type' => (string) $response->header('Content-Type'),
                'Cache-Control' => 'no-cache',
            ],
        );
    }

    /**
     * GET /v1/messages-adjunto: validación de modelos del CLI Claude.
     * Se sintetiza desde el modelo del agente (el CLI compara el `model`
     * pedido con esta lista al arrancar cada query; spike 1.2: el proxy no
     * la servia y el CLI la consultaba -> 404).
     */
    public function models(Request $request, Agent $agent): JsonResponse
    {
        if (! $this->agentTokenValid($agent, $this->tokenOf($request))) {
            return response()->json(['error' => ['message' => 'token de agente inválido']], 401);
        }

        $model = $agent->model;
        $data = $model ? [[
            'type' => 'model',
            'id' => $model->model_id,
            'display_name' => $model->name,
            'created_at' => 0,
            'max_input_tokens' => $model->context_window ?? 200000,
            'max_tokens' => $model->max_tokens ?? 8192,
        ]] : [];

        return new JsonResponse(['data' => $data]);
    }

    /**
     * Health check del CLI Claude (HEAD/GET /api/hello): el contenedor y la
     * red estan OK; la autenticacion se valida igual (un token malo debe
     * verse aqui, no en el primer turno).
     */
    public function hello(Request $request, Agent $agent): JsonResponse
    {
        if (! $this->agentTokenValid($agent, $this->tokenOf($request))) {
            return response()->json(['error' => ['message' => 'token de agente inválido']], 401);
        }

        return new JsonResponse([
            'status' => 'ok',
            'agent' => $agent->id,
        ]);
    }

    /**
     * El token de agente viaja en `Authorization: Bearer` (Pi/Hermes) o en
     * `x-api-key` (el CLI Claude usa la convención Anthropic; spike 1.2:
     * el proxy solo leía Bearer -> 401 en cada intento del CLI).
     */
    protected function tokenOf(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return (string) $request->header('x-api-key', '');
    }

    protected function agentTokenValid(Agent $agent, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $tokenModel = AgentToken::where('agent_id', $agent->id)->first();

        return $tokenModel && hash_equals($tokenModel->token_hash, hash('sha256', $token));
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
    protected function upstreamPath(Request $request, Agent $agent, ?string $providerType = null): string
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

        // OpenAI v1.0: el base_url del provider YA incluye su /v1 (p. ej.
        // http://vllm:8000/v1): quitarlo del path para no duplicarlo.
        // Anthropic (add-claude-code-runtime, spike 1.2): el base_url NO lleva
        // /v1 (http://gw:8901) y el CLI llama a /v1/messages y /v1/models:
        // ahi el /v1 SI es parte de la URL upstream.
        if ($providerType !== 'anthropic' && str_starts_with($rest, '/v1')) {
            return substr($rest, 3);
        }

        return $rest;
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
        $chunk = fn (array|object $d, ?string $finish, array $extra = []) => [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $data['created'] ?? time(),
            'model' => $model,
            'choices' => [['index' => 0, 'delta' => $d, 'finish_reason' => $finish]],
            ...$extra,
        ];

        // El chunk final usa delta {} (forma canonica OpenAI) y usage (los
        // clientes estrictos, p. ej. @ai-sdk/openai-compatible, lo validan;
        // los v1.0 la ignoran).
        $usage = $data['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        $events = [
            $chunk($delta, null),
            $chunk(['content' => (string) ($message['content'] ?? '')], null),
            // {} (objeto vacio): la forma canonica; [] (array) falla la
            // validacion Zod de @ai-sdk/openai-compatible.
            $chunk(new \stdClass, $finish, ['usage' => $usage]),
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
     * Stream en shape ANTHROPIC para /v1/messages (spike 1.2: el CLI Claude
     * solo consume SSE Anthropic; el upstream va en no-stream y aqui se
     * sintetiza). Emitido:
     *   message_start -> [content_block_start -> deltas* -> content_block_stop]+
     *   -> message_delta (stop_reason + usage) -> message_stop
     *
     * @param  array<string, mixed>|null  $data  respuesta JSON del upstream
     */
    protected function asAnthropicSse(?array $data): Response
    {
        $id = $data['id'] ?? 'msg_'.Str::uuid()->toString();
        $model = $data['model'] ?? 'unknown';
        $blocks = [];
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (is_array($block) && isset($block['type'])) {
                $blocks[] = $block;
            }
        }
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : ['input_tokens' => 0, 'output_tokens' => 0];

        $lines = [];
        $lines[] = ['message_start', [
            'type' => 'message_start',
            'message' => [
                'id' => $id,
                'type' => 'message',
                'role' => 'assistant',
                'model' => $model,
                'content' => [],
                'stop_reason' => null,
                'stop_sequence' => null,
                'usage' => ['input_tokens' => (int) ($usage['input_tokens'] ?? 0), 'output_tokens' => 0],
            ],
        ]];

        $index = 0;
        foreach ($blocks as $block) {
            $type = (string) $block['type'];
            if ($type === 'tool_use') {
                $lines[] = ['content_block_start', [
                    'type' => 'content_block_start',
                    'index' => $index,
                    'content_block' => [
                        'type' => 'tool_use',
                        'id' => (string) ($block['id'] ?? 'toolu_'.Str::uuid()->toString()),
                        'name' => (string) ($block['name'] ?? ''),
                        'input' => [],
                    ],
                ]];
                // El CLI reconstruye el input desde partial_json: un unico
                // delta con el JSON completo es valido y mas simple.
                $lines[] = ['content_block_delta', [
                    'type' => 'content_block_delta',
                    'index' => $index,
                    'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($block['input'] ?? new \stdClass, JSON_UNESCAPED_SLASHES)],
                ]];
            } elseif ($type === 'thinking') {
                $lines[] = ['content_block_start', [
                    'type' => 'content_block_start',
                    'index' => $index,
                    'content_block' => ['type' => 'thinking', 'thinking' => ''],
                ]];
                $lines[] = ['content_block_delta', [
                    'type' => 'content_block_delta',
                    'index' => $index,
                    'delta' => ['type' => 'thinking_delta', 'thinking' => (string) ($block['thinking'] ?? '')],
                ]];
            } else {
                $lines[] = ['content_block_start', [
                    'type' => 'content_block_start',
                    'index' => $index,
                    'content_block' => ['type' => 'text', 'text' => ''],
                ]];
                // El texto se reparte en trozos (el CLI no exige granularidad).
                $text = (string) ($block['text'] ?? '');
                for ($i = 0; $i < strlen($text); $i += 16) {
                    $lines[] = ['content_block_delta', [
                        'type' => 'content_block_delta',
                        'index' => $index,
                        'delta' => ['type' => 'text_delta', 'text' => mb_substr($text, $i, 16)],
                    ]];
                }
            }
            $lines[] = ['content_block_stop', [
                'type' => 'content_block_stop',
                'index' => $index,
            ]];
            $index++;
        }

        $lines[] = ['message_delta', [
            'type' => 'message_delta',
            'delta' => [
                'stop_reason' => $data['stop_reason'] ?? 'end_turn',
                'stop_sequence' => $data['stop_sequence'] ?? null,
            ],
            'usage' => ['input_tokens' => (int) ($usage['input_tokens'] ?? 0), 'output_tokens' => (int) ($usage['output_tokens'] ?? 0)],
        ]];
        $lines[] = ['message_stop', ['type' => 'message_stop']];

        $body = '';
        foreach ($lines as [$event, $payload]) {
            $body .= 'event: '.$event."\n";
            $body .= 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
        }

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

<?php

namespace Tests\Feature\Proxy;

use App\Models\Agent;
use App\Models\AgentToken;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Model Proxy: ruta Anthropic (add-claude-code-runtime, grupo 3) y
 * preservacion del comportamiento OpenAI v1.0. Upstream simulado con
 * Http::fake; el token de agente es real (issueFor).
 */
class ModelProxyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Agent $agent;

    protected ModelProvider $provider;

    protected Model $model;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->provider = ModelProvider::factory()->create([
            'type' => 'anthropic',
            // Sin /v1 (spike add-claude-code-runtime 1.2): el CLI llama a
            // /v1/messages y /v1/models y el proxy los reenvia intactos.
            'base_url' => 'http://upstream-anthropic.local',
            'api_key' => 'upstream-anthropic-key',
            'created_by' => $this->user->id,
        ]);
        $this->model = Model::factory()->create([
            'provider_id' => $this->provider->id,
            'model_id' => 'claude-test-1',
            'name' => 'Claude Test',
            'context_window' => 100000,
            'max_tokens' => 4096,
        ]);
        $this->agent = Agent::factory()->create([
            'owner_id' => $this->user->id,
            'model_id' => $this->model->id,
        ]);
        $token = AgentToken::issueFor($this->agent);
        $token->save();
        $this->token = $token->token;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    protected function messages(string $path = '/v1/messages', array $body = [], array $headers = [])
    {
        return $this->postJson("/api/proxy/agents/{$this->agent->id}{$path}", $body, $headers);
    }

    /* ---------- Anthropic (nuevo v1.1) ---------- */

    public function test_anthropic_stream_is_synthesized_as_anthropic_sse(): void
    {
        Http::fake([
            'upstream-anthropic.local/v1/messages*' => Http::response([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-test-1',
                'content' => [
                    ['type' => 'text', 'text' => 'Hola, estoy aqui.'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        $this->messages('/v1/messages?beta=true', [
            'model' => 'claude-test-1',
            'max_tokens' => 1024,
            'stream' => true,
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 1000],
            'messages' => [['role' => 'user', 'content' => 'hola']],
        ], [
            'x-api-key' => $this->token,
            'anthropic-version' => '2023-06-01',
            'anthropic-beta' => 'claude-code-20250219,thinking-2025-05-14',
        ]);

        // Upstream: clave real del provider + version + header beta del
        // cliente re-enviado; el stream pasa tal cual (Anthropic natively
        // streams; el relay solo actua si el upstream responde event-stream,
        // que no es el caso de este fake -> el proxy sintetiza el SSE).
        [$request] = Http::recorded(function ($request) {
            return str_contains($request->url(), 'upstream-anthropic');
        })->first();
        // Anthropic: el /v1 del request SI es parte de la URL upstream
        // (el base_url no lo lleva; en OpenAI v1.0 si lo lleva y se quita).
        $this->assertSame('http://upstream-anthropic.local/v1/messages', $request->url());
        $upstreamBody = $request->data();
        $this->assertTrue((bool) $upstreamBody['stream'], 'Anthropic: el stream se pide al upstream tal cual');
        $this->assertSame('enabled', $upstreamBody['thinking']['type'], 'los campos betas del cuerpo se conservan');
        $this->assertArrayNotHasKey('beta', $upstreamBody, 'la query string no se mezcla en el body');
        $this->assertArrayNotHasKey('reasoning_effort', $upstreamBody, 'campo OpenAI: no se envía a Anthropic');
        $this->assertArrayNotHasKey('temperature', $upstreamBody, 'no se impone muestreo al runtime Claude');
        $this->assertSame('upstream-anthropic-key', $request->header('x-api-key')[0]);
        $this->assertSame('2023-06-01', $request->header('anthropic-version')[0]);
        $this->assertSame('claude-code-20250219,thinking-2025-05-14', $request->header('anthropic-beta')[0]);

        // Cliente: stream en shape Anthropic (consumible por el CLI, spike 1.2)
        $this->assertDatabaseHas('agents', ['id' => $this->agent->id]);
        $response = $this->messages('/v1/messages?beta=true', [
            'model' => 'claude-test-1',
            'max_tokens' => 1024,
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'hola']],
        ], ['x-api-key' => $this->token]);

        $response->assertStatus(200)
            ->assertHeader('content-type', 'text/event-stream; charset=utf-8');

        $sse = $response->getContent();
        $this->assertStringContainsString('event: message_start', $sse);
        $this->assertStringContainsString('event: content_block_start', $sse);
        $this->assertStringContainsString('event: content_block_delta', $sse);
        $this->assertStringContainsString('event: content_block_stop', $sse);
        $this->assertStringContainsString('event: message_delta', $sse);
        $this->assertStringContainsString('event: message_stop', $sse);

        // El texto se reensambla tal cual
        $text = '';
        foreach (preg_split("/\n\n/", trim($sse)) as $frame) {
            foreach (explode("\n", $frame) as $line) {
                if (str_starts_with($line, 'data: ') && str_contains($line, 'text_delta')) {
                    $text .= json_decode(substr($line, 6), true)['delta']['text'] ?? '';
                }
            }
        }
        $this->assertSame('Hola, estoy aqui.', $text);

        // El bloque final lleva stop_reason
        $this->assertMatchesRegularExpression('/"stop_reason":"end_turn"/', $sse);
    }

    public function test_anthropic_native_stream_is_relayed_untouched(): void
    {
        $sse = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"m1\"}}\n\nevent: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";
        Http::fake([
            '*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $res = $this->messages('/v1/messages', [
            'model' => 'claude-test-1',
            'max_tokens' => 100,
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'hola']],
        ], [
            'x-api-key' => $this->token,
            'accept' => 'text/event-stream',
        ]);

        $res->assertStatus(200);
        $this->assertStringStartsWith('text/event-stream', (string) $res->headers->get('Content-Type'));
        $this->assertSame($sse, $res->content(), 'el stream nativo se rellea sin transformar');
    }

    public function test_anthropic_tool_use_block_streams_as_input_json_delta(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_2',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-test-1',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'ls']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 8, 'output_tokens' => 3],
        ])]);

        $response = $this->messages('/v1/messages', [
            'model' => 'claude-test-1',
            'max_tokens' => 100,
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'mira los ficheros']],
        ], ['x-api-key' => $this->token]);

        $sse = $response->getContent();
        $this->assertStringContainsString('"type":"tool_use"', $sse);
        $this->assertStringContainsString('"name":"Bash"', $sse);
        $this->assertStringContainsString('"partial_json":"{\\"command\\":\\"ls\\"}"', $sse);
        $this->assertMatchesRegularExpression('/"stop_reason":"tool_use"/', $sse);
    }

    public function test_anthropic_auth_accepts_x_api_key_header(): void
    {
        Http::fake(['*' => Http::response(['content' => [], 'stop_reason' => 'end_turn'], 200)]);

        // x-api-key solo (la convencion del CLI): valido
        $this->messages('/v1/messages', [
            'model' => 'claude-test-1', 'max_tokens' => 10, 'stream' => false,
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ], ['x-api-key' => $this->token])->assertStatus(200);

        // Bearer (Pi/Hermes) sigue valido
        $this->messages('/v1/messages', [
            'model' => 'claude-test-1', 'max_tokens' => 10, 'stream' => false,
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ], ['Authorization' => 'Bearer '.$this->token])->assertStatus(200);

        // Inválido en ambos
        $this->messages('/v1/messages', [
            'model' => 'claude-test-1', 'max_tokens' => 10, 'stream' => false,
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ], ['x-api-key' => 'taap-mal'])->assertStatus(401);
    }

    public function test_models_endpoint_serves_agent_model_for_cli_validation(): void
    {
        $this->getJson("/api/proxy/agents/{$this->agent->id}/v1/models", ['x-api-key' => $this->token])
            ->assertStatus(200)
            ->assertJson([
                'data' => [['type' => 'model', 'id' => 'claude-test-1']],
            ]);

        $this->getJson("/api/proxy/agents/{$this->agent->id}/v1/models", ['x-api-key' => 'taap-mal'])
            ->assertStatus(401);
    }

    public function test_api_hello_health_endpoint(): void
    {
        $this->get("/api/proxy/agents/{$this->agent->id}/api/hello", ['x-api-key' => $this->token])
            ->assertStatus(200);

        $this->head("/api/proxy/agents/{$this->agent->id}/api/hello", ['x-api-key' => $this->token])
            ->assertStatus(200);
    }

    /* ---------- OpenAI (v1.0 preservado) ---------- */

    public function test_openai_route_keeps_field_injection_and_sse(): void
    {
        $this->provider->forceFill(['type' => 'openai', 'base_url' => 'http://upstream-openai.local/v1'])->save();

        Http::fake([
            'upstream-openai.local/v1/chat/completions*' => Http::response([
                'id' => 'chatcmpl_1',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                'created' => 1,
                'model' => 'qwen-test',
            ]),
        ]);

        $this->postJson("/api/proxy/agents/{$this->agent->id}/v1/chat/completions", [
            'model' => 'qwen-test',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'hola']],
        ], ['Authorization' => 'Bearer '.$this->token])
            ->assertStatus(200)
            ->assertHeader('content-type', 'text/event-stream; charset=utf-8');

        [$request] = Http::recorded(function ($request) {
            return str_contains($request->url(), 'upstream-openai');
        })->first();
        $body = $request->data();
        $this->assertFalse((bool) $body['stream']);
        $this->assertSame('high', $body['reasoning_effort'], 'el campo OpenAI sigue inyectado');
        $this->assertSame(0.0, $body['temperature'], 'la temperature por defecto sigue inyectada');

        $this->assertStringContainsString('chat.completion.chunk', $this->postJson("/api/proxy/agents/{$this->agent->id}/v1/chat/completions", [
            'model' => 'qwen-test',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'hola']],
        ], ['Authorization' => 'Bearer '.$this->token])->getContent());
    }

    /* ---------- Responses (nuevo v1.1, runtime Codex) ---------- */

    public function test_responses_body_is_relayed_untouched(): void
    {
        // Codex consume el wire Responses de la API OpenAI: el proxy NO
        // inyecta max_tokens / reasoning_effort / temperature (el esquema
        // es ajeno a chat/completions).
        $this->provider->forceFill(['type' => 'openai', 'base_url' => 'http://upstream-openai.local/v1'])->save();

        $sse = "event: response.created\ndata: {\"type\":\"response.created\"}\n\ndata: [DONE]\n\n";
        Http::fake([
            'upstream-openai.local/v1/responses*' => Http::response($sse, 200, [
                'Content-Type' => 'text/event-stream',
            ]),
        ]);

        $body = [
            'model' => 'gpt-spike',
            'stream' => true,
            'input' => [['type' => 'message', 'role' => 'user', 'content' => 'hola']],
            'tools' => [['type' => 'function', 'name' => 'exec_command', 'parameters' => ['type' => 'object']]],
        ];

        $response = $this->postJson("/api/proxy/agents/{$this->agent->id}/v1/responses", $body, [
            'Authorization' => 'Bearer '.$this->token,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/event-stream; charset=utf-8');
        $this->assertSame($sse, $response->getContent(), 'el SSE del upstream se reemite tal cual');

        [$request] = Http::recorded(fn ($r) => str_contains($r->url(), 'upstream-openai'))->first();
        $sent = $request->data();
        $this->assertSame($body, $sent, 'el body se reenvia sin inyecciones');
    }

    public function test_responses_upstream_without_route_is_501(): void
    {
        $this->provider->forceFill(['type' => 'openai', 'base_url' => 'http://upstream-openai.local/v1'])->save();

        Http::fake([
            'upstream-openai.local/v1/responses*' => Http::response(['error' => 'not found'], 404),
        ]);

        $this->postJson("/api/proxy/agents/{$this->agent->id}/v1/responses", [
            'model' => 'gpt-spike',
        ], ['Authorization' => 'Bearer '.$this->token])
            ->assertStatus(501)
            ->assertJsonPath('error.message', 'el proveedor no soporta /v1/responses (wire Responses)');
    }

    public function test_responses_requires_agent_token(): void
    {
        $this->postJson("/api/proxy/agents/{$this->agent->id}/v1/responses", [
            'model' => 'gpt-spike',
        ], ['Authorization' => 'Bearer token-malo'])
            ->assertStatus(401);
    }

    public function test_agent_without_model_is_422(): void
    {
        $this->agent->forceFill(['model_id' => null])->save();

        $this->messages('/v1/messages', [
            'model' => 'x', 'max_tokens' => 10, 'stream' => false,
            'messages' => [['role' => 'user', 'content' => 'hola']],
        ], ['x-api-key' => $this->token])->assertStatus(422);
    }
}

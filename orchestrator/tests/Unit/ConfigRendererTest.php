<?php

namespace Tests\Unit;

use App\Agents\ConfigRenderer;
use App\Models\Agent;
use App\Models\AgentToken;
use App\Models\McpServer;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plano de configuración de Claude (add-claude-code-runtime 6.2):
 * /config/claude.json (re-leido por el sidecar cada turno) y sus mounts.
 */
class ConfigRendererTest extends TestCase
{
    use RefreshDatabase;

    protected function base(): string
    {
        $dir = sys_get_temp_dir().'/taap-render-'.uniqid();
        mkdir($dir, 0777, true);

        return $dir;
    }

    protected function tearDown(): void
    {
        // limpia la base temporal de los tests que la usaron
        foreach (glob(sys_get_temp_dir().'/taap-render-*') ?: [] as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        }

        parent::tearDown();
    }

    public function test_render_claude_writes_sidecar_config(): void
    {
        $user = User::factory()->create();
        $provider = ModelProvider::factory()->create([
            'type' => 'anthropic',
            'base_url' => 'http://host.docker.internal:9000/v1',
            'created_by' => $user->id,
        ]);
        $model = Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => 'claude-sonnet-4-5',
            'name' => 'Claude Sonnet 4.5',
        ]);
        $agent = Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => 'claude',
            'model_id' => $model->id,
            'system_prompt' => 'Eres un agente de test.',
            'config' => ['max_retries' => 3],
        ]);
        $token = AgentToken::issueFor($agent);
        $token->save();

        $mcp = McpServer::create([
            'name' => 'web',
            'type' => 'http',
            'url' => 'http://mcp.local/sse',
        ]);
        $agent->mcpServers()->attach($mcp->id);

        $base = $this->base();
        $mounts = (new ConfigRenderer($base))->render($agent);

        // mounts: config + data + workspace (los del registro)
        $this->assertCount(3, $mounts);
        $dests = array_map(fn (string $m) => substr($m, (int) strrpos($m, ':') + 1), $mounts);
        sort($dests);
        $this->assertSame(['/config', '/data', '/workspace'], $dests);

        // contenido del plano
        $raw = file_get_contents($base.'/'.$agent->id.'/config/claude.json');
        $this->assertNotFalse($raw);
        $cfg = json_decode($raw, true);

        $this->assertSame('claude-sonnet-4-5', $cfg['model']);
        // El CLI appenda /v1/messages -> la base NO lleva /v1
        $this->assertSame(rtrim((string) config('taap.proxy.internal_url'), '/').'/api/proxy/agents/'.$agent->id, $cfg['anthropic_base_url']);
        $this->assertSame($token->token, $cfg['anthropic_api_key']);
        $this->assertSame('Eres un agente de test.', $cfg['system_prompt']);
        $this->assertSame('/workspace', $cfg['workdir']);
        $this->assertSame('/data/session-state.json', $cfg['state_file']);

        // MCP por la puerta HTTP del orquestador con el token de agente
        $this->assertArrayHasKey('web', $cfg['mcp_servers']);
        $this->assertStringContainsString('?server=web', $cfg['mcp_servers']['web']['url']);
        $this->assertSame('Bearer '.$token->token, $cfg['mcp_servers']['web']['headers']['Authorization']);

        // Sin session_id en el plano: el sidecar lo gestiona (design D6)
        $this->assertArrayNotHasKey('session_id', $cfg);

        // Reintentos del CLI (proveedor caido): presente si el admin lo fija
        $this->assertSame(3, $cfg['max_retries']);

        $agent->forceFill(['config' => []])->save();
        $cfg2 = json_decode((new ConfigRenderer($base))->render($agent->fresh()) ? file_get_contents($base.'/'.$agent->id.'/config/claude.json') : '', true);
        $this->assertArrayNotHasKey('max_retries', $cfg2);
    }

    public function test_render_pi_is_unchanged(): void
    {
        $user = User::factory()->create();
        $provider = ModelProvider::factory()->create(['created_by' => $user->id]);
        $model = Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => 'qwen-1',
            'name' => 'Qwen',
        ]);
        $agent = Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => 'pi',
            'model_id' => $model->id,
        ]);
        $token = AgentToken::issueFor($agent);
        $token->save();

        $base = $this->base();
        (new ConfigRenderer($base))->render($agent);

        $env = file_get_contents($base.'/'.$agent->id.'/config/agent.env');
        $this->assertNotFalse($env);
        $this->assertStringContainsString('SESSION_ID=', $env);
        $this->assertStringContainsString('MODEL=qwen-1', $env);

        $models = json_decode(file_get_contents($base.'/'.$agent->id.'/data/models.json'), true);
        $this->assertArrayHasKey('taap', $models['providers']);
    }

    public function test_render_claude_without_model_omits_it(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => 'claude',
            'model_id' => null,
        ]);
        $token = AgentToken::issueFor($agent);
        $token->save();

        $base = $this->base();
        (new ConfigRenderer($base))->render($agent);

        $cfg = json_decode(file_get_contents($base.'/'.$agent->id.'/config/claude.json'), true);
        $this->assertNull($cfg['model'] ?? null);
    }

    /**
     * Codex (add-codex-runtime 3.2): codex.env (API key + CODEX_HOME) y
     * codex-args.env (los -c, uno por línea) con el provider custom en el
     * Model Proxy y wire `responses`.
     */
    public function test_render_codex_writes_env_and_args_planes(): void
    {
        $user = User::factory()->create();
        $provider = ModelProvider::factory()->create([
            'type' => 'openai',
            'base_url' => 'http://host.docker.internal:9000/v1',
            'created_by' => $user->id,
        ]);
        $model = Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => 'gpt-spike',
            'name' => 'Spike',
        ]);
        $agent = Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => 'codex',
            'model_id' => $model->id,
            'config' => ['sandbox_mode' => 'read-only'],
        ]);
        $token = AgentToken::issueFor($agent);
        $token->save();

        $mcp = McpServer::create(['name' => 'web', 'type' => 'http', 'url' => 'http://mcp.local/sse']);
        $agent->mcpServers()->attach($mcp->id);

        $base = $this->base();
        $mounts = (new ConfigRenderer($base))->render($agent);

        $dests = array_map(fn (string $m) => substr($m, (int) strrpos($m, ':') + 1), $mounts);
        sort($dests);
        $this->assertSame(['/config', '/data', '/workspace'], $dests);

        // codex.env: token de agente + CODEX_HOME en el volumen
        $env = file_get_contents($base.'/'.$agent->id.'/config/codex.env');
        $this->assertNotFalse($env);
        $this->assertStringContainsString('CODEX_HOME=/data', $env);
        $this->assertStringContainsString('TAAP_API_KEY='.$token->token, $env);

        // codex-args.env: uno por línea
        $args = array_values(array_filter(explode('
', (string) file_get_contents($base.'/'.$agent->id.'/config/codex-args.env'))));
        $this->assertContains('-c', $args);
        $this->assertContains('model="gpt-spike"', $args);
        $this->assertContains('model_provider=taap', $args);
        $this->assertContains('model_providers.taap.env_key=TAAP_API_KEY', $args);
        // wire responses: obligatorio desde 0.128 (el "chat" fue eliminado)
        $this->assertContains('model_providers.taap.wire_api=responses', $args);
        // base_url = Model Proxy del agente con /v1 (codex llama a /v1/responses)
        $this->assertContains(
            'model_providers.taap.base_url="'.rtrim((string) config('taap.proxy.internal_url'), '/').'/api/proxy/agents/'.$agent->id.'/v1"',
            $args
        );
        // dials del form (config JSON) con defaults razonables
        $this->assertContains('approval_policy=on-request', $args);
        $this->assertContains('sandbox_mode=read-only', $args, 'el dial del agente gana sobre el default');
        // web_search desactivada por defecto: el binary 0.171 la incluye
        // SIEMPRE en el request /v1/responses y un upstream que no la
        // soporta (orcarouter: 400) cae el turno entero (spike 2.1)
        $this->assertContains('web_search=disabled', $args, 'default del spike 2.1');
        // MCP http por la puerta del orquestador con el token
        $this->assertContains('mcp_servers.web.http_headers.Authorization="Bearer '.$token->token.'"', $args);

        // re-render con web_search reactivado y el dial de sandbox quitado:
        // cada uno independiente (el dial gana; el default vuelve)
        $agent->forceFill(['config' => ['web_search' => 'live']])->save();
        (new ConfigRenderer($base))->render($agent->fresh());
        $args2 = array_values(array_filter(explode("\n", (string) file_get_contents($base.'/'.$agent->id.'/config/codex-args.env'))));
        $this->assertContains('web_search=live', $args2, 'el admin puede reactivarla');
        $this->assertContains('sandbox_mode=workspace-write', $args2);
    }

    /**
     * Codex sin modelo: el provider taap sigue declarado (el app-server
     * arranca; el modelo se elegira luego) y sin línea `model=`.
     */
    public function test_render_codex_without_model_omits_model_line(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => 'codex',
            'model_id' => null,
        ]);
        $token = AgentToken::issueFor($agent);
        $token->save();

        $base = $this->base();
        (new ConfigRenderer($base))->render($agent);

        $args = array_values(array_filter(explode('
', (string) file_get_contents($base.'/'.$agent->id.'/config/codex-args.env'))));
        $this->assertNotContains('model="gpt-spike"', $args);
        $this->assertContains('model_provider=taap', $args);
    }
}

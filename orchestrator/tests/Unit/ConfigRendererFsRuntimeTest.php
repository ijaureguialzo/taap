<?php

namespace Tests\Unit;

use App\Agents\ConfigRenderer;
use App\Enums\FsMountMode;
use App\Models\Agent;
use App\Models\AgentFsMount;
use App\Models\AgentToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FS compartido en la config nativa de cada runtime (shared-filesystem 6.3,
 * design D8): bloque de prompt con paths+modos y reglas de permiso
 * equivalentes a "auto-aprobar dentro de lo montado" por runtime.
 */
class ConfigRendererFsRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function bases(): array
    {
        $root = sys_get_temp_dir().'/taap-fsrt-'.uniqid();
        mkdir($root, 0777, true);
        mkdir($root.'/fs', 0777, true);
        config([
            'taap.agents.data_base' => $root.'/agents',
            'taap.agents.data_host_base' => $root.'/agents',
            'taap.fs.fs_base' => $root.'/fs',
            'taap.fs.fs_host_base' => $root.'/fs',
        ]);

        return [$root.'/agents', $root.'/fs'];
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir().'/taap-fsrt-*') ?: [] as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        }

        parent::tearDown();
    }

    protected function agent(string $runtime, string $prompt = 'Eres un agente de test.'): Agent
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => $runtime,
            'system_prompt' => $prompt,
        ]);
        $token = AgentToken::issueFor($agent);
        $token->save();

        return $agent;
    }

    protected function mount(Agent $agent, string $path, FsMountMode $mode): void
    {
        (new AgentFsMount(['agent_id' => $agent->id, 'path' => $path, 'mode' => $mode]))->save();
    }

    protected function render(Agent $agent, string $agents, string $fs): array
    {
        return (new ConfigRenderer($agents, $fs))->render($agent);
    }

    public function test_pi_prompt_includes_paths_and_modes(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent('pi');
        $this->mount($agent, '/ana/proyectos', FsMountMode::ReadWrite);
        $this->mount($agent, '/ana/informes', FsMountMode::ReadOnly);
        $this->mount($agent, '/ana/oculto', FsMountMode::Disabled);

        $this->render($agent, $agents, $fs);

        $env = file_get_contents($agents.'/'.$agent->id.'/config/agent.env');
        $this->assertStringContainsString('Eres un agente de test.', $env);
        $this->assertStringContainsString('Shared filesystem (mounted under /fs):', $env);
        $this->assertStringContainsString('/fs/ana/informes (read-only)', $env);
        $this->assertStringContainsString('/fs/ana/proyectos (read/write)', $env);
        $this->assertStringNotContainsString('/fs/ana/oculto', $env, 'disabled no figura en el prompt');
    }

    public function test_pi_without_mounts_keeps_prompt_unchanged(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent('pi');

        $this->render($agent, $agents, $fs);

        $env = file_get_contents($agents.'/'.$agent->id.'/config/agent.env');
        $this->assertStringContainsString('SYSTEM_PROMPT=', $env);
        $this->assertStringNotContainsString('Shared filesystem', $env, 'sin carpetas: sin efecto observable');
    }

    public function test_claude_json_prompt_and_permissions(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent('claude');
        $this->mount($agent, '/ana/proyectos', FsMountMode::ReadWrite);
        $this->mount($agent, '/ana/informes', FsMountMode::ReadOnly);
        // permisos del admin (si los fijo) se combinan con los de FS
        $agent->forceFill(['config' => ['permissions' => ['allow' => ['Bash(npm *)']]]])->save();

        $this->render($agent->fresh(), $agents, $fs);

        $cfg = json_decode(file_get_contents($agents.'/'.$agent->id.'/config/claude.json'), true);

        $this->assertStringContainsString('Eres un agente de test.', $cfg['system_prompt']);
        $this->assertStringContainsString('/fs/ana/informes (read-only)', $cfg['system_prompt']);
        $this->assertContains('Bash(npm *)', $cfg['permissions']['allow'], 'los del admin se conservan');
        $this->assertContains('Read(/fs/**)', $cfg['permissions']['allow']);
        $this->assertContains('Edit(/fs/ana/proyectos/**)', $cfg['permissions']['allow']);
        $this->assertNotContains('Edit(/fs/ana/informes/**)', $cfg['permissions']['allow'], 'ro: sin edicion permitida');
    }

    public function test_claude_without_mounts_keeps_sidecar_defaults(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent('claude');

        $this->render($agent, $agents, $fs);

        $cfg = json_decode(file_get_contents($agents.'/'.$agent->id.'/config/claude.json'), true);
        $this->assertArrayNotHasKey('permissions', $cfg, 'sin carpetas: el sidecar usa sus defaults');
        $this->assertStringNotContainsString('Shared filesystem', (string) $cfg['system_prompt']);
    }

    public function test_codex_writable_roots_include_rw_paths(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent('codex');
        $this->mount($agent, '/ana/proyectos', FsMountMode::ReadWrite);
        $this->mount($agent, '/carlos/datos', FsMountMode::ReadWrite);
        $this->mount($agent, '/ana/informes', FsMountMode::ReadOnly);

        $this->render($agent, $agents, $fs);

        $args = file_get_contents($agents.'/'.$agent->id.'/config/codex-args.env');
        $this->assertStringContainsString(
            'sandbox_workspace_write.writable_roots=["/workspace","/fs/ana/proyectos","/fs/carlos/datos"]',
            $args,
        );
    }

    public function test_codex_without_mounts_writes_workspace_only(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent('codex');

        $this->render($agent, $agents, $fs);

        $args = file_get_contents($agents.'/'.$agent->id.'/config/codex-args.env');
        $this->assertStringContainsString(
            'sandbox_workspace_write.writable_roots=["/workspace"]',
            $args,
            'sin carpetas: solo el workspace (paridad con el default)',
        );
    }

    public function test_opencode_permission_globs_and_instructions(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent('opencode');
        $this->mount($agent, '/ana/proyectos', FsMountMode::ReadWrite);
        $this->mount($agent, '/ana/informes', FsMountMode::ReadOnly);

        $this->render($agent, $agents, $fs);

        $cfg = json_decode(file_get_contents($agents.'/'.$agent->id.'/config/opencode/opencode.json'), true);

        $this->assertStringContainsString('Eres un agente de test.', $cfg['instructions']);
        $this->assertStringContainsString('/fs/ana/proyectos (read/write)', $cfg['instructions']);

        $this->assertContains('/fs/ana/proyectos/**', $cfg['permission']['edit']['allow']);
        $this->assertNotContains('/fs/ana/informes/**', $cfg['permission']['edit']['allow'], 'ro: sin edicion');
        $this->assertContains('/fs/ana/informes/**', $cfg['permission']['read']['allow']);
        $this->assertContains('**', $cfg['permission']['read']['allow'], 'fuera de /fs: lectura sigue permitida (default actual)');
        $this->assertContains('**', $cfg['permission']['edit']['ask'], 'fuera de /fs rw: ask (default actual)');
    }

    public function test_opencode_without_mounts_keeps_defaults(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent('opencode');

        $this->render($agent, $agents, $fs);

        $cfg = json_decode(file_get_contents($agents.'/'.$agent->id.'/config/opencode/opencode.json'), true);
        $this->assertSame('Eres un agente de test.', $cfg['instructions'] ?? null, 'sin carpetas: solo el prompt del admin');
        $this->assertSame('ask', $cfg['permission']['edit'], 'sin carpetas: defaults intactos');
        $this->assertSame('allow', $cfg['permission']['read']);
    }

    public function test_hermes_is_kernel_only(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent('hermes');
        $this->mount($agent, '/ana/proyectos', FsMountMode::ReadWrite);

        $this->render($agent, $agents, $fs);

        $yaml = file_get_contents($agents.'/'.$agent->id.'/data/config.yaml');
        $this->assertStringNotContainsString('/fs', $yaml, 'Hermes: solo el kernel (binds) — sin bloque en su config');
    }
}

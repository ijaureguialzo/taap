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
 * FS compartido (shared-filesystem 6.1): render de la arena + binds por
 * path en la forma B' validada en el spike 1.1 (arena SIEMPRE :ro;
 * read_write = rw; read_only = :ro; disabled = sin montaje; destino =
 * arena + path canónico) y el esqueleto precreado (C1/C3).
 */
class ConfigRendererFsMountsTest extends TestCase
{
    use RefreshDatabase;

    protected function bases(): array
    {
        $root = sys_get_temp_dir().'/taap-fs-'.uniqid();
        $agents = $root.'/agents';
        $fs = $root.'/fs';
        mkdir($agents, 0777, true);
        mkdir($fs, 0777, true);
        config([
            'taap.agents.data_base' => $agents,
            'taap.agents.data_host_base' => $agents,
            'taap.fs.fs_base' => $fs,
            'taap.fs.fs_host_base' => $fs,
        ]);

        return [$agents, $fs];
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir().'/taap-fs-*') ?: [] as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        }

        parent::tearDown();
    }

    protected function agent(string $runtime = 'pi'): Agent
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => $runtime,
        ]);
        $token = AgentToken::issueFor($agent);
        $token->save();

        return $agent;
    }

    protected function mount(Agent $agent, string $path, FsMountMode $mode): AgentFsMount
    {
        $mount = new AgentFsMount(['agent_id' => $agent->id, 'path' => $path, 'mode' => $mode]);
        $mount->save();

        return $mount;
    }

    public function test_arena_always_mounted_read_only(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent('claude');

        $mounts = (new ConfigRenderer($agents, $fs))->render($agent);

        $this->assertSame(
            $agents.'/'.$agent->id.'/fs-arena:/fs:ro',
            $mounts[0],
            'la arena va primero y SIEMPRE en solo lectura (spike B\')',
        );
        $this->assertCount(4, $mounts, 'arena + los 3 mounts del runtime');
    }

    public function test_three_modes_render_exact_volume_strings(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent();
        $this->mount($agent, '/ana/proyectos', FsMountMode::ReadWrite);
        $this->mount($agent, '/ana/informes', FsMountMode::ReadOnly);
        $this->mount($agent, '/ana/archivo', FsMountMode::Disabled);

        $mounts = (new ConfigRenderer($agents, $fs))->fsMounts($agent);

        $this->assertSame([
            $agents.'/'.$agent->id.'/fs-arena:/fs:ro',
            // orden alfabético por path: /ana/archivo (disabled, ausente)
            $fs.'/ana/informes:/fs/ana/informes:ro',
            $fs.'/ana/proyectos:/fs/ana/proyectos',
        ], $mounts);
    }

    public function test_external_path_renders_like_any_other(): void
    {
        // Path fuera del subarbol del propietario (anadido por admin):
        // el renderer no conoce la propiedad; el canónico manda.
        [$agents, $fs] = $this->bases();
        $agent = $this->agent();
        $this->mount($agent, '/carlos/datos', FsMountMode::ReadWrite);

        $mounts = (new ConfigRenderer($agents, $fs))->fsMounts($agent);

        $this->assertSame([
            $agents.'/'.$agent->id.'/fs-arena:/fs:ro',
            $fs.'/carlos/datos:/fs/carlos/datos',
        ], $mounts);
    }

    public function test_canonical_normalization_of_stored_paths(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent();
        // sin barra inicial y con barra final (se sanea al renderizar)
        $this->mount($agent, 'ana/proyectos/', FsMountMode::ReadOnly);

        $renderer = new ConfigRenderer($agents, $fs);

        $this->assertSame('/ana/proyectos', $renderer->canonicalPath('ana/proyectos/'));
        $mounts = $renderer->fsMounts($agent);
        $this->assertSame($fs.'/ana/proyectos:/fs/ana/proyectos:ro', $mounts[1]);
    }

    public function test_render_returns_fs_mounts_before_runtime_mounts(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent('codex');
        $this->mount($agent, '/ana/proyectos', FsMountMode::ReadWrite);

        $mounts = (new ConfigRenderer($agents, $fs))->render($agent);

        $this->assertSame([
            $agents.'/'.$agent->id.'/fs-arena:/fs:ro',
            $fs.'/ana/proyectos:/fs/ana/proyectos',
            $agents.'/'.$agent->id.'/config:/config',
            $agents.'/'.$agent->id.'/data:/data',
            $agents.'/'.$agent->id.'/workspace:/workspace',
        ], $mounts);
    }

    public function test_skeleton_precreated_for_arena_and_sources(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent();
        // C3: la carpeta del source NO existe todavia en el arbol fisico
        $this->mount($agent, '/ana/proyectos', FsMountMode::ReadWrite);
        $this->mount($agent, '/carlos/datos', FsMountMode::ReadOnly);

        (new ConfigRenderer($agents, $fs))->render($agent);

        // C1: la arena y el destino COMPLETO (parents + destino) en ella
        $this->assertDirectoryExists($agents.'/'.$agent->id.'/fs-arena');
        $this->assertDirectoryExists($agents.'/'.$agent->id.'/fs-arena/ana/proyectos');
        $this->assertDirectoryExists($agents.'/'.$agent->id.'/fs-arena/carlos/datos');

        // C3: los sources recreados vacios en el arbol fisico
        $this->assertDirectoryExists($fs.'/ana/proyectos');
        $this->assertDirectoryExists($fs.'/carlos/datos');
    }

    public function test_disabled_mount_leaves_no_skeleton(): void
    {
        [$agents, $fs] = $this->bases();
        $agent = $this->agent();
        $this->mount($agent, '/ana/oculto', FsMountMode::Disabled);

        (new ConfigRenderer($agents, $fs))->render($agent);

        // invisible: sin source recreado y sin destino en la arena
        $this->assertDirectoryDoesNotExist($fs.'/ana/oculto');
        $this->assertDirectoryDoesNotExist($agents.'/'.$agent->id.'/fs-arena/ana/oculto');
    }
}

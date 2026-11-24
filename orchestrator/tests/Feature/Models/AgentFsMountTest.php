<?php

namespace Tests\Feature\Models;

use App\Enums\FsMountMode;
use App\Models\Agent;
use App\Models\AgentFsMount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FS compartido (2.2): `agent_fs_mounts` — carpeta canónica + modo, única
 * por agente+path, cascade al borrar el agente.
 */
class AgentFsMountTest extends TestCase
{
    use RefreshDatabase;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->create();
    }

    public function test_mount_is_stored_with_canonical_path_and_mode(): void
    {
        $mount = $this->agent->fsMounts()->create([
            'path' => '/ana/proyectos',
            'mode' => FsMountMode::ReadOnly,
        ]);

        $this->assertSame('/ana/proyectos', $mount->path);
        $this->assertSame(FsMountMode::ReadOnly, $mount->mode);
        $this->assertSame($this->agent->id, $mount->agent->id);
        $this->assertTrue($this->agent->fsMounts()->where('path', '/ana/proyectos')->exists());
    }

    public function test_mode_defaults_to_read_write_and_all_modes_persist(): void
    {
        $default = $this->agent->fsMounts()->create(['path' => '/ana/a']);
        // Sin modo explicito: el default de la columna (read_write) se aplica
        // en la base; el modelo en memoria lo lleva null hasta refrescar.
        $this->assertSame(FsMountMode::ReadWrite, $default->fresh()->mode);

        foreach (FsMountMode::cases() as $i => $mode) {
            $mount = $this->agent->fsMounts()->create(['path' => "/ana/m{$i}", 'mode' => $mode]);
            $this->assertSame($mode, $mount->fresh()->mode);
        }
    }

    public function test_path_is_unique_per_agent(): void
    {
        $this->agent->fsMounts()->create(['path' => '/ana/proyectos', 'mode' => FsMountMode::ReadOnly]);
        $other = Agent::factory()->create();
        // Otro agente: mismo path, sin colision
        $other->fsMounts()->create(['path' => '/ana/proyectos']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->agent->fsMounts()->create(['path' => '/ana/proyectos', 'mode' => FsMountMode::ReadWrite]);
    }

    public function test_exposed_scope_excludes_disabled(): void
    {
        $this->agent->fsMounts()->create(['path' => '/ana/rw', 'mode' => FsMountMode::ReadWrite]);
        $this->agent->fsMounts()->create(['path' => '/ana/ro', 'mode' => FsMountMode::ReadOnly]);
        $this->agent->fsMounts()->create(['path' => '/ana/off', 'mode' => FsMountMode::Disabled]);

        $this->assertSameCanonical(['/ana/ro', '/ana/rw'], AgentFsMount::where('agent_id', $this->agent->id)->exposed()->pluck('path')->all());
    }

    public function test_mounts_cascade_on_agent_hard_delete(): void
    {
        $this->agent->fsMounts()->create(['path' => '/ana/proyectos']);

        // Soft delete: la fila sigue (el agente puede volver)
        $this->agent->delete();
        $this->assertSame(1, AgentFsMount::count());

        $this->agent->forceDelete();
        $this->assertSame(0, AgentFsMount::count());
    }

    /**
     * @param  list<string>  $paths
     */
    protected function assertSameCanonical(array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }
}

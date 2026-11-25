<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentManager;
use App\Agents\ConfigRenderer;
use App\Agents\EgressSync;
use App\Enums\FsMountMode;
use App\Models\Agent;
use App\Models\User;
use App\Support\DockerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * FS compartido (shared-filesystem 6.5): cambiar la lista de carpetas de un
 * agente (añadir, quitar, cambiar modo) re-renderiza la config y aplica el
 * cambio RECREANDO el contenedor si el set de mounts cambio (los bind
 * mounts son inmutables despues del create); sin cambio, un `docker
 * restart` normal. Los destinos que dejan de montarse se eliminan si estan
 * vacios (C2) y se conservan si tienen contenido.
 *
 * Docker mocked: la forma exacta de la recreacion se verifica con las
 * llamadas capturadas; la integracion con contenedor real (y sesion intacta)
 * esta en PiFsRecreateIntegrationTest.
 */
class AgentManagerFsRecreateTest extends TestCase
{
    use RefreshDatabase;

    protected array $bases = [];

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->instance(EgressSync::class, $this->createMock(EgressSync::class));

        $root = sys_get_temp_dir().'/taap-recreate-'.uniqid();
        mkdir($root, 0777, true);
        $this->bases = [
            'agents' => $root.'/agents',
            'fs' => $root.'/fs',
        ];
        mkdir($this->bases['agents'], 0777, true);
        mkdir($this->bases['fs'], 0777, true);
        config([
            'taap.agents.data_base' => $this->bases['agents'],
            'taap.agents.data_host_base' => $this->bases['agents'],
            'taap.fs.fs_base' => $this->bases['fs'],
            'taap.fs.fs_host_base' => $this->bases['fs'],
        ]);

        $owner = User::factory()->create();
        $this->agent = Agent::factory()->create([
            'owner_id' => $owner->id,
            'image' => 'taap/pi:local',
            'container_name' => null,
            'container_id' => null,
            'ip' => null,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir().'/taap-recreate-*') ?: [] as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        }

        parent::tearDown();
    }

    protected function renderer(): ConfigRenderer
    {
        return new ConfigRenderer($this->bases['agents'], $this->bases['fs']);
    }

    /**
     * Captura las llamadas en &$captured (por referencia: los closures del
     * mock mutan la variable del llamador).
     *
     * @return MockObject&DockerClient
     */
    protected function stubDocker(?array &$captured = null, array $containerMounts = []): DockerClient
    {
        $captured = ['specs' => [], 'restart' => 0, 'remove' => 0, 'start' => 0, 'stop' => 0];

        $docker = $this->createMock(DockerClient::class);
        $docker->method('status')->willReturn('running');
        $docker->method('allocateIp')->willReturn('10.0.0.9');
        $docker->method('imageExists')->willReturn(true);
        $docker->method('imagePull');
        $docker->method('createContainer')->willReturnCallback(
            function (array $spec) use (&$captured) {
                $captured['specs'][] = $spec;

                return 'cid-'.count($captured['specs']);
            },
        );
        $docker->method('restart')->willReturnCallback(
            function (string $name) use (&$captured) {
                $captured['restart']++;
            },
        );
        $docker->method('start')->willReturnCallback(
            function (string $name) use (&$captured) {
                $captured['start']++;
            },
        );
        $docker->method('stop')->willReturnCallback(
            function (string $name) use (&$captured) {
                $captured['stop']++;
            },
        );
        $docker->method('remove')->willReturnCallback(
            function (string $name) use (&$captured) {
                $captured['remove']++;
            },
        );
        $docker->method('mounts')->willReturn($containerMounts);
        $docker->method('publishedPort')->willReturn(null);

        return $docker;
    }

    /** @return MockObject&DockerClient */

    /** Traduce las lineas `-v` renderizadas al formato de `DockerClient::mounts()`. */
    protected function parseMounts(array $volumes): array
    {
        return array_map(
            fn (string $v) => $this->parseMount($v),
            $volumes,
        );
    }

    protected function parseMount(string $volume): array
    {
        $parts = explode(':', $volume);

        return [
            'source' => $parts[0] ?? '',
            'dest' => $parts[1] ?? '',
            'rw' => ! (count($parts) > 2 && end($parts) === 'ro'),
        ];
    }

    public function test_restart_without_mount_change_only_restarts(): void
    {
        $this->agent->fsMounts()->create(['path' => '/ana/proyectos', 'mode' => FsMountMode::ReadWrite->value]);
        $agent = $this->agent;

        // provision inicial
        $captured = null;
        $docker = $this->stubDocker($captured);
        $manager = new AgentManager($docker, $this->renderer());
        $manager->provision($agent);
        $this->assertCount(1, $captured['specs']);

        // el contenedor "real" tiene los mounts renderizados
        $containerMounts = $this->parseMounts($captured['specs'][0]['volumes']);

        $captured2 = null;
        $docker2 = $this->stubDocker($captured2, $containerMounts);
        $manager2 = new AgentManager($docker2, $this->renderer());
        $manager2->restart($agent->refresh());

        $this->assertCount(0, $captured2['specs'], 'sin recreacion no hay createContainer');
        $this->assertSame(0, $captured2['stop']);
        $this->assertSame(1, $captured2['restart'], 'docker restart normal');
        $this->assertSame(0, $captured2['remove']);
        $this->assertSame('cid-1', $agent->refresh()->container_id, 'el mismo contenedor sigue');
    }

    public function test_restart_with_added_read_only_mount_recreates_container(): void
    {
        $this->agent->fsMounts()->create(['path' => '/ana/proyectos', 'mode' => FsMountMode::ReadWrite->value]);
        $agent = $this->agent;

        $captured = null;
        $docker = $this->stubDocker($captured);
        $manager = new AgentManager($docker, $this->renderer());
        $manager->provision($agent);
        $cidBefore = $captured['specs'][0]['name'] ?? null;
        $this->assertCount(1, $captured['specs']);

        // el contenedor vivo tiene los mounts viejos (solo el rw)
        $containerMounts = $this->parseMounts($captured['specs'][0]['volumes']);

        // se añade una carpeta SOLO LECTURA a la lista del agente
        $agent->fsMounts()->create(['path' => '/ana/informes', 'mode' => FsMountMode::ReadOnly->value]);

        $captured2 = null;
        $docker2 = $this->stubDocker($captured2, $containerMounts);
        $manager2 = new AgentManager($docker2, $this->renderer());
        $manager2->restart($agent->refresh());

        // recreacion: un createContainer NUEVO (el anterior fue el provision)
        $this->assertCount(1, $captured2['specs'], 'se re-creo el contenedor');
        $this->assertSame(0, $captured2['restart'], 'no un docker restart simple');
        $this->assertSame(1, $captured2['remove']);
        $this->assertSame(1, $captured2['start']);

        $volumes = $captured2['specs'][0]['volumes'];
        $this->assertContains($this->bases['fs'].'/ana/proyectos:/fs/ana/proyectos', $volumes);
        $this->assertContains($this->bases['fs'].'/ana/informes:/fs/ana/informes:ro', $volumes, 'el nuevo mount ro esta en la recreacion');
    }

    public function test_restart_with_changed_mode_recreates_container(): void
    {
        $this->agent->fsMounts()->create(['path' => '/ana/proyectos', 'mode' => FsMountMode::ReadWrite->value]);
        $agent = $this->agent;

        $captured = null;
        $docker = $this->stubDocker($captured);
        $manager = new AgentManager($docker, $this->renderer());
        $manager->provision($agent);
        $containerMounts = $this->parseMounts($captured['specs'][0]['volumes']);

        // rw -> ro sobre la MISMA carpeta: el dest no cambia, el modo si
        $agent->fsMounts()->first()->update(['mode' => FsMountMode::ReadOnly->value]);

        $captured2 = null;
        $docker2 = $this->stubDocker($captured2, $containerMounts);
        $manager2 = new AgentManager($docker2, $this->renderer());
        $manager2->restart($agent->refresh());

        $this->assertCount(1, $captured2['specs'], 'cambio de modo = recreacion');
        $this->assertContains($this->bases['fs'].'/ana/proyectos:/fs/ana/proyectos:ro', $captured2['specs'][0]['volumes']);
    }

    public function test_restart_prunes_unmounted_empty_destinations(): void
    {
        // el esqueleto de una vida anterior dejo estas carpetas en el arbol
        $empty = $this->bases['fs'].'/ana/informes';
        $withFiles = $this->bases['fs'].'/ana/datos';
        mkdir($empty, 0777, true);
        mkdir($withFiles, 0777, true);
        file_put_contents($withFiles.'/quedado.txt', 'x');

        $this->agent->fsMounts()->createMany([
            ['path' => '/ana/proyectos', 'mode' => FsMountMode::ReadWrite->value],
            ['path' => '/ana/informes', 'mode' => FsMountMode::ReadOnly->value],
            ['path' => '/ana/datos', 'mode' => FsMountMode::ReadOnly->value],
        ]);
        $agent = $this->agent;

        $captured = null;
        $docker = $this->stubDocker($captured);
        $manager = new AgentManager($docker, $this->renderer());
        $manager->provision($agent);
        $containerMounts = $this->parseMounts($captured['specs'][0]['volumes']);

        // de la lista del agente se quitan las dos carpetas ro (proyectos sigue)
        $agent->fsMounts()->whereIn('path', ['/ana/informes', '/ana/datos'])->delete();

        $captured2 = null;
        $docker2 = $this->stubDocker($captured2, $containerMounts);
        $manager2 = new AgentManager($docker2, $this->renderer());
        $manager2->restart($agent->refresh());

        $this->assertDirectoryDoesNotExist($empty, 'vacio y desmontado: se elimina (C2)');
        $this->assertDirectoryExists($withFiles, 'con contenido: se conserva en el arbol');
        $this->assertFileExists($withFiles.'/quedado.txt');
    }

    public function test_restart_without_container_falls_back_to_provision(): void
    {
        $docker = $this->createMock(DockerClient::class);
        $docker->method('status')->willReturn(null);
        $docker->method('mounts')->willReturn([]);
        $docker->method('allocateIp')->willReturn('10.0.0.9');
        $docker->method('imageExists')->willReturn(true);
        $docker->method('imagePull');
        $docker->method('publishedPort')->willReturn(null);
        $docker->expects($this->once())->method('createContainer'); // re-provisiona
        $docker->expects($this->never())->method('restart');

        $manager = new AgentManager($docker, $this->renderer());
        $manager->restart($this->agent);

        $this->assertSame('starting', $this->agent->refresh()->status->value);
    }
}

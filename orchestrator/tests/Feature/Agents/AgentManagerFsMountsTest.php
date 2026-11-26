<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentManager;
use App\Agents\ConfigRenderer;
use App\Agents\EgressSync;
use App\Enums\FsMountMode;
use App\Models\Agent;
use App\Models\AgentFsMount;
use App\Models\AgentToken;
use App\Models\User;
use App\Support\DockerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * FS compartido (shared-filesystem 6.2): `provision()` consume los mounts
 * del plano de config (arena SIEMPRE + paths expuestos) y el contenedor
 * resultante tiene la forma B' del spike 1.1. Un agente sin carpetas
 * recibe solo la arena (sin efecto observable).
 */
class AgentManagerFsMountsTest extends TestCase
{
    use RefreshDatabase;

    protected array $bases = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->instance(EgressSync::class, $this->createMock(EgressSync::class));

        $root = sys_get_temp_dir().'/taap-prov-'.uniqid();
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
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir().'/taap-prov-*') ?: [] as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        }

        parent::tearDown();
    }

    protected function manager(DockerClient $docker): AgentManager
    {
        return new AgentManager($docker, new ConfigRenderer(
            $this->bases['agents'],
            $this->bases['fs'],
        ));
    }

    protected function agent(string $image = 'taap/pi:local'): Agent
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->create([
            'owner_id' => $owner->id,
            'image' => $image,
            'container_name' => null,
            'container_id' => null,
            'ip' => null,
        ]);
        $token = AgentToken::issueFor($agent);
        $token->save();

        return $agent;
    }

    protected function stubDocker(?array &$capturedSpec = null): DockerClient
    {
        $docker = $this->createMock(DockerClient::class);
        $docker->method('status')->willReturn(null);
        $docker->method('allocateIp')->willReturn('10.0.0.9');
        $docker->method('imageExists')->willReturn(true);
        $docker->method('createContainer')->willReturnCallback(
            function (array $spec) use (&$capturedSpec) {
                $capturedSpec = $spec;

                return 'cid-fs-test';
            }
        );
        $docker->method('start');

        return $docker;
    }

    public function test_provision_without_folders_gets_arena_only(): void
    {
        $capturedSpec = null;
        $agent = $this->agent();

        $this->manager($this->stubDocker($capturedSpec))->provision($agent);

        $this->assertNotNull($capturedSpec);
        // Solo la arena (sin efecto observable) + los mounts del runtime
        $this->assertSame([
            $this->bases['agents'].'/'.$agent->id.'/fs-arena:/fs:ro',
            $this->bases['agents'].'/'.$agent->id.'/config:/config',
            $this->bases['agents'].'/'.$agent->id.'/data:/home/agent/.pi/agent',
            $this->bases['agents'].'/'.$agent->id.'/workspace:/workspace',
        ], $capturedSpec['volumes']);
        // la arena existe (esqueleto) aunque no tenga mounts
        $this->assertDirectoryExists($this->bases['agents'].'/'.$agent->id.'/fs-arena');
    }

    public function test_provision_with_mounts_renders_spike_b_form(): void
    {
        $capturedSpec = null;
        $agent = $this->agent();
        $this->mount($agent, '/ana/proyectos', FsMountMode::ReadWrite);
        $this->mount($agent, '/ana/informes', FsMountMode::ReadOnly);
        $this->mount($agent, '/ana/oculto', FsMountMode::Disabled);

        $this->manager($this->stubDocker($capturedSpec))->provision($agent);

        // forma B' exacta: arena :ro, paths rw/:ro, disabled ausente
        $this->assertSame([
            $this->bases['agents'].'/'.$agent->id.'/fs-arena:/fs:ro',
            $this->bases['fs'].'/ana/informes:/fs/ana/informes:ro',
            $this->bases['fs'].'/ana/proyectos:/fs/ana/proyectos',
            $this->bases['agents'].'/'.$agent->id.'/config:/config',
            $this->bases['agents'].'/'.$agent->id.'/data:/home/agent/.pi/agent',
            $this->bases['agents'].'/'.$agent->id.'/workspace:/workspace',
        ], $capturedSpec['volumes']);

        // endurecimiento intacto
        $this->assertTrue($capturedSpec['readonly']);
        $this->assertContains('/tmp:size=64m,mode=1777', $capturedSpec['tmpfs']);
        $this->assertContains('/rpc:size=8m,mode=1777', $capturedSpec['tmpfs']);

        // esqueleto C1/C3 en el lado host
        $this->assertDirectoryExists($this->bases['agents'].'/'.$agent->id.'/fs-arena/ana/proyectos');
        $this->assertDirectoryExists($this->bases['agents'].'/'.$agent->id.'/fs-arena/ana/informes');
        $this->assertDirectoryDoesNotExist($this->bases['agents'].'/'.$agent->id.'/fs-arena/ana/oculto');
        $this->assertDirectoryExists($this->bases['fs'].'/ana/proyectos');
        $this->assertDirectoryExists($this->bases['fs'].'/ana/informes');
        $this->assertDirectoryDoesNotExist($this->bases['fs'].'/ana/oculto');
    }

    protected function mount(Agent $agent, string $path, FsMountMode $mode): AgentFsMount
    {
        $mount = new AgentFsMount(['agent_id' => $agent->id, 'path' => $path, 'mode' => $mode]);
        $mount->save();

        return $mount;
    }

    /**
     * Contenedor real (si el entorno tiene Docker y la imagen del spike):
     * forma B' end a end — arena :ro + mounts anidados arrancan, el rw se
     * escribe, el ro no, y el inspect queda transcrito como fixture.
     */
    public function test_real_container_mounts_match_spike(): void
    {
        $dockerBin = trim((string) exec('command -v docker 2>/dev/null'));
        $localImages = array_filter(explode("\n", (string) shell_exec('docker images --format "{{.Repository}}:{{.Tag}}" 2>/dev/null')));
        if ($dockerBin === '' || ! in_array('taap/pi:local', $localImages, true)) {
            $this->markTestSkipped('Docker con la imagen taap/pi:local no disponible en este entorno');
        }

        $root = sys_get_temp_dir().'/taap-real-'.uniqid();
        $arena = $root.'/arena';
        $fs = $root.'/fs';
        mkdir($arena, 0777, true);
        mkdir($fs.'/ana/proyectos', 0777, true);
        mkdir($fs.'/carlos/datos', 0777, true);
        file_put_contents($fs.'/carlos/datos/seed.txt', "seed\n");
        // esqueleto (C1)
        mkdir($arena.'/ana/proyectos', 0777, true);
        mkdir($arena.'/carlos/datos', 0777, true);

        $name = 'taap-fs-spike-'.uniqid();
        $run = fn (string $cmd) => (string) shell_exec('docker exec '.$name.' sh -c '.escapeshellarg($cmd));

        try {
            exec(sprintf(
                'docker create --name %s --read-only --tmpfs /tmp:size=64m,mode=1777 --tmpfs /rpc:size=8m,mode=1777 -v %s:/fs:ro -v %s:/fs/ana/proyectos -v %s:/fs/carlos/datos:ro --entrypoint sh taap/pi:local -c "sleep 86400" 2>&1',
                escapeshellarg($name),
                escapeshellarg($arena),
                escapeshellarg($fs.'/ana/proyectos'),
                escapeshellarg($fs.'/carlos/datos'),
            ));
            $start = (string) exec('docker start '.$name.' 2>&1');
            $this->assertStringNotContainsString('Error', $start, 'el start con arena :ro + esqueleto debe salir limpio (spike H2): '.$start);
            $this->assertSame('true', trim((string) exec('docker inspect -f "{{.State.Running}}" '.$name.' 2>/dev/null')));

            // rw dentro de lo montado
            $out = $run('mkdir -p /fs/ana/proyectos/nuevo && touch /fs/ana/proyectos/nuevo/f.txt && echo OK');
            $this->assertStringNotContainsString('failed', $out, 'el comando dentro del contenedor debe salir bien: '.$out);
            $this->assertStringContainsString('OK', $out);
            // propagacion FUSE (Docker Desktop macOS) con retardo: reintentar
            $ok = false;
            for ($i = 0; $i < 20 && ! $ok; $i++) {
                $ok = is_file($fs.'/ana/proyectos/nuevo/f.txt');
                if (! $ok) {
                    usleep(250000);
                }
            }
            $this->assertTrue($ok, 'lo escrito via el mount rw llega al arbol: '.json_encode([
                'state' => exec('docker inspect -f "{{.State.Status}}" '.$name.' 2>/dev/null'),
                'exec_out' => $out,
                'container_ls' => $run('ls -la /fs/ana/proyectos 2>&1'),
            ]));

            // :ro dentro (EROFS para un usuario no root, como en el contenedor)
            $out = $run('touch /fs/carlos/datos/x.txt 2>&1 || true');
            $this->assertStringContainsString('Read-only file system', $out);

            // arena ro: ni mkdir en su tope
            $out = $run('mkdir /fs/otra 2>&1 || true');
            $this->assertStringContainsString('Read-only file system', $out);

            // los binds tal cual los ve el daemon (Config.Volumes solo lleva
            // los named volumes de la imagen; los -v de runtime van en .Mounts)
            $inspectLines = [];
            exec('docker inspect '.$name.' 2>/dev/null', $inspectLines);
            $inspect = json_decode(implode("\n", $inspectLines), true);
            $this->assertIsArray($inspect);
            $mounts = collect($inspect[0]['Mounts'] ?? [])
                ->filter(fn (array $m) => ($m['Type'] ?? '') === 'bind')
                ->mapWithKeys(fn (array $m) => [$m['Destination'] => $m['Source'].(($m['Mode'] ?? '') === 'ro' ? ':ro' : '')])
                ->all();
            ksort($mounts);

            $this->assertSame([
                '/fs' => $arena.':ro',
                '/fs/ana/proyectos' => $fs.'/ana/proyectos',
                '/fs/carlos/datos' => $fs.'/carlos/datos:ro',
            ], $mounts);
        } finally {
            exec('docker rm -f '.$name.' >/dev/null 2>&1');
            exec('rm -rf '.escapeshellarg($root));
        }
    }
}

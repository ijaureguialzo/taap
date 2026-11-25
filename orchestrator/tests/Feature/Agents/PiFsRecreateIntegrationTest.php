<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentManager;
use App\Agents\RuntimeAdapters;
use App\Enums\AgentStatus;
use App\Enums\FsMountMode;
use App\Models\Agent;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\AgentIntegrationInfra;
use Tests\TestCase;

/**
 * Integracion (shared-filesystem 6.5): añadir una carpeta SOLO-LECTURA a un
 * agente EN MARCHA. Como los bind mounts son inmutables despues del create,
 * el manager recrea el contenedor con el set nuevo de mounts; la sesion NO
 * se pierde (vive en el volumen de datos y se reanuda con --session-id):
 * el gateway ve crecer el historial en la siguiente peticion.
 *
 * Se SALTA sin Docker / sin la imagen / sin taap-orchestrator.
 */
class PiFsRecreateIntegrationTest extends TestCase
{
    use AgentIntegrationInfra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->infra([
            'image' => 'taap/pi:local',
            'gatewayPrefix' => 'taap-int-pi-r',
            'gatewayMjs' => 'gateway-pi.mjs',
            'gatewayPort' => '8904',
        ]);

        config([
            'taap.fs.fs_base' => $this->base.'/fs',
            'taap.fs.fs_host_base' => $this->base.'/fs',
        ]);

        File::ensureDirectoryExists($this->base.'/fs/ana/proyectos');
        File::ensureDirectoryExists($this->base.'/fs/ana/informes');
        File::put($this->base.'/fs/ana/informes/seed.txt', "contenido-semilla\n");
    }

    protected function tearDown(): void
    {
        $this->infraTeardown();
        parent::tearDown();
    }

    public function test_adding_a_folder_recreates_with_ro_mount_and_keeps_session(): void
    {
        $user = $this->user();
        $provider = $this->providerOpenaiGateway($user);
        $model = $this->modelFor($provider, 'qwen-int', 'Qwen Int');

        $agent = Agent::factory()->create([
            'name' => 'pi-rw-int',
            'owner_id' => $user->id,
            'runtime' => 'pi',
            'image' => 'taap/pi:local',
            'model_id' => $model->id,
            'internet_enabled' => false,
            'status' => AgentStatus::Offline,
        ]);
        // Solo la carpeta rw por ahora
        $agent->fsMounts()->create(['path' => '/ana/proyectos', 'mode' => FsMountMode::ReadWrite->value]);
        app(AgentManager::class)->provision($agent);
        $this->agent = $agent;
        $this->copyDbToOrchestrator();
        $agent = $agent->refresh();
        $adapter = app(RuntimeAdapters::class)->for($agent);
        $cidBefore = $agent->container_id;

        // El agente en marcha: un turno completo
        $agent = $this->pump($adapter, $agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 180);
        $this->assertSame('idle', $agent->status->value, 'no llego a idle: '.json_encode($this->eventTypes()));

        $adapter->writeMessage($agent->refresh(), 'fsrw: ejecuta en bash: echo hola > /fs/ana/proyectos/hola.txt');
        $agent = $this->pump($adapter, $agent, fn (Agent $a) => $a->status === AgentStatus::Idle && $this->eventsOfType('run.end') !== [], 120);
        $this->assertNotEmpty($this->eventsOfType('run.end'));

        // Se añade una carpeta SOLO LECTURA a la lista del agente
        $agent->fsMounts()->create(['path' => '/ana/informes', 'mode' => FsMountMode::ReadOnly->value]);

        // ...y se aplica (el path que usa la web: manager->restart)
        app(AgentManager::class)->restart($agent->refresh());
        $agent = $agent->refresh();

        // El reader viejo sigue el contenedor anterior (ya eliminado):
        // detach para que el pump re-adjunte al contenedor re-creado.
        $adapter->detach($agent);

        $cidAfter = $agent->container_id;
        $this->assertNotSame($cidBefore, $cidAfter, 'el contenedor se re-creo para tomar el nuevo mount');

        // El contenedor nuevo tiene el mount :ro y conserva el rw
        $p = new SymfonyProcess(['docker', 'inspect', $agent->container_name, '--format', '{{range .Mounts}}{{.Source}} -> {{.Destination}} rw={{.RW}}{{"\n"}}{{end}}']);
        $p->setTimeout(20);
        $p->run();
        $mounts = (string) $p->getOutput();
        $this->assertStringContainsString('/fs/ana/informes rw=false', $mounts, "mount ro: {$mounts}");
        $this->assertStringContainsString('/fs/ana/proyectos rw=true', $mounts, "mount rw previo: {$mounts}");

        // El contenedor recreado vuelve a idle y reanuda la sesion
        $agent = $this->pump($adapter, $agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 180);
        $this->assertSame('idle', $agent->status->value, 'no recuperado tras la recreacion: '.json_encode($this->eventTypes()));

        $adapter->writeMessage($agent->refresh(), 'fsread: ejecuta en bash: cat /fs/ana/informes/seed.txt');
        $agent = $this->pump($adapter, $agent, fn (Agent $a) => $a->status === AgentStatus::Idle && count($this->eventsOfType('run.end')) >= 2, 120);

        // Evidencia de la sesion intacta: la ULTIMA peticion al gateway lleva
        // el historial completo (turno 1 + turno 2), no un hilo nuevo.
        $requests = $this->gatewayRequests();
        $this->assertNotEmpty($requests);
        $last = $requests[count($requests) - 1];
        $this->assertGreaterThanOrEqual(4, (int) $last['nMessages'], 'la sesion reanudo con historial: '.json_encode($last));
        $this->assertStringContainsString('fsread', (string) $last['ut']);

        // Y el mount ro es real: la lectura funciono y la escritura fallaria
        $toolResults = $this->eventsOfType('tool.result');
        $lastTool = $toolResults[count($toolResults) - 1];
        $this->assertStringContainsString('contenido-semilla', (string) $lastTool['payload']['summary']);
    }
}

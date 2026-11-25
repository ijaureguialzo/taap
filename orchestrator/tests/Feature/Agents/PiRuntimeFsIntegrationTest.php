<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentManager;
use App\Agents\RuntimeAdapters;
use App\Enums\AgentStatus;
use App\Models\Agent;
use Illuminate\Support\Facades\File;
use Tests\Support\AgentIntegrationInfra;
use Tests\TestCase;

/**
 * Integracion extremo a extremo del runtime Pi con el FS compartido
 * (shared-filesystem 6.4): contenedor real taap/pi:local + gateway
 * sintético (modelo determinista que ordena comandos bash) + montados rw/ro.
 *
 * Es la verificacion de la tarea 6.4 "a traves del adapter Pi":
 *   - escritura en path rw  -> SE EJECUTA SIN approval.request (auto) y
 *     el fichero aparece en el arbol compartido;
 *   - escritura en path ro  -> approval.request (modo respetado); al
 *     aprobar, el comando FALLA (EROFS) y el error se reporta en el chat;
 *   - comando fuera de lo montado -> approval.request (flow actual).
 *
 * Se SALTA sin Docker / sin la imagen / sin taap-orchestrator (deploy local).
 */
class PiRuntimeFsIntegrationTest extends TestCase
{
    use AgentIntegrationInfra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->infra([
            'image' => 'taap/pi:local',
            'gatewayPrefix' => 'taap-int-pi',
            'gatewayMjs' => 'gateway-pi.mjs',
            'gatewayPort' => '8903',
        ]);

        // El arbol FS compartido vive dentro del area de datos de este test.
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

    public function test_fs_mounts_drive_pi_approval_flow(): void
    {
        $user = $this->user();
        $provider = $this->providerOpenaiGateway($user);
        $model = $this->modelFor($provider, 'qwen-int', 'Qwen Int');

        $agent = Agent::factory()->create([
            'name' => 'pi-fs-int',
            'owner_id' => $user->id,
            'runtime' => 'pi',
            'image' => 'taap/pi:local',
            'model_id' => $model->id,
            'internet_enabled' => false,
            'status' => AgentStatus::Offline,
        ]);
        // Paths canonicos DESDE la raiz del arbol (/fs) sin el prefijo /fs
        // (la convencion de agent_fs_mounts; el renderer lo antepone).
        $agent->fsMounts()->createMany([
            ['path' => '/ana/proyectos', 'mode' => 'read_write'],
            ['path' => '/ana/informes', 'mode' => 'read_only'],
        ]);
        app(AgentManager::class)->provision($agent);
        $this->agent = $agent;
        $this->copyDbToOrchestrator();
        $agent = $agent->refresh();
        $adapter = app(RuntimeAdapters::class)->for($agent);

        // Arranque: el contenedor llega a idle (pi rpc listo).
        $agent = $this->pump($adapter, $agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 180);
        $this->assertSame('idle', $agent->status->value, 'no llego a idle: '.json_encode($this->eventTypes()));

        /* -- turno 1: escritura en RW -> auto-aprobado, sin dialog -- */
        $adapter->writeMessage($agent->refresh(), 'fsrw: ejecuta en bash: echo hola > /fs/ana/proyectos/hola.txt');
        $agent = $this->pump($adapter, $agent, fn (Agent $a) => $a->status === AgentStatus::Idle && $this->eventsOfType('run.end') !== [], 120);

        $this->assertCount(0, $this->eventsOfType('approval.request'), 'rw no debe pedir aprobacion: '.json_encode($this->eventsOfType('approval.request')));
        // FUSE (fakeowner) en macOS: la propagacion contenedor->host tiene
        // retardo; reintenta la visibilidad (mismo patron que el test 6.2).
        $deadline = microtime(true) + 10;
        while (! file_exists($this->base.'/fs/ana/proyectos/hola.txt') && microtime(true) < $deadline) {
            usleep(250_000);
        }
        $this->assertFileExists($this->base.'/fs/ana/proyectos/hola.txt', 'el auto-aprobado escribio en el arbol');
        $this->assertSame('hola', trim((string) File::get($this->base.'/fs/ana/proyectos/hola.txt')));

        /* -- turno 2: escritura en RO -> dialog; al aprobar, falla (EROFS) -- */
        $adapter->writeMessage($agent->refresh(), 'fsro: ejecuta en bash: echo hola > /fs/ana/informes/hola.txt');
        $agent = $this->pump($adapter, $agent, fn (Agent $a) => $a->status === AgentStatus::WaitingApproval, 120);

        $approvals = $this->eventsOfType('approval.request');
        $this->assertCount(1, $approvals, 'ro SÍ pide aprobacion: '.json_encode($this->eventTypes()));
        $this->assertStringContainsString('/fs/ana/informes/hola.txt', (string) $approvals[0]['payload']['prompt']);

        $adapter->resolveApproval($agent->refresh(), $approvals[0]['payload']['id'], true, 'once');
        $agent = $this->pump($adapter, $agent, fn (Agent $a) => $a->status === AgentStatus::Idle && count($this->eventsOfType('run.end')) >= 2, 120);

        $this->assertFalse(file_exists($this->base.'/fs/ana/informes/hola.txt'), 'ro: no se pudo escribir');
        $toolResults = $this->eventsOfType('tool.result');
        $lastTool = $toolResults ? $toolResults[count($toolResults) - 1] : null;
        $this->assertNotNull($lastTool, 'falta el tool.result del turno ro');
        $this->assertTrue((bool) $lastTool['payload']['ok'] === false || str_contains((string) $lastTool['payload']['summary'], 'read-only'), 'el error EROFS se vio: '.json_encode($lastTool['payload']));
        $final = $this->eventsOfType('message.final');
        $this->assertNotEmpty($final, 'el turno ro termino con mensaje en el chat');

        /* -- turno 3: fuera de lo montado -> dialog (flow actual) -- */
        $adapter->writeMessage($agent->refresh(), 'fsfuera: ejecuta en bash: echo hola > /tmp/fuera.txt');
        $agent = $this->pump($adapter, $agent, fn (Agent $a) => $a->status === AgentStatus::WaitingApproval, 120);

        $approvals = $this->eventsOfType('approval.request');
        $this->assertCount(2, $approvals, 'fuera SÍ pide aprobacion: '.json_encode($this->eventTypes()));
        $this->assertStringContainsString('/tmp/fuera.txt', (string) $approvals[1]['payload']['prompt']);

        $adapter->resolveApproval($agent->refresh(), $approvals[1]['payload']['id'], true, 'once');
        $agent = $this->pump($adapter, $agent, fn (Agent $a) => $a->status === AgentStatus::Idle && count($this->eventsOfType('run.end')) >= 3, 120);

        /* -- turno 4: lectura en RO -> auto (sin dialog nuevo) -- */
        $adapter->writeMessage($agent->refresh(), 'fsread: ejecuta en bash: cat /fs/ana/informes/seed.txt');
        $agent = $this->pump($adapter, $agent, fn (Agent $a) => $a->status === AgentStatus::Idle && count($this->eventsOfType('run.end')) >= 4, 120);

        $this->assertCount(2, $this->eventsOfType('approval.request'), 'lectura en ro no pide aprobacion: '.json_encode($this->eventTypes()));
        $toolResults = $this->eventsOfType('tool.result');
        $lastTool = $toolResults[count($toolResults) - 1];
        $this->assertStringContainsString('contenido-semilla', (string) $lastTool['payload']['summary']);
    }
}

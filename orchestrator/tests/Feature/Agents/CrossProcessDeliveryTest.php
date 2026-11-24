<?php

namespace Tests\Feature\Agents;

use App\Agents\CodexAdapter;
use App\Agents\OpenCodeAdapter;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\ChatMessage;
use App\Support\DockerClient;
use Tests\Support\AgentIntegrationInfra;
use Tests\TestCase;

/**
 * Integracion de la COSTURA cross-proceso (fix-bridge-cross-process-seams
 * 4.1/4.2): dos INSTANCIAS de adapter (memorias $sessions separadas) sobre
 * el MISMO area de ficheros `in`/`out`/`turns` — el modelo fiel de "worker
 * de cola y daemon bridge son procesos distintos". Un mock en memoria no
 * podia capturar el fallo porque los tests anteriores corrian todo en una
 * sola instancia.
 *
 * Runtime real (contenedor + app-server/serve + gateway + proxy):
 *   4.1 Codex: el worker escribe el mensaje (thread persistido o cola
 *       durable); el bridge drena y el cliente ve UN turno coherente
 *       (run.start…run.end + message.final con el mismo id) + historial.
 *   4.2 OpenCode: el worker encola el run_id; el bridge lo adopta y cierra
 *       el turno; un unico lector vivo (el worker no spawneo coprocesos).
 *
 * Se SALTA sin Docker / sin la imagen / sin el deploy (igual que los
 * *RuntimeIntegrationTest).
 */
class CrossProcessDeliveryTest extends TestCase
{
    use AgentIntegrationInfra;

    protected function tearDown(): void
    {
        $this->infraTeardown();

        parent::tearDown();
    }

    /** Drenado final hasta idle (el ultimo pump del test). */
    protected function drainToIdle(\App\Agents\AgentRuntimeAdapter $adapter, Agent $agent, int $timeout = 120): Agent
    {
        return $this->pump($adapter, $agent, fn (Agent $a) => $a->status === AgentStatus::Idle, $timeout);
    }

    /* ── 4.1 Codex ── */

    public function test_codex_worker_message_delivered_by_bridge_with_persisted_thread(): void
    {
        $this->infra([
            'image' => 'taap/agent-codex:local',
            'gatewayPrefix' => 'taap-int-codex-gw',
            'gatewayPort' => '8902',
            'gatewayMjs' => 'gateway-responses.mjs',
        ]);

        $user = $this->user();
        $provider = $this->providerOpenaiGateway($user);
        $model = $this->modelFor($provider, 'gpt-codex-int', 'Codex Int');
        $agent = $this->makeAgent($user, $model, 'codex-xproc', 'codex', 'taap/agent-codex:local');

        // Instancia "daemon bridge" (adjunta y drena) e instancia "worker de
        // cola" (recien construida: $sessions vacio), compartiendo solo
        // ficheros, como los procesos reales.
        $bridge = app(\App\Agents\RuntimeAdapters::class)->for($agent);
        $worker = new CodexAdapter(app(DockerClient::class), $this->sink);

        $agent = $this->drainToIdle($bridge, $agent, 180); // provision -> ready
        $this->assertSame('idle', $agent->refresh()->status->value, 'no llego a idle: '.json_encode($this->eventTypes()));

        // Turno 1 por el bridge (ping -> aprobacion -> allow): establece y
        // persiste el thread.
        $bridge->writeMessage($agent->refresh(), 'Ejecuta en bash: echo ping');
        $agent = $this->pump($bridge, $agent->refresh(), fn (Agent $a) => $this->eventsOfType('approval.request') !== [], 120);
        $this->assertNotEmpty($this->eventsOfType('approval.request'));
        $bridge->resolveApproval($agent->refresh(), $this->lastOfType('approval.request')['payload']['id'], true, 'once');
        $agent = $this->pump($bridge, $agent->refresh(), fn (Agent $a) => $a->status === AgentStatus::Idle && $this->eventsOfType('run.end') !== [], 180);
        $this->assertNotEmpty($this->eventsOfType('run.end'), 'fallo el turno 1: '.json_encode($this->eventTypes()));
        $threadId = $agent->refresh()->config['thread_id'] ?? null;
        $this->assertNotNull($threadId, 'el thread no se persistio');

        // Turno 2 desde el WORKER (sin sesion en memoria): con el fix, usa
        // el thread del config y escribe turn/start en `in` (el writer del
        // bridge lo entrega). Sin el fix: 'Undefined array key N'.
        // (El gateway sintetiza: 'recordatorio' -> texto sin tools.)
        $worker->writeMessage($agent->refresh(), 'Envia el recordatorio de la integracion');

        $agent = $this->pump($bridge, $agent->refresh(), fn (Agent $a) => $a->status === AgentStatus::Idle && count($this->eventsOfType('run.end')) >= 2, 180);

        $runEnds = $this->eventsOfType('run.end');
        $this->assertCount(2, $runEnds, 'un run por turno: '.json_encode($this->eventTypes()));

        // El turno 2 es coherente de principio a fin con UN id.
        $start = $this->lastOfType('run.start');
        $final = $this->lastOfType('message.final');
        $end = $this->lastOfType('run.end');
        $this->assertNotNull($start);
        $this->assertNotNull($final, 'el mensaje del worker no llego al cliente');
        $this->assertSame($start['payload']['run_id'], $final['payload']['run_id']);
        $this->assertSame($start['payload']['run_id'], $end['payload']['run_id']);
        $this->assertSame(true, $end['payload']['ok']);
        $this->assertStringContainsString('recordatorio', (string) $final['payload']['content']);

        // Historial: cada turno dejo su run_id en la meta (un turno con tool
        // emite dos finales — antes y despues del tool — con el mismo run_id).
        $runIds = ChatMessage::where('agent_id', $agent->id)->where('author', 'agent')->get()
            ->map(fn ($m) => $m->meta['run_id'] ?? null)->unique()->values();
        $this->assertCount(2, $runIds, 'un run_id por turno en el historial: '.json_encode($runIds));
    }

    /**
     * El camino de la cola durable: el worker no ve el thread (config
     * borrado; el bridge lo tiene en memoria) -> encola en `turns` -> el
     * tick de drenado del bridge lo emite al hilo vivo.
     */
    public function test_codex_queued_turn_delivered_when_bridge_thread_exists(): void
    {
        $this->infra([
            'image' => 'taap/agent-codex:local',
            'gatewayPrefix' => 'taap-int-codex-gw',
            'gatewayPort' => '8904',
            'gatewayMjs' => 'gateway-responses.mjs',
        ]);

        $user = $this->user();
        $provider = $this->providerOpenaiGateway($user);
        $model = $this->modelFor($provider, 'gpt-codex-int', 'Codex Int');
        $agent = $this->makeAgent($user, $model, 'codex-xq', 'codex', 'taap/agent-codex:local');

        $bridge = app(\App\Agents\RuntimeAdapters::class)->for($agent);
        $worker = new CodexAdapter(app(DockerClient::class), $this->sink);

        $agent = $this->drainToIdle($bridge, $agent, 180);
        $bridge->writeMessage($agent->refresh(), 'Ejecuta en bash: echo ping');
        $agent = $this->pump($bridge, $agent->refresh(), fn (Agent $a) => $this->eventsOfType('approval.request') !== [], 120);
        $this->assertNotEmpty($this->eventsOfType('approval.request'));
        $bridge->resolveApproval($agent->refresh(), $this->lastOfType('approval.request')['payload']['id'], true, 'once');
        $agent = $this->pump($bridge, $agent->refresh(), fn (Agent $a) => $a->status === AgentStatus::Idle && $this->eventsOfType('run.end') !== [], 180);
        $this->assertNotEmpty($this->eventsOfType('run.end'));

        // Divergencia simulada: el worker no ve el thread persistido (p. ej.
        // se creo en otra ventana): encola en `turns` en vez de escribirlo.
        $config = $agent->config;
        $config['thread_id'] = null;
        $agent->config = $config;
        $agent->saveQuietly();
        $agent = $agent->refresh();

        $worker->writeMessage($agent, 'Envia el recordatorio de la integracion');

        $turnsFile = app(DockerClient::class)->rpcDir($agent->id).'/turns';
        $this->assertTrue(is_file($turnsFile), 'el worker encoló el mensaje en la cola durable');

        // El bridge (thread en memoria) lo drena en el tick y lo emite.
        $agent = $this->pump($bridge, $agent->refresh(), fn (Agent $a) => $a->status === AgentStatus::Idle && count($this->eventsOfType('run.end')) >= 2, 180);

        $final = $this->lastOfType('message.final');
        $this->assertNotNull($final, 'el turno encolado no se entrego: '.json_encode($this->eventTypes()));
        $this->assertStringContainsString('recordatorio', (string) $final['payload']['content']);
        $this->assertCount(2, $this->eventsOfType('run.end'));
        $runIds = ChatMessage::where('agent_id', $agent->id)->where('author', 'agent')->get()
            ->map(fn ($m) => $m->meta['run_id'] ?? null)->unique()->values();
        $this->assertCount(2, $runIds, 'un run_id por turno en el historial');
        $this->assertEmpty((array) @file($turnsFile) === false ? [] : array_filter(array_map('trim', @file($turnsFile) ?: [])), 'la cola quedo vacia (exactly-once)');
    }

    /* ── 4.2 OpenCode ── */

    public function test_opencode_worker_message_delivered_by_bridge_single_reader(): void
    {
        $this->infra([
            'image' => 'taap/agent-opencode:local',
            'gatewayPrefix' => 'taap-int-oc-gw',
            'gatewayPort' => '8905',
            'gatewayMjs' => 'gateway-chat.mjs',
            'config' => ['taap.agents.test_api_publish' => true],
        ]);

        $user = $this->user();
        $provider = $this->providerOpenaiGateway($user);
        $model = $this->modelFor($provider, 'gpt-opencode-int', 'OC Int');
        $agent = $this->makeAgent($user, $model, 'oc-xproc', 'opencode', 'taap/agent-opencode:local');

        $bridge = app(\App\Agents\RuntimeAdapters::class)->for($agent);
        $worker = new OpenCodeAdapter(app(DockerClient::class), $this->sink);

        $agent = $this->drainToIdle($bridge, $agent, 180); // -> ready/idle
        $this->assertNotSame('error', $agent->refresh()->status->value, 'no llego a ready: '.json_encode($this->eventTypes()));

        // El lector del bridge esta vivo (pid fichero).
        $readerPid = app(DockerClient::class)->rpcDir($agent->id).'/reader.pid';
        $pidBefore = trim((string) @file_get_contents($readerPid));
        $this->assertNotSame('', $pidBefore, 'el bridge no tiene lector');

        // El WORKER envía (el gateway sintetiza: 'ping' -> texto + tool
        // bash -> permiso -> resultado). Sin el fix: un attach aqui mataba
        // al lector del bridge.
        $worker->writeMessage($agent->refresh(), 'Di ping');

        $this->assertSame($pidBefore, trim((string) @file_get_contents($readerPid)), 'el worker no (re)spawneo el lector: un unico coproceso');

        // El permiso lo resuelve el BRIDGE (el que ve el permission.asked).
        $agent = $this->pump($bridge, $agent->refresh(), fn (Agent $a) => $this->eventsOfType('approval.request') !== [], 120);
        $this->assertNotEmpty($this->eventsOfType('approval.request'), 'falta el permiso: '.json_encode($this->eventTypes()));
        $bridge->resolveApproval($agent->refresh(), $this->lastOfType('approval.request')['payload']['id'], true, 'once');

        $agent = $this->pump($bridge, $agent->refresh(), fn (Agent $a) => $a->status === AgentStatus::Idle && $this->eventsOfType('run.end') !== [], 180);

        $runStarts = $this->eventsOfType('run.start');
        $finals = $this->eventsOfType('message.final');
        $runEnds = $this->eventsOfType('run.end');
        $this->assertCount(1, $runStarts, 'el run.start lo dispatcho el worker (una vez)');
        $this->assertNotEmpty($finals, 'el final se perdio (el bug de produccion): '.json_encode($this->eventTypes()));
        $this->assertCount(1, $runEnds, 'exactly once');

        // UN id de principio a fin: el del worker, adoptado por el bridge.
        $workerRunId = $runStarts[0]['payload']['run_id'];
        $this->assertSame($workerRunId, $finals[0]['payload']['run_id'], 'el bridge adopto el run_id del worker');
        $this->assertSame($workerRunId, $runEnds[0]['payload']['run_id']);
        $this->assertStringContainsString('ping', (string) $finals[0]['payload']['content']);

        // Historial persistido (un run_id por turno en la meta).
        $runIds = ChatMessage::where('agent_id', $agent->id)->where('author', 'agent')->get()
            ->map(fn ($m) => $m->meta['run_id'] ?? null)->unique()->values();
        $this->assertCount(1, $runIds);
    }

    /* ── 4.3 batch: dos prompt_async en batería ──

    Confirmado contra el binario 1.19.6 (captura del stream /event con dos
    prompt_async seguidos): el servidor emite UN solo par busy→idle para el
    batch entero (5 busy + 1 idle + 1 session.idle), NO uno por turno. El
    límite de turno es el `time.completed` de cada mensaje de asistente: el
    cierre POR MENSAJE (finishMessage) es lo que separa los turnos del batch.
    */

    public function test_opencode_batch_two_prompts_close_each_run_fifo(): void
    {
        $this->infra([
            'image' => 'taap/agent-opencode:local',
            'gatewayPrefix' => 'taap-int-oc-gw',
            'gatewayPort' => '8906',
            'gatewayMjs' => 'gateway-chat.mjs',
            'config' => ['taap.agents.test_api_publish' => true],
        ]);

        $user = $this->user();
        $provider = $this->providerOpenaiGateway($user);
        $model = $this->modelFor($provider, 'gpt-opencode-int', 'OC Int');
        $agent = $this->makeAgent($user, $model, 'oc-xbatch', 'opencode', 'taap/agent-opencode:local');

        $bridge = app(\App\Agents\RuntimeAdapters::class)->for($agent);
        $worker = new OpenCodeAdapter(app(DockerClient::class), $this->sink);

        $agent = $this->drainToIdle($bridge, $agent, 180);
        $this->assertNotSame('error', $agent->refresh()->status->value, 'no llego a ready: '.json_encode($this->eventTypes()));

        // Dos mensajes en batería: el gateway retarda 1,5s el primer turno,
        // así el segundo prompt_async llega con la sesión ya ocupada y el
        // servidor lo procesa en el MISMO período busy (comportamiento real
        // verificado contra el binario 1.19.6).
        $worker->writeMessage($agent->refresh(), 'bateria uno');
        $worker->writeMessage($agent->refresh(), 'bateria dos');

        $agent = $this->pump($bridge, $agent->refresh(), fn (Agent $a) => $a->status === AgentStatus::Idle && count($this->eventsOfType('run.end')) >= 2, 240);

        $runStarts = $this->eventsOfType('run.start');
        $runEnds = $this->eventsOfType('run.end');
        $this->assertCount(2, $runStarts, 'el worker dispatchó los dos runs');
        $this->assertCount(2, $runEnds, 'los dos runs cierran: '.json_encode($this->eventTypes()));

        // FIFO: el run que empezó primero cierra primero, con sus ids.
        $ids = [$runStarts[0]['payload']['run_id'], $runStarts[1]['payload']['run_id']];
        $this->assertNotSame($ids[0], $ids[1]);
        $this->assertSame($ids[0], $runEnds[0]['payload']['run_id'], 'FIFO: el primer run cierra primero');
        $this->assertSame($ids[1], $runEnds[1]['payload']['run_id']);

        // Un final POR PROMPT (no un final fusionado del batch), sin mezcla
        // de contenidos, cada uno con su run_id.
        $finals = $this->eventsOfType('message.final');
        $this->assertCount(2, $finals, 'un final por prompt: '.json_encode($this->eventTypes()));
        $this->assertSame($ids[0], $finals[0]['payload']['run_id']);
        $this->assertSame($ids[1], $finals[1]['payload']['run_id']);
        $this->assertStringContainsString('uno', (string) $finals[0]['payload']['content']);
        $this->assertStringNotContainsString('dos', (string) $finals[0]['payload']['content'], 'no se mezcla el contenido de los turnos del batch');
        $this->assertStringContainsString('dos', (string) $finals[1]['payload']['content']);

        // Historial: un mensaje por prompt, cada uno con su run_id.
        $msgs = ChatMessage::where('agent_id', $agent->id)->where('author', 'agent')->orderBy('id')->get();
        $this->assertCount(2, $msgs, 'un mensaje de agente por prompt');
        $this->assertSame($ids[0], $msgs[0]->meta['run_id']);
        $this->assertSame($ids[1], $msgs[1]->meta['run_id']);

        // La cola `turns` quedó vacía (nada que contamine el siguiente turno).
        $lines = (array) (@file(app(DockerClient::class)->rpcDir($agent->id).'/turns') ?: []);
        $this->assertSame([], array_filter(array_map('trim', $lines)), 'la cola durable quedó vacía (exactly-once)');

        // Confirmación del binario en el stream crudo: el batch completo
        // emitió UN único idle (un par por batch, no por turno) — de ahí el
        // cierre por mensaje completado.
        $raw = (string) @file_get_contents(app(DockerClient::class)->rpcDir($agent->id).'/out');
        $this->assertGreaterThan(0, substr_count($raw, '"status":{"type":"busy"}'), 'el batch estuvo busy');
        $this->assertSame(1, substr_count($raw, '"status":{"type":"idle"}'), 'UN idle para el batch entero (comportamiento del binario 1.19.6)');
    }
}

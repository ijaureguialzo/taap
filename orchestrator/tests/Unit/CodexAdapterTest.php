<?php

namespace Tests\Unit;

use App\Agents\AgentEvent;
use App\Agents\CodexAdapter;
use App\Agents\EventSink;
use App\Models\Agent;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use App\Support\DockerClient;
use App\Support\ProcessStreams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CodexAdapter (add-codex-runtime 2.1): el dialecto JSON-RPC del app-server
 * contra shapes REALES del binary 0.171.0 (fixtures del spike:
 * `openspec/changes/add-codex-runtime/notes/spike-codex.md`). Docker
 * simulado; el stdout del app-server se simula escribiendo lineas en
 * $dir/out (el mismo mecanismo que usa el bridge).
 */
class CodexAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected EventSink $sink;

    protected DockerClient $realDocker;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->dir = sys_get_temp_dir().'/taap-codex-'.uniqid();
        mkdir($this->dir.'/rpc', 0777, true);
        $this->sink = new EventSink;
        $this->realDocker = new class ($this->dir.'/rpc') extends DockerClient {
            public function __construct(private string $d) {
                parent::__construct('docker-fake');
            }

            public function rpcDir(int $agentId): string
            {
                return $this->d;
            }
        };
    }

    protected function tearDown(): void
    {
        foreach ((array) @scandir($this->dir.'/rpc') as $f) {
            if ($f !== '.' && $f !== '..') {
                @unlink($this->dir.'/rpc/'.$f);
            }
        }
        @rmdir($this->dir.'/rpc');
        @rmdir($this->dir);

        parent::tearDown();
    }

    protected function makeAdapter(Agent $agent): CodexAdapter
    {
        $docker = $this->createMock(DockerClient::class);
        $docker->method('rpcDir')->willReturn($this->dir.'/rpc');
        $docker->method('status')->willReturn('running');
        $docker->method('coprocessAlive')->willReturn(true);
        $streams = $this->createMock(ProcessStreams::class);
        $docker->method('attachReader')->willReturn($streams);
        $docker->method('startWriter')->willReturn($streams);
        $docker->method('killCoprocess');
        // La cola durable `turns` se delega a un DockerClient REAL (la misma
        // area de ficheros que el mock): los tests de costura usan la logica
        // de ficheros, no un mock en memoria (que es justo lo que rompio).
        $docker->method('turnsAppend')->willReturnCallback(fn ($id, $line) => $this->realDocker->turnsAppend($id, $line));
        $docker->method('turnsNext')->willReturnCallback(fn ($id) => $this->realDocker->turnsNext($id));
        $docker->method('turnsTake')->willReturnCallback(fn ($id) => $this->realDocker->turnsTake($id));

        return new CodexAdapter($docker, $this->sink);
    }

    protected function agent(array $config = [], string $status = 'starting'): Agent
    {
        $user = User::factory()->create();

        return Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => 'codex',
            'status' => $status,
            'container_name' => 'taap-agent-codex-test',
            'container_id' => 'cid-test',
            'config' => $config,
        ]);
    }

    /** Escribe una linea JSON-RPC (u otra cosa) en el `out` simulado. */
    protected function out(array|string $obj): void
    {
        $line = is_string($obj) ? $obj : json_encode($obj);
        file_put_contents($this->dir.'/rpc/out', $line."\n", FILE_APPEND);
    }

    /** @return list<array<string, mixed>> lo escrito en el FIFO `in` (decodificado) */
    protected function in(): array
    {
        return array_map(
            fn ($l) => json_decode((string) $l, true),
            array_values(array_filter(explode("\n", (string) @file_get_contents($this->dir.'/rpc/in'))))
        );
    }

    protected function pump(CodexAdapter $adapter, Agent $agent): array
    {
        $events = $adapter->drain($agent);
        foreach ($events as $event) {
            $this->sink->dispatch($agent->refresh(), $event);
        }

        return $events;
    }

    /** @return list<string> lineas de la cola durable `turns` (crudas) */
    protected function turns(): array
    {
        $raw = (string) @file_get_contents($this->dir.'/rpc/turns');

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
    }

    /**
     * La secuencia de (re)arranque que el adapter encadena tras el
     * initialize de la base: initialized (NOTIFICACION, sin id) +
     * thread/start (o resume si hay id persistido) + sync de modelo.
     */
    public function test_attach_writes_startup_sequence(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $cmds = $this->in();
        $this->assertCount(3, $cmds, 'initialize + initialized + thread/start');
        $this->assertSame('initialize', $cmds[0]['method']);
        $this->assertTrue($cmds[0]['params']['capabilities']['experimentalApi'] ?? false);
        $this->assertSame('initialized', $cmds[1]['method']);
        $this->assertArrayNotHasKey('id', $cmds[1], 'initialized es notificacion: sin id');
        $this->assertSame('thread/start', $cmds[2]['method']);
        $this->assertSame('/workspace', $cmds[2]['params']['cwd']);
    }

    public function test_attach_resumes_persisted_thread_and_syncs_model(): void
    {
        $provider = ModelProvider::factory()->create(['type' => 'openai', 'created_by' => User::factory()]);
        $model = Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => 'gpt-spike',
            'name' => 'Spike',
        ]);

        $agent = $this->agent(['thread_id' => '01a1f7d2-7dc3-7752-94e1-3b46a3329c70']);
        $agent->model_id = $model->id;
        $agent->save();

        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $cmds = $this->in();
        $this->assertCount(4, $cmds, 'initialize + initialized + thread/resume + settings/update');
        $this->assertSame('thread/resume', $cmds[2]['method']);
        $this->assertSame('01a1f7d2-7dc3-7752-94e1-3b46a3329c70', $cmds[2]['params']['threadId']);
        $this->assertSame('thread/settings/update', $cmds[3]['method']);
        $this->assertSame('gpt-spike', $cmds[3]['params']['model']);
    }

    /** Sondeo: el resultado de initialize marca "ready". */
    public function test_initialize_result_marks_ready(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $this->out([
            'jsonrpc' => '2.0',
            'id' => 'taap-ready-'.$agent->id.'-123',
            'result' => ['userAgent' => 'taap-bridge/1.1', 'codexHome' => '/data'],
        ]);

        $events = $this->pump($adapter, $agent);
        $this->assertSame(['status.change'], array_map(fn ($e) => $e->type, $events));
        $this->assertSame('ready', $events[0]->payload['status']);
    }

    /**
     * `initialize` NO es idempotente en el app-server ("Already
     * initialized"): el re-probe del bridge debe marcar "ready" igualmente.
     */
    public function test_already_initialized_error_marks_ready(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $this->out([
            'jsonrpc' => '2.0',
            'id' => 'taap-ready-'.$agent->id.'-999',
            'error' => ['code' => -32600, 'message' => 'Already initialized'],
        ]);

        $events = $this->pump($adapter, $agent);
        $this->assertSame('ready', $events[0]->payload['status']);
    }

    /** El result de thread/start persiste el id del hilo en el config. */
    public function test_thread_result_persists_thread_id(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $this->out([
            'jsonrpc' => '2.0',
            'id' => 'taap-thread-'.$agent->id,
            'result' => [
                'thread' => [
                    'id' => '01a1f7d2-7dc3-7752-94e1-3b46a3329c70',
                    'environments' => [['environmentId' => 'local', 'cwd' => '/workspace']],
                ],
            ],
        ]);

        $events = $this->pump($adapter, $agent);
        $this->assertSame([], $events, 'el result del thread no emite eventos de chat');
        $this->assertSame('01a1f7d2-7dc3-7752-94e1-3b46a3329c70', $agent->refresh()->config['thread_id']);
    }

    /** writeMessage -> turn/start con el input como item de texto. */
    public function test_write_message_sends_turn_start(): void
    {
        $agent = $this->agent(['thread_id' => '01a1f7d2-7dc3-7752-94e1-3b46a3329c70']);
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $adapter->writeMessage($agent, 'Ejecuta en bash: echo ping');

        $cmds = $this->in();
        $cmd = $cmds[count($cmds) - 1];
        $this->assertSame('turn/start', $cmd['method']);
        $this->assertSame('01a1f7d2-7dc3-7752-94e1-3b46a3329c70', $cmd['params']['threadId']);
        $this->assertSame([['type' => 'text', 'text' => 'Ejecuta en bash: echo ping']], $cmd['params']['input']);
    }

    /**
     * Un transcript COMPLETO del spike (turno con aprobacion) produce la
     * secuencia exacta de AgentEvent que la pipeline espera.
     */
    public function test_full_turn_transcript_normalizes_to_expected_events(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $tid = '01a1f7d5-ce21-7d13-a75c-96e04381493a';
        $turnId = '01a1f7d5-ce2c-7b71-8797-21213d619e07';

        $this->out(['jsonrpc' => '2.0', 'id' => 'taap-thread-'.$agent->id, 'result' => ['thread' => ['id' => $tid]]]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'thread/started', 'params' => ['thread' => ['id' => $tid]]]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'turn/started', 'params' => ['threadId' => $tid, 'turn' => ['id' => $turnId, 'status' => 'inProgress']]]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'thread/status/changed', 'params' => ['threadId' => $tid, 'status' => ['type' => 'active', 'activeFlags' => []]]]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'item/agentMessage/delta', 'params' => ['threadId' => $tid, 'turnId' => $turnId, 'itemId' => 'msg_1', 'delta' => 'Voy a ejecutarlo.']]);
        // peticion de aprobacion (REQUEST con id del app-server)
        $this->out([
            'jsonrpc' => '2.0',
            'id' => 0,
            'method' => 'item/commandExecution/requestApproval',
            'params' => [
                'kind' => 'command',
                'threadId' => $tid,
                'turnId' => $turnId,
                'itemId' => 'call_1',
                'command' => "/bin/zsh -lc 'touch /tmp/spike-out.txt'",
                'cwd' => '/workspace',
                'commandActions' => [['type' => 'unknown', 'command' => 'touch /tmp/spike-out.txt']],
                'proposedExecpolicyAmendment' => ['touch', '/tmp/spike-out.txt'],
                'availableDecisions' => ['accept', ['acceptWithExecpolicyAmendment' => ['execpolicy_amendment' => ['touch', '/tmp/spike-out.txt']]], 'cancel'],
            ],
        ]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'thread/status/changed', 'params' => ['threadId' => $tid, 'status' => ['type' => 'active', 'activeFlags' => ['waitingOnApproval']]]]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'item/completed', 'params' => ['threadId' => $tid, 'item' => [
            'type' => 'commandExecution',
            'id' => 'call_1',
            'command' => "/bin/zsh -lc 'touch /tmp/spike-out.txt'",
            'aggregatedOutput' => '',
            'exitCode' => 0,
            'status' => 'completed',
        ]]]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'item/agentMessage/delta', 'params' => ['threadId' => $tid, 'turnId' => $turnId, 'itemId' => 'msg_2', 'delta' => 'Listo.']]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'item/completed', 'params' => ['threadId' => $tid, 'item' => ['type' => 'agentMessage', 'id' => 'msg_2', 'text' => 'Listo.']]]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'thread/tokenUsage/updated', 'params' => ['threadId' => $tid, 'turnId' => $turnId, 'tokenUsage' => ['total' => ['totalTokens' => 110, 'inputTokens' => 100, 'outputTokens' => 10]]]]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'thread/status/changed', 'params' => ['threadId' => $tid, 'status' => ['type' => 'idle', 'activeFlags' => []]]]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'turn/completed', 'params' => ['threadId' => $tid, 'turn' => ['id' => $turnId, 'status' => 'completed', 'error' => null]]]);

        $events = $this->pump($adapter, $agent);

        $types = array_map(fn (AgentEvent $e) => $e->type, $events);
        $this->assertSame(
            [
                'run.start',
                'status.change', // working (turn/started)
                'status.change', // working (status/changed sin flag, corrida en vuelo)
                'message.delta',
                'approval.request',
                'status.change', // working (flag waitingOnApproval)
                'status.change', // waiting-approval
                'tool.result',
                'message.delta',
                'message.final',
                'status.change', // idle (thread/status/changed)
                'run.end',
                'status.change', // idle (turn/completed)
            ],
            $types
        );

        // la aprobacion se registro con su id JSON-RPC y el amendment
        $approval = collect($events)->first(fn ($e) => $e->type === 'approval.request');
        $this->assertSame('codex-0', $approval->payload['id']);
        $this->assertStringContainsString('touch /tmp/spike-out.txt', $approval->payload['prompt']);
        $values = array_column($approval->payload['options'], 'value');
        $this->assertSame(['once', 'always', 'deny'], $values);

        // el sink aplico los estados: tras turn/completed el agente queda idle
        $this->assertSame('idle', $agent->refresh()->status->value);

        // resolver la aprobacion "siempre" -> decision con el amendment
        $adapter->resolveApproval($agent, 'codex-0', true, 'always');
        $cmds = $this->in();
        $cmd = $cmds[count($cmds) - 1];
        $this->assertSame(0, $cmd['id'], 'correlacionado con el id JSON-RPC original');
        $this->assertSame(
            ['acceptWithExecpolicyAmendment' => ['execpolicy_amendment' => ['touch', '/tmp/spike-out.txt']]],
            $cmd['result']['decision']
        );
    }

    /* ── entrega desde el worker sin sesion en memoria (fix-bridge-cross-process-seams) ──

    Un adapter recienv construido = el worker de cola: $sessions vacio.
    El Docker esta simulado, pero la cola `turns` es de ficheros reales,
    igual que en la costura de procesos.
    */

    /**
     * El fallo de produccion: worker sin sesion + hilo persistido en el
     * config. Antes de este fix, `writeTurn` leia `$sessions[$id]` vacio y
     * PHP 8 lanzaba *Undefined array key* ("No se pudo entregar el
     * mensaje: Undefined array key 2" en Codex).
     */
    public function test_write_message_from_worker_uses_persisted_thread(): void
    {
        $agent = $this->agent(['thread_id' => '01a1f7d2-7dc3-7752-94e1-3b46a3329c70'], 'idle');
        $adapter = $this->makeAdapter($agent); // sin attach: worker
        $this->assertFalse($adapter->isAttached($agent));

        $adapter->writeMessage($agent, 'Ejecuta en bash: echo ping');

        $cmds = $this->in();
        $this->assertCount(1, $cmds, 'turn/start directo en `in` (el writer del bridge lo entrega)');
        $this->assertSame('turn/start', $cmds[0]['method']);
        $this->assertSame('01a1f7d2-7dc3-7752-94e1-3b46a3329c70', $cmds[0]['params']['threadId']);
        $this->assertSame([['type' => 'text', 'text' => 'Ejecuta en bash: echo ping']], $cmds[0]['params']['input']);
        $this->assertSame([], $this->turns(), 'con hilo conocido no se usa la cola durable');
    }

    /**
     * Worker sin sesion y sin hilo persistido (primer mensaje, arranque):
     * el contenido va a la cola durable, no se pierde con el proceso.
     */
    public function test_write_message_from_worker_without_thread_queues_durable(): void
    {
        $agent = $this->agent([], 'starting');
        $adapter = $this->makeAdapter($agent);

        $adapter->writeMessage($agent, 'Responde solo con OK');

        $this->assertSame([], $this->in(), 'sin hilo: nada hacia el app-server todavia');
        $lines = $this->turns();
        $this->assertCount(1, $lines);
        $entry = json_decode($lines[0], true);
        $this->assertSame('turn', $entry['kind']);
        $this->assertSame('Responde solo con OK', $entry['content']);
    }

    /** Worker sin area RPC: mismo error de siempre. */
    public function test_write_message_from_worker_without_ipc_area_throws(): void
    {
        $agent = $this->agent([], 'idle');
        $docker = $this->createMock(DockerClient::class);
        $docker->method('rpcDir')->willReturn($this->dir.'/no-existe');
        $adapter = new CodexAdapter($docker, $this->sink);

        $this->expectException(\RuntimeException::class);
        $adapter->writeMessage($agent, 'x');
    }

    /**
     * El bridge emite la cola durable al establecer el hilo (el result del
     * thread/start llega y el pendiente de otro proceso viaja ya).
     */
    public function test_bridge_emits_durable_turns_when_thread_established(): void
    {
        $agent = $this->agent(); // sin thread_id: arranca con thread/start
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        // "Otro proceso" (worker) encola mientras el hilo no existe.
        $worker = $this->makeAdapter($agent);
        $worker->writeMessage($agent, 'Responde solo con OK');
        $this->assertCount(1, $this->turns());

        $this->out([
            'jsonrpc' => '2.0',
            'id' => 'taap-thread-'.$agent->id,
            'result' => ['thread' => ['id' => '01a1f7d5-ce21-7d13-a75c-96e04381493a']],
        ]);

        $this->pump($adapter, $agent);

        $cmds = $this->in();
        $turn = end($cmds);
        $this->assertSame('turn/start', $turn['method']);
        $this->assertSame('01a1f7d5-ce21-7d13-a75c-96e04381493a', $turn['params']['threadId']);
        $this->assertSame([['type' => 'text', 'text' => 'Responde solo con OK']], $turn['params']['input']);
        $this->assertSame([], $this->turns(), 'exactly-once: la cola quedo vacia');
        $this->assertSame('01a1f7d5-ce21-7d13-a75c-96e04381493a', $agent->refresh()->config['thread_id']);

        // Un segundo pump no re-emite el turno.
        $this->pump($adapter, $agent->refresh());
        $this->assertCount(4, $this->in(), 'initialize + initialized + start + turn (una sola vez)');
    }

    /**
     * D3: `thread/resume` fallido ("no rollout found", hilo sin turnos):
     * se descarta el hilo (memoria + config) y la secuencia abre uno nuevo.
     */
    public function test_failed_resume_recovers_with_new_thread(): void
    {
        $agent = $this->agent(['thread_id' => '01a1f7d2-7dc3-7752-94e1-3b46a3329c70']);
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $this->assertCount(3, $this->in(), 'initialize + initialized + resume (sin sync: sin modelo en el test)');

        $this->out([
            'jsonrpc' => '2.0',
            'id' => 'taap-thread-'.$agent->id,
            'error' => ['code' => -32600, 'message' => 'no rollout found for thread id 01a1f7d2-7dc3-7752-94e1-3b46a3329c70'],
        ]);

        $events = $this->pump($adapter, $agent);
        $this->assertContains('error', array_map(fn ($e) => $e->type, $events));

        // Re-secuencia: initialized + thread/start NUEVO (el hilo muerto se descart).
        $cmds = $this->in();
        $this->assertCount(5, $cmds, 'la re-secuencia añade initialized + thread/start');
        $this->assertSame('initialized', $cmds[3]['method']);
        $this->assertSame('thread/start', $cmds[4]['method']);
        $this->assertNull($agent->refresh()->config['thread_id'], 'el hilo muerto ya no esta en el config');

        // El result del nuevo start lo recuerda y lo persiste.
        $this->out(['jsonrpc' => '2.0', 'id' => 'taap-thread-'.$agent->id, 'result' => ['thread' => ['id' => '01a1f7d6-new-thread-id-1234']]]);
        $this->pump($adapter, $agent->refresh());
        $this->assertSame('01a1f7d6-new-thread-id-1234', $agent->refresh()->config['thread_id']);

        // Y el siguiente mensaje (worker) va al hilo vivo.
        $worker = $this->makeAdapter($agent->refresh());
        $worker->writeMessage($agent->refresh(), 'Responde solo con OK');
        $cmds = $this->in();
        $turn = $cmds[count($cmds) - 1];
        $this->assertSame('01a1f7d6-new-thread-id-1234', $turn['params']['threadId']);
    }

    /** Aprobacion: once -> "accept", denegado -> "cancel". */
    public function test_resolve_approval_decision_mapping(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $adapter->resolveApproval($agent, 'codex-7', true, 'once');
        $cmds = $this->in();
        $cmd = $cmds[count($cmds) - 1];
        $this->assertSame(7, $cmd['id']);
        $this->assertSame('accept', $cmd['result']['decision']);

        $adapter->resolveApproval($agent, 'codex-8', false, 'deny');
        $cmds = $this->in();
        $cmd = $cmds[count($cmds) - 1];
        $this->assertSame('cancel', $cmd['result']['decision']);
    }

    /**
     * ACKs de comandos que no son el sondeo ni el ciclo de thread se
     * descartan (no son estado de chat).
     */
    public function test_acks_and_noise_are_discarded(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $this->out(['jsonrpc' => '2.0', 'id' => 'taap-sync-'.$agent->id, 'result' => new \stdClass]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'account/rateLimits/updated', 'params' => ['rateLimits' => new \stdClass]]);
        $this->out(['jsonrpc' => '2.0', 'id' => null, 'method' => 'warning', 'params' => ['message' => 'Model metadata for `gpt-spike` not found.']]);
        $this->out('log de stderr embebido (no JSON)');

        $this->assertSame([], $this->pump($adapter, $agent));
    }

    /**
     * Fixture REAL del spike 2.1 (stream completo de `codex app-server` 0.171
     * con razonamiento, `fixtures/spike-codex-reasoning.jsonl`): los deltas de
     * razonamiento -> `thinking.delta` SEPARADOS del texto, y el razonamiento
     * acumulado viaja como `thinking` en el `message.final` (no fundido).
     * El fixture muestra solo deltas de MUESTREO por parte (los intermedios
     * estan marcados con `_sampled`); el item/completed lleva el texto
     * completo oficial, que es el que debe terminar en el final.
     */
    public function test_spike_fixture_reasoning_maps_to_separated_thinking(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $fixture = base_path('../openspec/changes/archive/2026-11-24-interactive-agent-chat/fixtures/spike-codex-reasoning.jsonl');
        $this->assertFileExists($fixture);

        // Texto OFICIAL de cada razonamiento completado ANTES del agentMessage
        // (el que debe ir en el final): se calcula del fixture, no se hardcodea.
        $expectedThinking = [];
        foreach (array_map('trim', file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $line) {
            $o = json_decode($line, true);
            $it = $o['params']['item'] ?? null;
            if (($o['method'] ?? '') === 'item/completed' && is_array($it)) {
                if (($it['type'] ?? '') === 'reasoning') {
                    $parts = array_filter((array) ($it['summary'] ?? []), fn ($p) => is_string($p) && $p !== '');
                    if ($parts !== []) {
                        $expectedThinking[] = implode('', $parts);
                    }
                } elseif (($it['type'] ?? '') === 'agentMessage') {
                    break; // los razonamientos siguientes no van en ESTE final
                }
            }
        }

        // El stream capturado no incluye turn/started (empieza a mitad de
        // turno); todas las lineas llevan turnId, que los eventos heredan.
        foreach (file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $this->out(trim($line));
        }

        $events = $this->pump($adapter, $agent);
        $types = array_map(fn (AgentEvent $e) => $e->type, $events);

        // 1) Razonamiento en streaming: 25 deltas de muestra, SEPARADOS
        $tdeltas = collect($events)->filter(fn ($e) => $e->type === 'thinking.delta');
        $this->assertCount(25, $tdeltas, 'los summaryTextDelta pasan como thinking.delta');
        // y el texto de razonamiento NO aparece en el flujo de mensaje
        $mdeltas = collect($events)->filter(fn ($e) => $e->type === 'message.delta')
            ->map(fn ($e) => $e->payload['content'])->implode('');
        $this->assertSame("Sandbox hiccup — retrying the listing.\n\n", $mdeltas);
        $this->assertStringNotContainsString("user's requests", $mdeltas, 'el thinking no se funde en el texto');

        // 2) Un solo final: el agentMessage; su `thinking` = los resúmenes
        //    completos de los razonamientos que lo precedieron
        $finals = collect($events)->filter(fn ($e) => $e->type === 'message.final');
        $this->assertCount(1, $finals);
        $final = $finals->first();
        $this->assertSame('Sandbox hiccup — retrying the listing.', $final->payload['content']);
        $this->assertSame(trim(implode("\n", $expectedThinking)), $final->payload['thinking']);
        $this->assertStringContainsString("user's requests", $final->payload['thinking']);
        $this->assertStringNotContainsString('bwrap keeps failing', $final->payload['thinking'],
            'los razonamientos POSTERIORES al mensaje no van en su final');

        // 3) El resto del stream se normaliza como antes (los 4 `ls` del
        //    fixture fallaron con exit 1: el sandbox bwrap no podia crear
        //    namespaces en ese host — era parte del turno del spike)
        $this->assertCount(4, array_keys($types, 'tool.start'));
        $this->assertCount(4, array_keys($types, 'tool.result'));
        $this->assertNotContains('run.start', $types, 'el fixture no trae turn/started');
        $this->assertNotContains('run.end', $types);

        // 4) El sink asento el final con su razonamiento en la meta
        $row = \App\Models\ChatMessage::where('agent_id', $agent->id)->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('Sandbox hiccup — retrying the listing.', $row->content);
        $this->assertSame($final->payload['thinking'], $row->meta['thinking'] ?? null);
    }
}

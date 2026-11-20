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

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->dir = sys_get_temp_dir().'/taap-codex-'.uniqid();
        mkdir($this->dir.'/rpc', 0777, true);
        $this->sink = new EventSink;
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
}

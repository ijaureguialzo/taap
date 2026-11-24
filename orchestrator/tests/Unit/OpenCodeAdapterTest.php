<?php

namespace Tests\Unit;

use App\Agents\AgentEvent;
use App\Agents\EventSink;
use App\Agents\OpenCodeAdapter;
use App\Models\Agent;
use App\Models\AgentEventRow;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use App\Support\DockerClient;
use App\Support\ProcessStreams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * OpenCodeAdapter (add-opencode-runtime 2.1/2.2/2.3): el dialecto de la API
 * `opencode serve` 1.19.6 contra shapes REALES del spike
 * (`openspec/changes/add-opencode-runtime/fixtures/event-flow.jsonl`).
 * Docker simulado + Http::fake; el stream SSE se simula escribiendo lineas
 * `data: <json>` en $dir/out (el mismo mecanismo que el coproceso real).
 */
class OpenCodeAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected EventSink $sink;

    protected DockerClient $realDocker;

    const SID = 'ses_e07b7f8a5ffeIbjTqeDVD1PX5l';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        // NOTA: sin Http::fake() vacio en setUp: en esta version de Laravel
        // envenena los fakes con mapa que ponia fakeServer() despues.
        $this->dir = sys_get_temp_dir().'/taap-oc-'.uniqid();
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

    protected function makeAdapter(Agent $agent): OpenCodeAdapter
    {
        $docker = $this->createMock(DockerClient::class);
        $docker->method('rpcDir')->willReturn($this->dir.'/rpc');
        $docker->method('status')->willReturn('running');
        $docker->method('coprocessAlive')->willReturn(true);
        $docker->method('startedAt')->willReturn('t0');
        $streams = $this->createMock(ProcessStreams::class);
        $docker->method('attachReader')->willReturn($streams);
        $docker->method('killCoprocess');
        // La cola durable `turns` se delega a un DockerClient REAL (la misma
        // area de ficheros que el mock): los tests de costura usan la logica
        // de ficheros, no un mock en memoria (que es justo lo que rompio).
        $docker->method('turnsAppend')->willReturnCallback(fn ($id, $line) => $this->realDocker->turnsAppend($id, $line));
        $docker->method('turnsNext')->willReturnCallback(fn ($id) => $this->realDocker->turnsNext($id));
        $docker->method('turnsTake')->willReturnCallback(fn ($id) => $this->realDocker->turnsTake($id));

        return new OpenCodeAdapter($docker, $this->sink);
    }

    protected function agent(array $config = [], string $status = 'starting'): Agent
    {
        $user = User::factory()->create();
        $provider = ModelProvider::factory()->create([
            'name' => 'prov',
            'type' => 'openai',
            'created_by' => $user->id,
        ]);
        $model = Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => 'gpt-test',
            'name' => 'GPT Test',
        ]);

        return Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => 'opencode',
            'status' => $status,
            'container_name' => 'taap-agent-oc-test',
            'container_id' => 'cid-test',
            'model_id' => $model->id,
            'config' => $config,
        ]);
    }

    /** Escribe un evento del stream (con el prefijo SSE del coproceso real). */
    protected function stream(array $event): void
    {
        file_put_contents($this->dir.'/rpc/out', 'data: '.json_encode($event)."\n", FILE_APPEND);
    }

    protected function pump(OpenCodeAdapter $adapter, Agent $agent): array
    {
        $events = $adapter->drain($agent);
        foreach ($events as $event) {
            $this->sink->dispatch($agent->refresh(), $event);
        }

        return $events;
    }

    protected function types(array $events): array
    {
        return array_map(fn (AgentEvent $e) => $e->type, $events);
    }

    /** El health de la API del contenedor (el adapter llama a este puerto). */
    protected function fakeServer(): void
    {
        $b = 'http://taap-agent-oc-test:4096';
        Http::fake([
            $b.'/api/health' => Http::response(['healthy' => true]),
            $b.'/session' => Http::response(['id' => self::SID, 'title' => 't'], 200),
            $b.'/session/*' => Http::response(['id' => self::SID]),
            $b.'/api/*' => Http::response(null, 204),
            $b.'/permission' => Http::response([]),
            $b.'/*' => Http::response([]),
        ]);
    }

    /* ── 2.1 mecánica del modo api-events ── */

    public function test_attach_probes_health_and_spawns_stream_reader(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();

        $adapter->attach($agent);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/health'));
        $this->assertTrue($adapter->isAttached($agent));
    }

    public function test_stream_server_connected_marks_ready(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();
        $adapter->attach($agent);

        $this->stream(['id' => 'evt_1', 'type' => 'server.connected', 'properties' => []]);

        $events = $this->pump($adapter, $agent);
        $ready = collect($events)->first(fn (AgentEvent $e) => $e->type === 'status.change' && $e->payload['status'] === 'ready');
        $this->assertNotNull($ready, 'falta ready: '.json_encode($this->types($events)));
    }

    /* ── 2.2 ciclo de sesión y comandos HTTP ── */

    public function test_ensure_session_creates_and_persists(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();
        $adapter->attach($agent);

        $adapter->writeMessage($agent, 'hola');

        $agent->refresh();
        $this->assertSame(self::SID, $agent->config['session_id']);

        // El mensaje fue por prompt_async con el override de modelo.
        Http::assertSent(function (Request $r) {
            return str_contains($r->url(), '/prompt_async')
                && str_contains($r->body(), 'hola')
                && str_contains($r->body(), 'gpt-test');
        });
    }

    public function test_ensure_session_reuses_existing_after_restart(): void
    {
        $agent = $this->agent(['session_id' => self::SID]);
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();
        $adapter->attach($agent);

        $adapter->writeMessage($agent, 'hola');

        // GET de verificacion de la sesion existente (no se crea otra).
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/session/'.self::SID) && $r->method() === 'GET');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/session'));
    }

    public function test_model_change_resyncs_session_model(): void
    {
        $agent = $this->agent(['session_id' => self::SID, 'model_key' => 'taap|gpt-old']);
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();
        $adapter->attach($agent);

        $adapter->writeMessage($agent, 'hola');

        Http::assertSent(function (Request $r) {
            return str_contains($r->url(), '/api/session/'.self::SID.'/model')
                && str_contains($r->body(), 'gpt-test');
        });

        $this->assertSame('taap|gpt-test', $agent->refresh()->config['model_key']);
    }

    /** @return list<string> lineas de la cola durable `turns` (crudas) */
    protected function turns(): array
    {
        $raw = (string) @file_get_contents($this->dir.'/rpc/turns');

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
    }

    /* ── 2.3 aprobaciones por API ── */

    public function test_permission_asked_produces_approval_request(): void
    {
        $agent = $this->agent(['session_id' => self::SID]);
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();
        $adapter->attach($agent);
        $adapter->writeMessage($agent, 'Ejecuta en bash: echo ping');

        $this->stream([
            'id' => 'evt_2', 'type' => 'permission.asked', 'properties' => [
                'id' => 'per_1',
                'sessionID' => self::SID,
                'permission' => 'bash',
                'patterns' => ['echo ping'],
                'metadata' => ['command' => 'echo ping'],
                'always' => ['echo *'],
                'tool' => ['messageID' => 'msg_1', 'callID' => 'call_0'],
            ],
        ]);

        $events = $this->pump($adapter, $agent);
        $req = collect($this->types($events))->contains('approval.request')
            ? collect($events)->first(fn (AgentEvent $e) => $e->type === 'approval.request')
            : null;
        $this->assertNotNull($req, 'falta approval.request: '.json_encode($this->types($events)));
        $this->assertSame('per_1', $req->payload['id']);
        $this->assertStringContainsString('echo ping', $req->payload['prompt']);
        $this->assertCount(3, $req->payload['options']);
    }

    public function test_resolve_approval_maps_options_to_native_response(): void
    {
        $agent = $this->agent(['session_id' => self::SID]);
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();
        $adapter->attach($agent);
        $adapter->writeMessage($agent, 'x');

        $this->stream([
            'id' => 'evt_3', 'type' => 'permission.asked', 'properties' => [
                'id' => 'per_2', 'sessionID' => self::SID, 'permission' => 'bash',
                'patterns' => ['ls'], 'metadata' => ['command' => 'ls'], 'always' => ['ls*'],
            ],
        ]);
        $this->pump($adapter, $agent);

        $adapter->resolveApproval($agent, 'per_2', true, 'always');

        Http::assertSent(function (Request $r) {
            return str_contains($r->url(), '/permissions/per_2')
                && str_contains($r->body(), 'always');
        });
    }

    public function test_permission_timeout_rejects_and_resolves(): void
    {
        $agent = $this->agent(['session_id' => self::SID, 'approval_timeout_seconds' => 1]);
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();
        $adapter->attach($agent);
        $adapter->writeMessage($agent, 'x');

        $this->stream([
            'id' => 'evt_4', 'type' => 'permission.asked', 'properties' => [
                'id' => 'per_3', 'sessionID' => self::SID, 'permission' => 'bash',
                'patterns' => ['x'], 'metadata' => ['command' => 'x'], 'always' => ['x'],
            ],
        ]);
        $this->pump($adapter, $agent);
        $this->assertNotEmpty($this->sinkEvents(), 'no llego el ask');

        // > 1s: el permiso caduco al drain siguiente
        usleep(1_100_000);
        $this->pump($adapter, $agent);

        Http::assertSent(fn (Request $r) => str_contains($r->body(), 'reject'));
        $this->assertNotEmpty($this->sinkEventsOf('approval.resolved'));
    }

    /* ── normalización del flujo completo (fixture del spike) ── */

    public function test_full_turn_flow_normalizes_to_pipeline_events(): void
    {
        $agent = $this->agent(['session_id' => self::SID]);
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();
        $adapter->attach($agent);
        $adapter->writeMessage($agent, 'Ejecuta en bash: echo ping');

        // Flujo del fixture: busy, texto, tool, permiso, respuesta, idle.
        $this->stream(['id' => 'evt_5', 'type' => 'session.status', 'properties' => ['sessionID' => self::SID, 'status' => ['type' => 'busy']]]);
        $this->stream(['id' => 'evt_6', 'type' => 'message.updated', 'properties' => ['sessionID' => self::SID, 'info' => ['id' => 'msg_u', 'role' => 'user']]]);
        $this->stream(['id' => 'evt_7', 'type' => 'message.part.updated', 'properties' => ['sessionID' => self::SID, 'part' => ['id' => 'prt_u', 'messageID' => 'msg_u', 'type' => 'text', 'text' => 'Ejecuta en bash: echo ping']]]);
        $this->stream(['id' => 'evt_8', 'type' => 'message.updated', 'properties' => ['sessionID' => self::SID, 'info' => ['id' => 'msg_a', 'role' => 'assistant', 'parentID' => 'msg_u']]]);
        $this->stream(['id' => 'evt_9', 'type' => 'message.part.delta', 'properties' => ['sessionID' => self::SID, 'messageID' => 'msg_a', 'partID' => 'prt_a', 'field' => 'text', 'delta' => 'Ping enviado.']]);
        $this->stream(['id' => 'evt_10', 'type' => 'message.part.updated', 'properties' => ['sessionID' => self::SID, 'part' => ['id' => 'prt_t', 'messageID' => 'msg_a', 'type' => 'tool', 'tool' => 'bash', 'callID' => 'call_0', 'state' => ['status' => 'pending', 'input' => ['command' => 'echo ping']]]]]);
        $drained = collect($this->pump($adapter, $agent))->map(fn (AgentEvent $e) => ['type' => $e->type, 'payload' => $e->payload]);
        $events = collect($this->sinkEvents());
        $this->assertTrue($events->contains(fn ($e) => $e['type'] === 'status.change' && $e['payload']['status'] === 'working'), 'falta working: '.json_encode($this->sinkTypes()));
        // El sink no persiste deltas (spec agent-chat): se verifica en el drain.
        $this->assertTrue($drained->contains(fn (array $e) => $e['type'] === 'message.delta'), 'falta message.delta en el drain: '.json_encode($drained->pluck('type')->all()));
        $this->assertSame('Ping enviado.', $drained->first(fn (array $e) => $e['type'] === 'message.delta')['payload']['content']);
        $tool = $events->first(fn ($e) => $e['type'] === 'tool.start');
        $this->assertSame('bash', $tool['payload']['tool']);

        // permiso + resolucion + idle + final
        $this->stream(['id' => 'evt_11', 'type' => 'permission.asked', 'properties' => ['id' => 'per_9', 'sessionID' => self::SID, 'permission' => 'bash', 'patterns' => ['echo ping'], 'metadata' => ['command' => 'echo ping'], 'always' => ['echo *']]]);
        $this->pump($adapter, $agent);
        $events = collect($this->sinkEvents());
        $this->assertTrue($events->contains(fn ($e) => $e['type'] === 'approval.request'));
        $this->assertTrue($events->contains(fn ($e) => $e['type'] === 'status.change' && $e['payload']['status'] === 'waiting-approval'));

        $this->stream(['id' => 'evt_12', 'type' => 'message.part.delta', 'properties' => ['sessionID' => self::SID, 'messageID' => 'msg_a', 'partID' => 'prt_a', 'field' => 'text', 'delta' => ' Hecho.']]);
        $this->stream(['id' => 'evt_13', 'type' => 'session.idle', 'properties' => ['sessionID' => self::SID]]);
        $this->pump($adapter, $agent);

        $events = collect($this->sinkEvents());
        $final = $events->first(fn ($e) => $e['type'] === 'message.final');
        $this->assertSame('Ping enviado. Hecho.', $final['payload']['content']);
        $this->assertTrue($events->contains(fn ($e) => $e['type'] === 'run.end' && $e['payload']['ok']));
        $this->assertTrue($events->contains(fn ($e) => $e['type'] === 'status.change' && $e['payload']['status'] === 'idle'));
    }

    public function test_session_error_marks_failed_run(): void
    {
        $agent = $this->agent(['session_id' => self::SID]);
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();
        $adapter->attach($agent);
        $adapter->writeMessage($agent, 'hola');

        $this->stream(['id' => 'evt_14', 'type' => 'session.error', 'properties' => ['sessionID' => self::SID, 'error' => ['name' => 'APIError', 'data' => ['message' => '/v1/chat/completions', 'statusCode' => 404]]]]);

        $events = $this->pump($adapter, $agent);
        $this->assertContains('error', $this->types($events));
        $runEnd = collect($events)->first(fn (AgentEvent $e) => $e->type === 'run.end');
        $this->assertFalse((bool) $runEnd->payload['ok']);
    }

    /* ── status ── */

    public function test_status_reflects_busy_and_pending_approval(): void
    {
        $agent = $this->agent(['session_id' => self::SID], 'idle');
        $adapter = $this->makeAdapter($agent);
        $b = 'http://taap-agent-oc-test:4096';
        Http::fake([
            $b.'/api/health' => Http::response(['healthy' => true]),
            $b.'/session' => Http::response(['id' => self::SID], 200),
            $b.'/session/*' => Http::response(['id' => self::SID]),
            $b.'/api/*' => Http::response(null, 204),
            $b.'/session/status' => Http::response([self::SID => ['type' => 'busy']]),
            $b.'/*' => Http::response([]),
        ]);
        $adapter->attach($agent);
        $adapter->writeMessage($agent, 'x');

        $this->assertSame('working', $adapter->status($agent->refresh())->value);

        $this->stream(['id' => 'evt_15', 'type' => 'permission.asked', 'properties' => ['id' => 'per_8', 'sessionID' => self::SID, 'permission' => 'bash', 'patterns' => ['x'], 'metadata' => ['command' => 'x'], 'always' => ['x']]]);
        $this->pump($adapter, $agent);

        $this->assertSame('waiting-approval', $adapter->status($agent->refresh())->value);
    }

    /* ── helpers sobre el sink ── */

    /** @return list<array<string, mixed>> */
    protected function sinkEvents(): array
    {
        return AgentEventRow::query()->get(['type', 'payload', 'occurred_at'])->map(
            fn ($row) => ['type' => $row->type, 'payload' => $row->payload],
        )->all();
    }

    /** @return list<string> */
    protected function sinkTypes(): array
    {
        return array_map(fn (array $e) => $e['type'], $this->sinkEvents());
    }

    protected function sinkEventsOf(string $type): array
    {
        return array_values(array_filter($this->sinkEvents(), fn (array $e) => $e['type'] === $type));
    }
    /* ── 2.5 razonamiento (interactive-agent-chat) ──

    El binary 1.19.6 streamea las partes `reasoning` con field "text"
    (verificado en el binario: reasoning-delta -> updatePartDelta
    field:"text"); el tipo se aprende de `message.part.updated`. Los shapes
    de los eventos son los REALES de la API (openapi.json del spike). */

    protected function turnWithReasoning(): void
    {
        // 1 asistente: razona, luego responde
        $this->stream(['id' => 'evt_1', 'type' => 'message.updated', 'properties' => [
            'sessionID' => self::SID, 'info' => ['id' => 'msg_1', 'role' => 'assistant'],
        ]]);
        // 2 parte reasoning de ARRANQUE (texto vacio): aqui se aprende el tipo
        $this->stream(['id' => 'evt_2', 'type' => 'message.part.updated', 'properties' => [
            'sessionID' => self::SID, 'part' => [
                'id' => 'prt_r1', 'sessionID' => self::SID, 'messageID' => 'msg_1',
                'type' => 'reasoning', 'text' => '', 'time' => ['start' => 1],
            ],
        ]]);
        // 3 deltas de la parte reasoning (field "text", como el texto)
        $this->stream(['id' => 'evt_3', 'type' => 'message.part.delta', 'properties' => [
            'sessionID' => self::SID, 'messageID' => 'msg_1', 'partID' => 'prt_r1', 'field' => 'text', 'delta' => 'Pienso en la peticion. ',
        ]]);
        $this->stream(['id' => 'evt_4', 'type' => 'message.part.delta', 'properties' => [
            'sessionID' => self::SID, 'messageID' => 'msg_1', 'partID' => 'prt_r1', 'field' => 'text', 'delta' => 'Es una suma.',
        ]]);
        // 4 parte reasoning COMPLETA (texto oficial)
        $this->stream(['id' => 'evt_5', 'type' => 'message.part.updated', 'properties' => [
            'sessionID' => self::SID, 'part' => [
                'id' => 'prt_r1', 'sessionID' => self::SID, 'messageID' => 'msg_1',
                'type' => 'reasoning', 'text' => 'Pienso en la peticion. Es una suma.', 'time' => ['start' => 1, 'end' => 2],
            ],
        ]]);
        // 5 parte de texto: arranque + deltas + completa
        $this->stream(['id' => 'evt_6', 'type' => 'message.part.updated', 'properties' => [
            'sessionID' => self::SID, 'part' => [
                'id' => 'prt_t1', 'sessionID' => self::SID, 'messageID' => 'msg_1',
                'type' => 'text', 'text' => '', 'time' => ['start' => 2],
            ],
        ]]);
        $this->stream(['id' => 'evt_7', 'type' => 'message.part.delta', 'properties' => [
            'sessionID' => self::SID, 'messageID' => 'msg_1', 'partID' => 'prt_t1', 'field' => 'text', 'delta' => '2+2=',
        ]]);
        $this->stream(['id' => 'evt_8', 'type' => 'message.part.delta', 'properties' => [
            'sessionID' => self::SID, 'messageID' => 'msg_1', 'partID' => 'prt_t1', 'field' => 'text', 'delta' => ' 4.',
        ]]);
        $this->stream(['id' => 'evt_9', 'type' => 'message.part.updated', 'properties' => [
            'sessionID' => self::SID, 'part' => [
                'id' => 'prt_t1', 'sessionID' => self::SID, 'messageID' => 'msg_1',
                'type' => 'text', 'text' => '2+2= 4.', 'time' => ['start' => 2, 'end' => 3],
            ],
        ]]);
        // 6 fin del turno
        $this->stream(['id' => 'evt_10', 'type' => 'session.status', 'properties' => [
            'sessionID' => self::SID, 'status' => ['type' => 'idle'],
        ]]);
    }

    public function test_reasoning_part_streams_thinking_separate_from_text(): void
    {
        $agent = $this->agent(['session_id' => self::SID]);
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();
        $adapter->attach($agent);
        $adapter->writeMessage($agent, 'suma 2+2');

        $this->turnWithReasoning();
        $events = $this->pump($adapter, $agent);

        // 1) el reasoning fluye como thinking.delta (los deltas NO caen en
        //    message.delta: el tipo se aprendio del part.updated de arranque)
        $thinking = collect($events)->filter(fn ($e) => $e->type === 'thinking.delta')
            ->map(fn ($e) => $e->payload['content'])->implode('');
        $this->assertSame('Pienso en la peticion. Es una suma.', $thinking);

        $content = collect($events)->filter(fn ($e) => $e->type === 'message.delta')
            ->map(fn ($e) => $e->payload['content'])->implode('');
        $this->assertSame('2+2= 4.', $content, 'el razonamiento no se funde en el flujo de texto');

        // 2) el final: contenido y razonamiento SEPARADOS
        $final = collect($events)->first(fn ($e) => $e->type === 'message.final');
        $this->assertNotNull($final);
        $this->assertSame('2+2= 4.', $final->payload['content']);
        $this->assertSame('Pienso en la peticion. Es una suma.', $final->payload['thinking']);
    }

    /**
     * Re-attach a mitad de parte (se perdio el updated de ARRANQUE): los
     * deltas llegan con tipo desconocido y caen en texto; el updated de FIN
     * de parte (reasoning, texto completo) lo mueve al razonamiento.
     */
    public function test_reasoning_part_learned_at_completion_is_moved_from_text(): void
    {
        $agent = $this->agent(['session_id' => self::SID]);
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();
        $adapter->attach($agent);
        $adapter->writeMessage($agent, 'hola');

        $this->stream(['id' => 'evt_1', 'type' => 'message.updated', 'properties' => [
            'sessionID' => self::SID, 'info' => ['id' => 'msg_2', 'role' => 'assistant'],
        ]]);
        // deltas SIN el updated de arranque: tipo desconocido -> texto
        $this->stream(['id' => 'evt_2', 'type' => 'message.part.delta', 'properties' => [
            'sessionID' => self::SID, 'messageID' => 'msg_2', 'partID' => 'prt_r2', 'field' => 'text', 'delta' => 'Mira el ',
        ]]);
        $this->stream(['id' => 'evt_3', 'type' => 'message.part.delta', 'properties' => [
            'sessionID' => self::SID, 'messageID' => 'msg_2', 'partID' => 'prt_r2', 'field' => 'text', 'delta' => 'fichero.',
        ]]);
        // parte COMPLETA: resulta ser reasoning -> se mueve
        $this->stream(['id' => 'evt_4', 'type' => 'message.part.updated', 'properties' => [
            'sessionID' => self::SID, 'part' => [
                'id' => 'prt_r2', 'sessionID' => self::SID, 'messageID' => 'msg_2',
                'type' => 'reasoning', 'text' => 'Mira el fichero.', 'time' => ['start' => 1, 'end' => 2],
            ],
        ]]);
        $this->stream(['id' => 'evt_5', 'type' => 'session.status', 'properties' => [
            'sessionID' => self::SID, 'status' => ['type' => 'idle'],
        ]]);

        $events = $this->pump($adapter, $agent);
        $final = collect($events)->first(fn ($e) => $e->type === 'message.final');
        $this->assertNotNull($final);
        // El turno no tuvo parte de texto: el "texto" (que era razonamiento)
        // queda como contenido y no se duplica en thinking.
        $this->assertSame('Mira el fichero.', $final->payload['content'], 'el "texto" era razonamiento: no debe caer en thinking aparte');
        $this->assertSame('', $final->payload['thinking']);
    }

    /**
     * Turno que SOLO penso (sin parte de texto): el razonamiento se deja
     * como contenido (ultima linea de defensa, como el Model Proxy) para
     * que el turno no desaparezca de la historia.
     */
    public function test_turn_that_only_thought_survives_as_content(): void
    {
        $agent = $this->agent(['session_id' => self::SID]);
        $adapter = $this->makeAdapter($agent);
        $this->fakeServer();
        $adapter->attach($agent);
        $adapter->writeMessage($agent, 'piensa');

        $this->stream(['id' => 'evt_1', 'type' => 'message.updated', 'properties' => [
            'sessionID' => self::SID, 'info' => ['id' => 'msg_3', 'role' => 'assistant'],
        ]]);
        $this->stream(['id' => 'evt_2', 'type' => 'message.part.updated', 'properties' => [
            'sessionID' => self::SID, 'part' => [
                'id' => 'prt_r3', 'sessionID' => self::SID, 'messageID' => 'msg_3',
                'type' => 'reasoning', 'text' => '', 'time' => ['start' => 1],
            ],
        ]]);
        $this->stream(['id' => 'evt_3', 'type' => 'message.part.delta', 'properties' => [
            'sessionID' => self::SID, 'messageID' => 'msg_3', 'partID' => 'prt_r3', 'field' => 'text', 'delta' => 'Solo pense esto.',
        ]]);
        $this->stream(['id' => 'evt_4', 'type' => 'message.part.updated', 'properties' => [
            'sessionID' => self::SID, 'part' => [
                'id' => 'prt_r3', 'sessionID' => self::SID, 'messageID' => 'msg_3',
                'type' => 'reasoning', 'text' => 'Solo pense esto.', 'time' => ['start' => 1, 'end' => 2],
            ],
        ]]);
        $this->stream(['id' => 'evt_5', 'type' => 'session.status', 'properties' => [
            'sessionID' => self::SID, 'status' => ['type' => 'idle'],
        ]]);

        $events = $this->pump($adapter, $agent);
        $final = collect($events)->first(fn ($e) => $e->type === 'message.final');
        $this->assertNotNull($final);
        $this->assertSame('Solo pense esto.', $final->payload['content']);
        $this->assertSame('', $final->payload['thinking'], 'el razonamiento ya esta en el contenido: no se duplica');
    }

    /* ── 3.4 costura cross-proceso (fix-bridge-cross-process-seams) ──

    Dos instancias de adapter comparten el mismo area de ficheros (la cola
    `turns` es real): una simula el worker de cola (writeMessage, sin
    sesion) y otra el daemon bridge (attach + drenado). Es la costura que
    rompio: los procesos comparten ficheros, no memoria.
    */

    public function test_write_message_from_worker_queues_run_id_without_attach(): void
    {
        $agent = $this->agent(['session_id' => self::SID]);
        $adapter = $this->makeAdapter($agent); // worker: sin attach
        $this->fakeServer();
        $this->assertFalse($adapter->isAttached($agent));

        $adapter->writeMessage($agent, 'hola');

        $lines = $this->turns();
        $this->assertCount(1, $lines);
        $entry = json_decode($lines[0], true);
        $this->assertSame('run', $entry['kind']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $entry['run_id']);

        // El prompt fue por HTTP (no depende del lector) y NO hay attach
        // (el worker no spawnea coprocesos: D5).
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/prompt_async') && str_contains($r->body(), 'hola'));
        $this->assertFalse(is_file($this->dir.'/rpc/reader.pid'), 'el worker no spawneo el lector');
    }

    public function test_bridge_adopts_run_id_and_closes_turn(): void
    {
        $agent = $this->agent(['session_id' => self::SID]);
        $worker = $this->makeAdapter($agent); // proceso 1: writeMessage
        $bridge = $this->makeAdapter($agent); // proceso 2: attach + drenado
        $this->fakeServer();
        $bridge->attach($agent);
        $this->assertNull($bridge->sessionRunId($agent->id), 'el bridge no conoce el run (otra memoria)');

        $worker->writeMessage($agent, 'hola');
        $runId = json_decode($this->turns()[0], true)['run_id'];

        // El stream (que el bridge drena): el worker NUNCA lo vio.
        $this->stream(['type' => 'session.status', 'properties' => ['sessionID' => self::SID, 'status' => ['type' => 'busy']]]);
        $this->pump($bridge, $agent);
        $this->assertSame($runId, $bridge->sessionRunId($agent->id), 'el bridge adopto el id en el primer evento del turno');

        $this->stream(['type' => 'message.part.updated', 'properties' => [
            'sessionID' => self::SID, 'messageID' => 'msg_1',
            'part' => ['id' => 'prt_1', 'messageID' => 'msg_1', 'type' => 'text', 'text' => 'Hola'],
        ]]);
        $this->stream(['type' => 'message.part.delta', 'properties' => [
            'sessionID' => self::SID, 'messageID' => 'msg_1', 'partID' => 'prt_1', 'field' => 'text', 'delta' => ' mundo',
        ]]);
        $events = $this->pump($bridge, $agent->refresh());
        $delta = collect($events)->first(fn ($e) => $e->type === 'message.delta');
        $this->assertNotNull($delta);
        $this->assertSame($runId, $delta->payload['run_id'], 'el delta lleva el id del run que el cliente recibio');

        $this->stream(['type' => 'session.status', 'properties' => ['sessionID' => self::SID, 'status' => ['type' => 'idle']]]);
        $events = $this->pump($bridge, $agent->refresh());
        $final = collect($events)->first(fn ($e) => $e->type === 'message.final');
        $this->assertNotNull($final, 'el final se perdio (el bug de produccion)');
        $this->assertSame($runId, $final->payload['run_id'], 'mismo id de principio a fin: un turno coherente');
        $this->assertSame('Hola mundo', $final->payload['content']);
        $runEnds = collect($events)->filter(fn ($e) => $e->type === 'run.end')->values();
        $this->assertCount(1, $runEnds, 'exactly once');
        $this->assertSame($runId, $runEnds[0]->payload['run_id']);
        $this->assertSame([], $this->turns(), 'la cola quedo vacia (no se re-emite)');
    }

    public function test_finish_turn_generates_run_id_when_adopt_fails(): void
    {
        // El busy se perdio (stream truncado) y la cola esta vacia: el idle
        // cierra el turno igual (genera un id, no deja el run colgando).
        $agent = $this->agent(['session_id' => self::SID]);
        $bridge = $this->makeAdapter($agent);
        $this->fakeServer();
        $bridge->attach($agent);

        $this->stream(['type' => 'message.part.delta', 'properties' => [
            'sessionID' => self::SID, 'messageID' => 'msg_9', 'partID' => 'prt_9', 'field' => 'text', 'delta' => 'Respuesta',
        ]]);
        $events = $this->pump($bridge, $agent);
        $delta = collect($events)->first(fn ($e) => $e->type === 'message.delta');
        $this->assertNotNull($delta);
        $this->assertNull($delta->payload['run_id'], 'sin adopcion posible: id null');

        $this->stream(['type' => 'session.status', 'properties' => ['sessionID' => self::SID, 'status' => ['type' => 'idle']]]);
        $events = $this->pump($bridge, $agent->refresh());
        $final = collect($events)->first(fn ($e) => $e->type === 'message.final');
        $runEnd = collect($events)->first(fn ($e) => $e->type === 'run.end');
        $this->assertNotNull($final, 'el turno con contenido nunca se pierde');
        $this->assertNotNull($runEnd);
        $this->assertNotNull($final->payload['run_id'], 'id generado');
        $this->assertSame($final->payload['run_id'], $runEnd->payload['run_id']);
    }

    public function test_idle_without_content_and_without_run_closes_nothing(): void
    {
        // El idle inicial de la sesion (sin turno en vuelo ni en cola) no
        // emite run.* (regresion: el dedupe de idle re-emitidos).
        $agent = $this->agent(['session_id' => self::SID]);
        $bridge = $this->makeAdapter($agent);
        $this->fakeServer();
        $bridge->attach($agent);

        $this->stream(['type' => 'session.status', 'properties' => ['sessionID' => self::SID, 'status' => ['type' => 'idle']]]);
        $events = $this->pump($bridge, $agent);

        $this->assertNotContains('run.end', $this->types($events));
        $this->assertNotContains('message.final', $this->types($events));
        $this->assertContains('status.change', $this->types($events));
    }

    /**
     * Dos turnos en bateria: la cola FIFO entrelaza correctamente (cada run
     * adopta su id en su propio busy; el final del primero NO consume el id
     * del segundo).
     */
    public function test_two_queued_runs_interleave_fifo(): void
    {
        $agent = $this->agent(['session_id' => self::SID]);
        $bridge = $this->makeAdapter($agent);
        $this->fakeServer();
        $bridge->attach($agent);

        // El worker envio dos mensajes mientras el primero iba en vuelo.
        $this->realDocker->turnsAppend($agent->id, json_encode(['kind' => 'run', 'run_id' => 'run-uno']));
        $this->realDocker->turnsAppend($agent->id, json_encode(['kind' => 'run', 'run_id' => 'run-dos']));

        // Turno 1: busy -> adopcion del primero.
        $this->stream(['type' => 'session.status', 'properties' => ['sessionID' => self::SID, 'status' => ['type' => 'busy']]]);
        $this->pump($bridge, $agent);
        $this->assertSame('run-uno', $bridge->sessionRunId($agent->id));
        $this->assertCount(1, $this->turns(), 'el segundo run sigue en cola');

        $this->stream(['type' => 'message.part.delta', 'properties' => ['sessionID' => self::SID, 'messageID' => 'm1', 'partID' => 'p1', 'field' => 'text', 'delta' => 'A1']]);
        $this->stream(['type' => 'session.status', 'properties' => ['sessionID' => self::SID, 'status' => ['type' => 'idle']]]);
        $events = $this->pump($bridge, $agent->refresh());
        $this->assertSame('run-uno', collect($events)->first(fn ($e) => $e->type === 'message.final')->payload['run_id']);
        $this->assertCount(1, $this->turns(), 'el final del turno 1 no consumo el id del turno 2');

        // Turno 2: su busy adopta el segundo id.
        $this->stream(['type' => 'session.status', 'properties' => ['sessionID' => self::SID, 'status' => ['type' => 'busy']]]);
        $this->pump($bridge, $agent->refresh());
        $this->assertSame('run-dos', $bridge->sessionRunId($agent->id));

        $this->stream(['type' => 'message.part.delta', 'properties' => ['sessionID' => self::SID, 'messageID' => 'm2', 'partID' => 'p2', 'field' => 'text', 'delta' => 'A2']]);
        $this->stream(['type' => 'session.status', 'properties' => ['sessionID' => self::SID, 'status' => ['type' => 'idle']]]);
        $events = $this->pump($bridge, $agent->refresh());
        $final = collect($events)->first(fn ($e) => $e->type === 'message.final');
        $this->assertSame('run-dos', $final->payload['run_id']);
        $this->assertSame('A2', $final->payload['content'], 'los buffers no se mezclan entre turnos');
        $this->assertSame([], $this->turns());
    }
}

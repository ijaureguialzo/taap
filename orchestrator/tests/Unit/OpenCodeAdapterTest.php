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
}

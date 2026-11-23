<?php

namespace Tests\Unit;

use App\Agents\AgentEvent;
use App\Agents\ClaudeAdapter;
use App\Agents\ConfigRenderer;
use App\Agents\EventSink;
use App\Models\Agent;
use App\Models\AgentToken;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use App\Support\DockerClient;
use App\Support\ProcessStreams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ClaudeAdapter (add-claude-code-runtime 6.1): paso-through validado del
 * protocolo TAAP v1. Docker simulado; el stdout del sidecar se simula
 * escribiendo lineas en $dir/out (el mismo mecanismo que usa el bridge).
 */
class ClaudeAdapterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{type: string, payload: array, at: string}> */
    protected array $lines = [];

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->dir = sys_get_temp_dir().'/taap-claude-'.uniqid();
        mkdir($this->dir.'/rpc', 0777, true);
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

    protected EventSink $sink;

    protected function makeAdapter(Agent $agent): ClaudeAdapter
    {
        $docker = $this->createMock(DockerClient::class);
        $docker->method('rpcDir')->willReturn($this->dir.'/rpc');
        $docker->method('status')->willReturn('running');
        $docker->method('coprocessAlive')->willReturn(true);
        $streams = $this->createMock(ProcessStreams::class);
        $docker->method('attachReader')->willReturn($streams);
        $docker->method('startWriter')->willReturn($streams);
        $docker->method('killCoprocess');

        $this->sink = new EventSink;

        return new ClaudeAdapter($docker, $this->sink);
    }

    protected function agent(?int $modelId = null): Agent
    {
        $user = User::factory()->create();
        $token = AgentToken::issueFor($a = Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => 'claude',
            'status' => 'starting',
            'container_name' => 'taap-agent-claude-test',
            'container_id' => 'cid-test',
        ]));
        $token->save();

        if ($modelId !== null) {
            $a->model_id = $modelId;
            $a->save();
        }

        return $a;
    }

    protected function providerAndModel(string $type): Model
    {
        $provider = ModelProvider::factory()->create(['type' => $type, 'created_by' => User::factory()]);

        return Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => 'claude-sonnet-4-5',
            'name' => 'Claude Sonnet',
        ]);
    }

    /** Escribe una linea (JSON u otra cosa) en el `out` simulado. */
    protected function out(array|string $obj): void
    {
        $line = is_string($obj) ? $obj : json_encode($obj);
        file_put_contents($this->dir.'/rpc/out', $line."\n", FILE_APPEND);
    }

    /** Emite los eventos por el sink (como hace el bridge). */
    protected function pump(ClaudeAdapter $adapter, Agent $agent, EventSink $sink): array
    {
        $events = $adapter->drain($agent);
        foreach ($events as $event) {
            $sink->dispatch($agent->refresh(), $event);
        }

        return $events;
    }

    public function test_prompt_command_is_written_to_the_fifo(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $adapter->writeMessage($agent, 'hola');

        $in = array_values(array_filter(explode("\n", (string) file_get_contents($this->dir.'/rpc/in'))));
        $cmd = json_decode($in[count($in) - 1], true);
        $this->assertSame(1, $cmd['protocol']);
        $this->assertSame('prompt', $cmd['type']);
        $this->assertSame('hola', $cmd['message']);
    }

    public function test_drain_validates_protocol_version_and_type(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        // version equivocada -> descartada
        $this->out(['protocol' => 2, 'type' => 'message.delta', 'payload' => ['content' => 'x'], 'at' => now()->toIso8601String()]);
        // tipo desconocido -> descartada
        $this->out(['protocol' => 1, 'type' => 'mystery', 'payload' => [], 'at' => now()->toIso8601String()]);
        $this->out('no soy json');

        $this->assertSame([], $adapter->drain($agent));
    }

    public function test_full_turn_streams_normalized_agentevents(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $at = now()->toIso8601String();
        $this->out(['protocol' => 1, 'type' => 'status.change', 'payload' => ['status' => 'starting'], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'run.start', 'payload' => ['run_id' => 'r1'], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'status.change', 'payload' => ['status' => 'working'], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'message.delta', 'payload' => ['run_id' => 'r1', 'content' => 'Voy a'], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'message.delta', 'payload' => ['run_id' => 'r1', 'content' => ' mirar.'], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'tool.start', 'payload' => ['run_id' => 'r1', 'tool' => 'Bash', 'args' => ['command' => 'ls']], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'tool.result', 'payload' => ['run_id' => 'r1', 'tool' => 'Bash', 'ok' => true, 'summary' => 'a b c'], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'message.final', 'payload' => ['run_id' => 'r1', 'content' => 'Hay 3 ficheros.'], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'run.end', 'payload' => ['run_id' => 'r1', 'ok' => true], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'status.change', 'payload' => ['status' => 'idle'], 'at' => $at]);

        $events = $this->pump($adapter, $agent, $this->sink);

        $this->assertSame(
            ['status.change', 'run.start', 'status.change', 'message.delta', 'message.delta', 'tool.start', 'tool.result', 'message.final', 'run.end', 'status.change'],
            array_map(fn (AgentEvent $e) => $e->type, $events),
        );

        // shape exacto de AgentEvent (el cliente web lo consume): el final
        // lleva `thinking` (vacío aquí: el sidecar de este flujo no lo emite).
        $final = $events[7]->toArray();
        $this->assertSame('message.final', $final['type']);
        $this->assertSame(['run_id' => 'r1', 'content' => 'Hay 3 ficheros.', 'thinking' => ''], $final['payload']);
        $this->assertArrayHasKey('at', $final);

        // el sink aplico los efectos de estado: el agente queda idle
        $this->assertSame('idle', $agent->refresh()->status->value);
    }

    public function test_partial_lines_are_buffered_until_newline(): void
    {

        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $line = json_encode(['protocol' => 1, 'type' => 'run.start', 'payload' => ['run_id' => 'r9'], 'at' => now()->toIso8601String()]);
        file_put_contents($this->dir.'/rpc/out', substr($line, 0, 10));

        $this->assertSame([], $adapter->drain($agent), 'a media linea no es evento');

        file_put_contents($this->dir.'/rpc/out', substr($line, 10)."\n", FILE_APPEND);

        $events = $this->pump($adapter, $agent, $this->sink);
        $this->assertCount(1, $events);
        $this->assertSame('r9', $events[0]->payload['run_id']);
    }

    public function test_resolve_approval_writes_protocol_command(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $adapter->resolveApproval($agent, 'ap-1', true, 'always');

        $in = array_values(array_filter(explode("\n", (string) file_get_contents($this->dir.'/rpc/in'))));
        $cmd = json_decode($in[count($in) - 1], true);
        $this->assertSame('approval_response', $cmd['type']);
        $this->assertSame('ap-1', $cmd['approval_id']);
        $this->assertTrue($cmd['approved']);
        $this->assertSame('always', $cmd['option'], '«permitir siempre» viaja como option');
    }

    public function test_apply_config_re_renders_without_restart(): void
    {
        $agent = $this->agent($this->providerAndModel('anthropic')->id);
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $renderer = new ConfigRenderer($this->dir.'/cfg');
        $this->app->instance(ConfigRenderer::class, $renderer);

        $adapter->applyConfig($agent);

        $rendered = json_decode((string) file_get_contents($this->dir.'/cfg/'.$agent->id.'/config/claude.json'), true);
        $this->assertSame('claude-sonnet-4-5', $rendered['model']);
    }
}

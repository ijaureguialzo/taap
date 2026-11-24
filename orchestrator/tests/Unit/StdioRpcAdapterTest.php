<?php

namespace Tests\Unit;

use App\Agents\EventSink;
use App\Agents\PiAdapter;
use App\Models\Agent;
use App\Models\AgentToken;
use App\Models\User;
use App\Support\DockerClient;
use App\Support\ProcessStreams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Correlacion de run en el adaptador RPC (interactive-agent-chat 3.3):
 * el (re)adjuntado del IPC (detach + attach, o el re-attach por lector
 * stale) no debe perder el `run_id` en curso. Si se pierde, los eventos
 * posteriores — incluido `message.final` — se persisten con run_id=null y
 * el mensaje asientado no muestra su seccion de Actividad (y el
 * razonamiento queda descorrelacionado del run).
 */
class StdioRpcAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->dir = sys_get_temp_dir().'/taap-stdio-'.uniqid();
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

    protected function makeAdapter(): PiAdapter
    {
        $docker = $this->createMock(DockerClient::class);
        $docker->method('rpcDir')->willReturn($this->dir.'/rpc');
        $docker->method('status')->willReturn('running');
        $docker->method('coprocessAlive')->willReturn(true);
        $streams = $this->createMock(ProcessStreams::class);
        $docker->method('attachReader')->willReturn($streams);
        $docker->method('startWriter')->willReturn($streams);
        $docker->method('killCoprocess');

        return new PiAdapter($docker, new EventSink);
    }

    protected function agent(): Agent
    {
        $user = User::factory()->create();
        $token = AgentToken::issueFor($a = Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => 'pi',
            'status' => 'working',
            'container_name' => 'taap-agent-pi-test',
            'container_id' => 'cid-test',
        ]));
        $token->save();

        return $a;
    }

    protected function adapterSession(PiAdapter $adapter, int $agentId): array
    {
        $prop = new ReflectionProperty($adapter, 'sessions');

        return $prop->getValue($adapter)[$agentId] ?? [];
    }

    protected function setRunId(PiAdapter $adapter, int $agentId, ?string $runId): void
    {
        $prop = new ReflectionProperty($adapter, 'sessions');
        $sessions = $prop->getValue($adapter);
        $sessions[$agentId]['run_id'] = $runId;
        $prop->setValue($adapter, $sessions);
    }

    public function test_run_id_survives_detach_and_attach(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter();
        $adapter->attach($agent);

        // El run en curso (uno lo abrio agent_start y sigue activo).
        $this->setRunId($adapter, $agent->id, 'run-corr-1');

        // Re-adjuntado: detach + attach (el puente lo hace en el ciclo de
        // arranque y el watchdog de lector stale lo hace en el drain).
        $adapter->detach($agent);
        $this->assertSame([], $this->adapterSession($adapter, $agent->id));
        $adapter->attach($agent);

        $this->assertSame(
            'run-corr-1',
            $this->adapterSession($adapter, $agent->id)['run_id'] ?? null,
        );
    }

    public function test_detach_without_run_keeps_session_clean(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter();
        $adapter->attach($agent);
        $adapter->detach($agent);
        $adapter->attach($agent);

        $this->assertNull($this->adapterSession($adapter, $agent->id)['run_id'] ?? null);
    }
}

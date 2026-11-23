<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentManager;
use App\Agents\ConfigRenderer;
use App\Agents\EgressSync;
use App\Broadcasts\AgentEventBroadcast;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentEventRow;
use App\Models\User;
use App\Support\DockerClient;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\TestCase;

/**
 * AgentManager (interactive-agent-chat 1.3, spec agent-lifecycle):
 * `start()` y `provision()` emiten la transición `status.change(starting)`
 * por el canal (paridad con `stop()`): los clientes ven la evolución
 * starting -> idle sin recargar. Una sola emisión (EventSink::setStatus
 * no la duplica: el estado ya es el que el evento declara).
 */
class AgentManagerStatusTest extends TestCase
{
    use RefreshDatabase;

    /** @var QueueFake */
    protected $queueFake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queueFake = Queue::fake();

        $docker = $this->createMock(DockerClient::class);
        $this->app->instance(DockerClient::class, $docker);
        $this->app->instance(ConfigRenderer::class, $this->createMock(ConfigRenderer::class));
        $this->app->instance(EgressSync::class, $this->createMock(EgressSync::class));
    }

    protected function agent(string $status): Agent
    {
        $owner = User::factory()->create();

        return Agent::factory()->create([
            'owner_id' => $owner->id,
            'status' => $status,
            'container_name' => 'taap-agent-test-1',
            'container_id' => 'cid-existing',
        ]);
    }

    /** @return list<AgentEventBroadcast> */
    protected function broadcasts(): array
    {
        return array_map(
            fn (BroadcastEvent $job) => $job->event,
            $this->queueFake->pushed(BroadcastEvent::class)->all()
        );
    }

    public function test_start_emits_status_change_starting_row_and_broadcast(): void
    {
        $docker = $this->app->make(DockerClient::class);
        $docker->method('status')->willReturn('running'); // contenedor vivo
        $docker->method('start');

        $agent = $this->agent(AgentStatus::Stopped->value);
        $manager = new AgentManager($docker, $this->app->make(ConfigRenderer::class));

        $manager->start($agent);

        $agent->refresh();
        $this->assertSame(AgentStatus::Starting, $agent->status);

        // Fila de cronologia (la spec exige que la transición sea visible
        // también tras un refresco: agent_events es la fuente).
        $rows = AgentEventRow::where('agent_id', $agent->id)
            ->where('type', 'status.change')
            ->get();
        $this->assertCount(1, $rows, 'una sola emisión (sin duplicar la de setStatus)');
        $this->assertSame('starting', $rows[0]->payload['status']);

        // Broadcast al canal público.
        $broadcast = collect($this->broadcasts())
            ->first(fn (AgentEventBroadcast $b) => $b->type === 'status.change');
        $this->assertNotNull($broadcast);
        $this->assertSame('starting', $broadcast->payload['status']);
        $this->assertSame('agent.'.$agent->id, $broadcast->broadcastOn()[0]->name);
    }

    public function test_start_on_agent_already_starting_does_not_double_emit(): void
    {
        $docker = $this->app->make(DockerClient::class);
        $docker->method('status')->willReturn('running');
        $docker->method('start');

        $agent = $this->agent(AgentStatus::Starting->value);
        $manager = new AgentManager($docker, $this->app->make(ConfigRenderer::class));

        $manager->start($agent);

        $count = AgentEventRow::where('agent_id', $agent->id)
            ->where('type', 'status.change')
            ->where('payload->status', 'starting')
            ->count();
        $this->assertSame(1, $count, 'setStatus no re-emitía (estado sin cambio): la única fila es la del manager');
    }

    public function test_provision_emits_status_change_starting(): void
    {
        $docker = $this->app->make(DockerClient::class);
        $docker->method('status')->willReturn(null); // sin contenedor: (re)provisionar
        $docker->method('allocateIp')->willReturn('10.0.0.5');
        $docker->method('imageExists')->willReturn(true);
        $docker->method('createContainer')->willReturn('cid-new');
        $docker->method('start');

        $owner = User::factory()->create();
        $agent = Agent::factory()->create([
            'owner_id' => $owner->id,
            'status' => AgentStatus::Offline->value,
            'container_name' => null,
            'container_id' => null,
            'ip' => null,
        ]);

        $manager = new AgentManager($docker, $this->app->make(ConfigRenderer::class));
        $manager->provision($agent);

        $agent->refresh();
        $this->assertSame(AgentStatus::Starting, $agent->status);
        $this->assertSame('10.0.0.5', $agent->ip);
        $this->assertSame('cid-new', $agent->container_id);

        $rows = AgentEventRow::where('agent_id', $agent->id)
            ->where('type', 'status.change')
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('starting', $rows[0]->payload['status']);

        $this->assertTrue(
            collect($this->broadcasts())->contains(
                fn (AgentEventBroadcast $b) => $b->type === 'status.change' && $b->payload['status'] === 'starting'
            ),
            'provision difunde starting al canal'
        );
    }
}

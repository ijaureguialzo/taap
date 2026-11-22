<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentManager;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Support\DockerClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reconciliación del estado `created` (agent-runtime-reliability, design
 * D3): un contenedor creado sin arrancar (colisión de IP, fallo
 * transitorio) no puede quedar colgado para siempre.
 */
class ReconcileCreatedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DockerClient sin binario: `managedContainers` fijo (la fila que le
     * pasamos) y `start`/`stop` grabados.
     *
     * @param  list<array{id: string, name: string, agent: ?int, instance: string, state: string}>  $rows
     */
    protected function docker(array $rows): DockerClient
    {
        return new class ($rows) extends DockerClient
        {
            public array $started = [];

            public function __construct(
                private array $rows,
            ) {
                parent::__construct('docker-fake');
            }

            public function managedContainers(): array
            {
                return $this->rows;
            }

            public function start(string $name): void
            {
                $this->started[] = $name;
            }

            public function stop(string $name, int $timeout = 10): void
            {
            }

            public function status(string $name): ?string
            {
                foreach ($this->rows as $row) {
                    if ($row['name'] === $name) {
                        return $row['state'];
                    }
                }

                return null;
            }
        };
    }

    protected function agent(array $attrs = []): Agent
    {
        $user = User::factory()->create();

        return Agent::factory()->create(array_merge([
            'owner_id' => $user->id,
            'container_name' => 'taap-agent-test',
            'container_id' => 'cid-1',
            'auto_restart' => true,
        ], $attrs));
    }

    public function test_created_container_with_autorestart_is_started(): void
    {
        $a = $this->agent(['status' => AgentStatus::Starting]);
        $docker = $this->docker([[
            'id' => 'cid-1', 'name' => 'taap-agent-test', 'agent' => $a->id,
            'instance' => config('taap.instance', 'default'), 'state' => 'created',
        ]]);
        $this->app->instance(DockerClient::class, $docker);

        app(AgentManager::class)->reconcile();

        $this->assertSame(['taap-agent-test'], $docker->started);
        $this->assertSame(AgentStatus::Starting, $a->fresh()->status);
    }

    public function test_created_container_without_autorestart_goes_offline(): void
    {
        $a = $this->agent(['status' => AgentStatus::Starting, 'auto_restart' => false]);
        $docker = $this->docker([[
            'id' => 'cid-1', 'name' => 'taap-agent-test', 'agent' => $a->id,
            'instance' => config('taap.instance', 'default'), 'state' => 'created',
        ]]);
        $this->app->instance(DockerClient::class, $docker);

        app(AgentManager::class)->reconcile();

        $this->assertSame([], $docker->started);
        $this->assertSame(AgentStatus::Offline, $a->fresh()->status);
    }

    public function test_stopped_agent_created_container_is_left_alone(): void
    {
        $a = $this->agent(['status' => AgentStatus::Stopped, 'auto_restart' => true]);
        $docker = $this->docker([[
            'id' => 'cid-1', 'name' => 'taap-agent-test', 'agent' => $a->id,
            'instance' => config('taap.instance', 'default'), 'state' => 'created',
        ]]);
        $this->app->instance(DockerClient::class, $docker);

        app(AgentManager::class)->reconcile();

        $this->assertSame([], $docker->started);
        $this->assertSame(AgentStatus::Stopped, $a->fresh()->status);
    }
}

<?php

namespace Tests\Feature\Console;

use App\Agents\AgentManager;
use App\Enums\AgentRuntime;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Rollout (shared-filesystem 8.1): `taap:agents:reprovision` aplica la nueva
 * forma de mounts a los agentes (todos, o `--only`), saltandose los
 * eliminados; `--dry-run` solo informa; un fallo no para al resto.
 */
class AgentsReprovisionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Agent $a1;

    protected Agent $a2;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $owner = User::factory()->create();
        $this->a1 = Agent::factory()->create([
            'owner_id' => $owner->id, 'name' => 'uno',
            'runtime' => AgentRuntime::Pi, 'container_name' => 'taap-agent-uno',
        ]);
        $this->a2 = Agent::factory()->create([
            'owner_id' => $owner->id, 'name' => 'dos',
            'runtime' => AgentRuntime::Claude, 'container_name' => null,
        ]);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function mockManager(): \PHPUnit\Framework\MockObject\MockObject
    {
        $mock = $this->createMock(AgentManager::class);
        $mock->method('restart')->willReturnCallback(fn ($a) => $a);
        $mock->method('needsMountRecreate')->willReturn(true);

        return $mock;
    }

    public function test_reprovisions_all_agents(): void
    {
        $manager = $this->mockManager();
        $manager->expects($this->exactly(2))->method('restart');
        $this->app->instance(AgentManager::class, $manager);

        $this->artisan('taap:agents:reprovision')->assertExitCode(0);

        // El manager se llamo para cada agente (el orden da igual)
        $this->assertTrue(true);
    }

    public function test_only_flag_filters_agents(): void
    {
        $manager = $this->mockManager();
        $manager->expects($this->once())->method('restart');
        $this->app->instance(AgentManager::class, $manager);

        $this->artisan('taap:agents:reprovision', ['--only' => $this->a1->id])->assertExitCode(0);
    }

    public function test_dry_run_informs_without_applying(): void
    {
        $manager = $this->mockManager();
        $manager->expects($this->never())->method('restart');
        $this->app->instance(AgentManager::class, $manager);

        $this->artisan('taap:agents:reprovision', ['--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('[dry-run]');
    }

    public function test_one_failure_does_not_stop_the_rest(): void
    {
        $manager = $this->createMock(AgentManager::class);
        $manager->method('needsMountRecreate')->willReturn(true);
        $manager->method('restart')->willReturnCallback(
            function (Agent $a) {
                if ($a->id === $this->a1->id) {
                    throw new \RuntimeException('docker caido');
                }

                return $a;
            },
        );
        $this->app->instance(AgentManager::class, $manager);

        $this->artisan('taap:agents:reprovision')
            ->assertExitCode(1)
            ->expectsOutputToContain('docker caido');
    }

    public function test_soft_deleted_agents_are_skipped(): void
    {
        $this->a2->delete(); // soft delete

        $manager = $this->mockManager();
        $manager->expects($this->once())->method('restart');
        $this->app->instance(AgentManager::class, $manager);

        $this->artisan('taap:agents:reprovision')->assertExitCode(0);
    }
}

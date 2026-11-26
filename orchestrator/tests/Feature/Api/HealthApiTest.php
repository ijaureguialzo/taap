<?php

namespace Tests\Feature\Api;

use App\Models\Agent;
use App\Models\AgentEventRow;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email' => 'health-admin@taap.test']);

        $agent = Agent::factory()->create(['owner_id' => $this->admin->id, 'status' => 'idle']);
        AgentEventRow::create([
            'agent_id' => $agent->id,
            'type' => 'error',
            'payload' => ['message' => 'comando RPC falló'],
            'occurred_at' => now(),
        ]);

        ModelProvider::factory()->create(['name' => 'Healthy', 'status' => 'ok', 'last_checked_at' => now(), 'created_by' => $this->admin->id]);
        ModelProvider::factory()->create(['name' => 'Unhealthy', 'status' => 'error', 'last_checked_at' => null, 'created_by' => $this->admin->id]);
    }

    public function test_admin_gets_summary(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/v1/health/summary')
            ->assertOk()
            ->assertJsonStructure(['agents_by_status', 'recent_failures', 'providers'])
            ->assertJsonPath('agents_by_status.idle', 1)
            ->assertJsonPath('recent_failures.0.cause', 'comando RPC falló');
    }

    public function test_non_admin_is_denied(): void
    {
        $other = User::factory()->create(['email' => 'health-other@taap.test']);
        $this->actingAs($other, 'sanctum');

        $this->getJson('/api/v1/health/summary')->assertStatus(403);
    }
}

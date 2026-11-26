<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentEventRow;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email' => 'health-page@taap.test']);
        $agent = Agent::factory()->create(['owner_id' => $this->admin->id, 'status' => 'error', 'name' => 'Agente Roto']);
        AgentEventRow::create(['agent_id' => $agent->id, 'type' => 'error', 'payload' => ['message' => 'fallo de prueba'], 'occurred_at' => now()]);
        ModelProvider::factory()->create(['name' => 'Healthy', 'status' => 'ok', 'last_checked_at' => now(), 'created_by' => $this->admin->id]);

        $this->actingAs($this->admin);
    }

    public function test_admin_sees_health_page(): void
    {
        Livewire::test(\App\Livewire\Health\Index::class)
            ->assertOk()
            ->assertSee('Agente Roto')
            ->assertSee('Healthy');
    }

    public function test_non_admin_is_denied(): void
    {
        $other = User::factory()->create(['email' => 'health-page-other@taap.test']);
        $this->actingAs($other);

        $this->get(route('health.index'))->assertForbidden();
    }
}

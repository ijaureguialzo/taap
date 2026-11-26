<?php

namespace Tests\Feature\Api;

use App\Models\Agent;
use App\Models\ModelProvider;
use App\Models\ModelUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Agent $agent;

    protected ModelProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email' => 'usage-admin@taap.test']);
        $this->provider = ModelProvider::factory()->create(['name' => 'UsoProv', 'created_by' => $this->admin->id]);
        $this->agent = Agent::factory()->create(['owner_id' => $this->admin->id, 'name' => 'Agente Uso']);

        ModelUsage::create(['agent_id' => $this->agent->id, 'provider_id' => $this->provider->id, 'tokens_input' => 10, 'tokens_output' => 4, 'status' => 200, 'latency_ms' => 100, 'stream' => false, 'created_at' => now()]);
        ModelUsage::create(['agent_id' => $this->agent->id, 'provider_id' => $this->provider->id, 'tokens_input' => 20, 'tokens_output' => 8, 'status' => 500, 'latency_ms' => 200, 'stream' => true, 'created_at' => now()]);
    }

    public function test_admin_lists_usage_with_totals(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/v1/usage')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('totals.requests', 2)
            ->assertJsonPath('totals.tokens_in', 30)
            ->assertJsonPath('totals.tokens_out', 12)
            ->assertJsonPath('totals.errors', 1)
            ->assertJsonCount(1, 'per_agent')
            ->assertJsonCount(1, 'per_provider');
    }

    public function test_admin_filters_by_agent(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/v1/usage?agent_id='.$this->agent->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_non_admin_is_denied(): void
    {
        $other = User::factory()->create(['email' => 'usage-other@taap.test']);
        $this->actingAs($other, 'sanctum');

        $this->getJson('/api/v1/usage')
            ->assertStatus(403);
    }
}

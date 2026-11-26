<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\ModelProvider;
use App\Models\ModelUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UsageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email' => 'usage-page@taap.test']);
        $provider = ModelProvider::factory()->create(['name' => 'PageProv', 'created_by' => $this->admin->id]);
        $agent = Agent::factory()->create(['owner_id' => $this->admin->id, 'name' => 'Agente Pagina']);

        ModelUsage::create(['agent_id' => $agent->id, 'provider_id' => $provider->id, 'tokens_input' => 5, 'tokens_output' => 2, 'status' => 200, 'latency_ms' => 42, 'stream' => false, 'created_at' => now()]);

        $this->actingAs($this->admin);
    }

    public function test_admin_sees_usage_page(): void
    {
        Livewire::test(\App\Livewire\Usage\Index::class)
            ->assertOk()
            ->assertSee('Agente Pagina');
    }

    public function test_non_admin_is_denied_usage_page(): void
    {
        $other = User::factory()->create(['email' => 'usage-page-other@taap.test']);
        $this->actingAs($other);

        $this->get(route('usage.index'))->assertForbidden();
    }
}

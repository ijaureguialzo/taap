<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentLogsWebTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'detail-logs@taap.test']);
        $this->agent = Agent::factory()->create([
            'owner_id' => $this->owner->id,
            'container_name' => 'taap-agent-test123',
            'name' => 'Agente Logs',
        ]);

        $this->actingAs($this->owner);
    }

    public function test_logs_section_toggles_and_shows_status(): void
    {
        Livewire::test(\App\Livewire\Agents\Detail::class, ['agent' => $this->agent])
            ->assertOk()
            ->call('toggleLogs')
            ->assertOk()
            ->assertSee('Logs del agente')
            // El contenedor de prueba no existe en este entorno:
            ->assertSee('no tiene contenedor');
    }

    public function test_logs_tail_selector_changes(): void
    {
        Livewire::test(\App\Livewire\Agents\Detail::class, ['agent' => $this->agent])
            ->call('toggleLogs')
            ->call('setLogsTail', 500)
            ->assertSet('logsTail', 500);
    }
}

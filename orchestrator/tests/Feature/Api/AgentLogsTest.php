<?php

namespace Tests\Feature\Api;

use App\Models\Agent;
use App\Models\User;
use App\Services\AgentLogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentLogsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'logs-owner@taap.test']);
        $this->agent = Agent::factory()->create([
            'owner_id' => $this->owner->id,
            'container_name' => 'taap-agent-test123',
        ]);
    }

    public function test_owner_gets_log_tail_with_ok_status_when_docker_fails(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        // El contenedor no existe en tests: el servicio devuelve 'no-container'
        // (docker logs falla con rc=1 + stderr "No such container").
        $this->getJson('/api/v1/agents/'.$this->agent->id.'/logs?tail=50')
            ->assertOk()
            ->assertJsonStructure(['status', 'lines'])
            ->assertJsonPath('status', 'no-container');
    }

    public function test_tail_is_capped_and_validated(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->getJson('/api/v1/agents/'.$this->agent->id.'/logs?tail=999')
            ->assertStatus(422);
    }

    public function test_unauthorized_user_is_denied(): void
    {
        $other = User::factory()->create(['email' => 'logs-other@taap.test']);
        $this->actingAs($other, 'sanctum');

        $this->getJson('/api/v1/agents/'.$this->agent->id.'/logs')
            ->assertStatus(404);
    }

    public function test_missing_container_returns_no_container_status(): void
    {
        $agent = Agent::factory()->create([
            'owner_id' => $this->owner->id,
            'container_name' => '',
        ]);
        $this->actingAs($this->owner, 'sanctum');

        $this->getJson('/api/v1/agents/'.$agent->id.'/logs')
            ->assertOk()
            ->assertJsonPath('status', 'no-container')
            ->assertJsonPath('lines', []);
    }
}

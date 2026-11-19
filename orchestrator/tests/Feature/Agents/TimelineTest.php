<?php

namespace Tests\Feature\Agents;

use App\Agents\Timeline;
use App\Models\Agent;
use App\Models\AgentEventRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 7.2: sintesis de la linea de tiempo (ventanas de trabajo a partir de
 * agent_events) y su UI ("lo que hizo mientras no estabas", design D6).
 */
class TimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Locale deterministico para asentar las etiquetas traducidas.
        config(['app.locale' => 'es']);
    }

    public function test_timeline_synthesizes_work_windows_and_renders_them(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->create(['owner_id' => $owner->id]);

        // Ventana 1: trabajo completo hace ~2h (cerrada con run.end).
        $w1 = now()->subHours(2);
        AgentEventRow::factory()->create([
            'agent_id' => $agent->id,
            'type' => 'run.start',
            'occurred_at' => $w1,
        ]);
        AgentEventRow::factory()->create([
            'agent_id' => $agent->id,
            'type' => 'tool.start',
            'payload' => ['tool' => 'web_search'],
            'occurred_at' => $w1->copy()->addMinutes(1),
        ]);
        AgentEventRow::factory()->create([
            'agent_id' => $agent->id,
            'type' => 'message.final',
            'payload' => ['content' => 'Listo.'],
            'occurred_at' => $w1->copy()->addMinutes(5),
        ]);
        AgentEventRow::factory()->create([
            'agent_id' => $agent->id,
            'type' => 'run.end',
            'payload' => ['summary' => 'Informe generado'],
            'occurred_at' => $w1->copy()->addMinutes(6),
        ]);

        // Ventana 2: trabajo reciente, sin run.end => en curso
        // (hueco > gap respecto a la ventana 1).
        $w2 = now()->subMinutes(10);
        AgentEventRow::factory()->create([
            'agent_id' => $agent->id,
            'type' => 'run.start',
            'occurred_at' => $w2,
        ]);
        AgentEventRow::factory()->create([
            'agent_id' => $agent->id,
            'type' => 'approval.request',
            'payload' => ['tool' => 'shell'],
            'occurred_at' => $w2->copy()->addMinute(),
        ]);
        AgentEventRow::factory()->create([
            'agent_id' => $agent->id,
            'type' => 'error',
            'payload' => ['message' => 'boom'],
            'occurred_at' => $w2->copy()->addMinutes(2),
        ]);

        // Sintesis del servicio.
        $windows = app(Timeline::class)->windows($agent->id);
        $this->assertCount(2, $windows);
        $this->assertFalse($windows[0]['open']);
        $this->assertSame('Informe generado', $windows[0]['note']);
        $this->assertSame(1, $windows[0]['messages']);
        $this->assertSame(['web_search'], $windows[0]['tools']);
        $this->assertTrue($windows[1]['open']);
        $this->assertSame(1, $windows[1]['approvals']);
        $this->assertSame(1, $windows[1]['errors']);

        // UI: el dueño ve la seccion con ambos resu­menes y el estado remate.
        $this->actingAs($owner)
            ->get(route('agents.show', $agent))
            ->assertOk()
            ->assertSee('Mientras no estabas')
            ->assertSee('Informe generado')
            ->assertSee('web_search')
            ->assertSee('En curso');
    }

    public function test_timeline_is_empty_without_events(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)
            ->get(route('agents.show', $agent))
            ->assertOk()
            ->assertSee('Sin actividad registrada');
    }

    public function test_timeline_api_endpoint_returns_windows_and_status(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->create(['owner_id' => $owner->id, 'status' => 'idle']);

        $w = now()->subMinutes(30);
        AgentEventRow::factory()->create(['agent_id' => $agent->id, 'type' => 'run.start', 'occurred_at' => $w]);
        AgentEventRow::factory()->create(['agent_id' => $agent->id, 'type' => 'tool.start', 'payload' => ['tool' => 'shell'], 'occurred_at' => $w->copy()->addMinute()]);
        AgentEventRow::factory()->create(['agent_id' => $agent->id, 'type' => 'run.end', 'payload' => ['summary' => 'Hecho'], 'occurred_at' => $w->copy()->addMinutes(4)]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/agents/{$agent->id}/timeline")
            ->assertOk()
            ->assertJsonPath('agent_status', 'idle')
            ->assertJsonPath('windows.0.note', 'Hecho')
            ->assertJsonPath('windows.0.open', false)
            ->assertJsonPath('windows.0.tools.0', 'shell')
            ->assertJsonPath('windows.0.tool_calls', 1);
    }

    public function test_timeline_hides_other_owners_activity(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $agent = Agent::factory()->create(['owner_id' => $owner->id]);

        AgentEventRow::factory()->create([
            'agent_id' => $agent->id,
            'type' => 'message.final',
            'payload' => ['content' => 'SECRETO-PROPIO'],
        ]);

        // No es dueño ni admin: 404 (los eventos ajenos no se filtran).
        $this->actingAs($other)->get(route('agents.show', $agent))->assertNotFound();
    }

    public function test_admin_can_see_any_agent_timeline(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $agent = Agent::factory()->create(['owner_id' => $owner->id]);

        AgentEventRow::factory()->create([
            'agent_id' => $agent->id,
            'type' => 'run.start',
            'occurred_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($admin)
            ->get(route('agents.show', $agent))
            ->assertOk()
            ->assertSee('Mientras no estabas');
    }
}

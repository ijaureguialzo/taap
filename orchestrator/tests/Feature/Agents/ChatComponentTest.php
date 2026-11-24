<?php

namespace Tests\Feature\Agents;

use App\Enums\AgentRuntime;
use App\Enums\AgentStatus;
use App\Livewire\Agents\Chat;
use App\Models\Agent;
use App\Models\AgentEventRow;
use App\Jobs\BridgeSendMessage;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Componente del chat (interactive-agent-chat, §3): historial + feed de
 * actividad por run, streaming por ESQUELETO (run.start -> 'live';
 * message.final -> null), estado en vivo (status.change) y aprobacion.
 *
 * `Queue::fake()` en todo: el EventSink difunde los eventos a Reverb de
 * forma encolada; sin el fake los tests abririan sockets reales.
 */
class ChatComponentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(
            \App\Agents\AgentManager::class,
            fn ($app) => $this->createMock(\App\Agents\AgentManager::class),
        );
        Queue::fake();
    }

    protected function makeAgent(AgentStatus $status = AgentStatus::Idle): Agent
    {
        $owner = User::factory()->create();

        return Agent::factory()->create([
            'owner_id' => $owner->id,
            'runtime' => AgentRuntime::Pi,
            'status' => $status,
        ]);
    }

    public function test_mount_returns_empty_history_and_idle_streaming(): void
    {
        $agent = $this->makeAgent();

        $test = Livewire::test(Chat::class, ['agent' => $agent]);

        $this->assertSame(null, $test->streaming);
        // 4.2: el estado en vivo se siembra con el persistido en el mount
        // (los status.change del canal lo irán actualizando).
        $this->assertSame('idle', $test->liveStatus);
        $this->assertNull($test->pendingApproval);
        $test->assertSee(__('chat.empty'), escape: false);
    }

    public function test_history_and_activities_are_rendered_per_run(): void
    {
        $agent = $this->makeAgent();

        // Turno previo: razonamiento en meta + texto final + actividad.
        ChatMessage::factory()->create([
            'agent_id' => $agent->id,
            'author' => 'user',
            'content' => 'revisa el repo',
            'meta' => ['run_id' => 'r-1'],
        ]);
        ChatMessage::factory()->create([
            'agent_id' => $agent->id,
            'author' => 'agent',
            'content' => 'Listo, no hay errores.',
            'meta' => ['run_id' => 'r-1', 'thinking' => "busqué en el repo\ny lo confirmé"],
        ]);
        AgentEventRow::create([
            'agent_id' => $agent->id,
            'type' => 'tool.start',
            'payload' => ['run_id' => 'r-1', 'tool' => 'bash', 'args' => ['command' => 'rg TODO src/']],
            'occurred_at' => now()->subMinute(),
        ]);

        Livewire::test(Chat::class, ['agent' => $agent])
            ->assertSee('revisa el repo', escape: false)
            ->assertSee('Listo, no hay errores.', escape: false)
            ->assertSee('bash · rg TODO src/', escape: false);

        // 3.3: el razonamiento sale como sección colapsable: el
        // <details> SIN `open` (colapsado por defecto).
        $html = Livewire::test(Chat::class, ['agent' => $agent])->html();
        $this->assertStringContainsString('<details class="msg-reasoning mb-2">', $html);
        $this->assertStringContainsString('busqué en el repo', $html);
        $this->assertDoesNotMatchRegularExpression('/<details class="msg-reasoning[^"]*"\s+open/', $html);
    }

    public function test_run_start_opens_the_skeleton_and_final_closes_it(): void
    {
        $agent = $this->makeAgent(AgentStatus::Working);

        $test = Livewire::test(Chat::class, ['agent' => $agent]);
        $this->assertSame(null, $test->streaming);

        $test->call('agentEvent', [
            'agent_id' => $agent->id,
            'type' => 'run.start',
            'payload' => ['run_id' => 'r-9', 'task' => 'x'],
        ]);
        $this->assertSame('live', $test->streaming);

        // Un delta aislado (el JS lo envia para "convocar" el esqueleto si
        // se perdió el run.start) no debe cerrarlo.
        $test->call('agentEvent', [
            'agent_id' => $agent->id,
            'type' => 'message.delta',
            'payload' => ['run_id' => null, 'content' => ''],
        ]);
        $this->assertSame('live', $test->streaming);

        // El sink (no el componente) persiste el final; el componente lo
        // recibe por canal para cerrar el esqueleto.
        app(\App\Agents\EventSink::class)->dispatch(
            $agent,
            \App\Agents\AgentEvent::messageFinal('r-9', 'hola', 'pensé'),
        );
        $test->call('agentEvent', [
            'agent_id' => $agent->id,
            'type' => 'message.final',
            'payload' => ['run_id' => 'r-9', 'content' => 'hola', 'thinking' => 'pensé'],
        ]);
        $this->assertSame(null, $test->streaming);

        // El final se persistió (author=agent) y ahora forma parte del
        // historial renderizado (3.5: no duplicado por el cliente).
        $final = ChatMessage::where('agent_id', $agent->id)
            ->where('author', 'agent')
            ->first();
        $this->assertNotNull($final);
        $this->assertSame('hola', $final->content);
        $this->assertSame('pensé', $final->meta['thinking'] ?? null);
        $test->assertSee('hola', escape: false);
    }

    public function test_status_change_updates_live_status_used_by_input_and_buttons(): void
    {
        $agent = $this->makeAgent(AgentStatus::Stopped);

        $test = Livewire::test(Chat::class, ['agent' => $agent]);
        $this->assertSame('stopped', $test->instance()->liveStatus);

        $test->call('agentEvent', [
            'agent_id' => $agent->id,
            'type' => 'status.change',
            'payload' => ['status' => 'idle', 'at' => now()->toIso8601String()],
        ]);

        $this->assertSame('idle', $test->instance()->liveStatus);
        $this->assertSame(AgentStatus::Idle, $test->instance()->effectiveStatus());
    }

    public function test_send_broadcasts_and_notifies_the_client(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->create([
            'owner_id' => $owner->id,
            'runtime' => AgentRuntime::Pi,
            'status' => AgentStatus::Idle,
        ]);

        Livewire::actingAs($owner)->test(Chat::class, ['agent' => $agent])
            ->call('send', 'hola')
            ->assertDispatched('message-sent');

        // El prompt se encola en la cola FIFO del bridge (sync en tests).
        Queue::assertPushedOn('taap-bridge', \App\Jobs\BridgeSendMessage::class);
        $this->assertSame(
            1,
            ChatMessage::where('agent_id', $agent->id)->where('author', 'user')->count(),
        );
    }

    public function test_approval_card_roundtrip(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->create([
            'owner_id' => $owner->id,
            'runtime' => AgentRuntime::Claude,
            'status' => AgentStatus::WaitingApproval,
        ]);

        AgentEventRow::create([
            'agent_id' => $agent->id,
            'type' => 'approval.request',
            'payload' => ['run_id' => 'r-a', 'id' => 'a-1', 'prompt' => '¿Ejecutar rm -rf /data?', 'resolved' => false],
            'occurred_at' => now(),
        ]);

        $test = Livewire::actingAs($owner)->test(Chat::class, ['agent' => $agent]);

        // La aprobacion ya existia en BD (la pagina se abrio despues):
        // loadPendingApprovals la levanta (el JS difunde `approvalRequested`).
        $test->call('loadPendingApprovals');
        $this->assertNotNull($test->pendingApproval);

        $test->call('approve', true, 'once');
        $this->assertNull($test->pendingApproval);
        // El sink marco el request como resuelto (payload->resolved = true)
        // y lo excluye de las siguientes cargas pendientes.
        $row = AgentEventRow::where('agent_id', $agent->id)->first();
        $this->assertTrue((bool) ($row->payload['resolved'] ?? false));
        $test->call('loadPendingApprovals');
        $this->assertNull($test->pendingApproval);
    }

    public function test_effective_status_falls_back_to_persisted(): void
    {
        $agent = $this->makeAgent(AgentStatus::Error);

        $component = new Chat;
        $component->agent = $agent;

        $this->assertSame(AgentStatus::Error, $component->effectiveStatus());
    }
}

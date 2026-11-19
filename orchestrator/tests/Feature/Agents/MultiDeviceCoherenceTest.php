<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 7.3: coherencia multi-dispositivo. Dos clientes autorizados (p. ej. el
 * movil del dueño y la web del admin) ven el mismo historial en el mismo
 * orden y reciben el mismo flujo de eventos del canal `agent.{id}`:
 * el orden lo fija la persistencia (chat_messages.id / agent_events) y el
 * broadcast por canal solo lo reenvia a los suscriptores.
 */
class MultiDeviceCoherenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'es']);
    }

    public function test_owner_and_admin_authorize_the_same_channel_stranger_cannot(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->create(['owner_id' => $owner->id]);

        $channel = 'agent.'.$agent->id;

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => 's1'])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => 's2'])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => 's3'])
            ->assertForbidden();
    }

    public function test_both_clients_get_identical_history_in_the_same_order(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $agent = Agent::factory()->create(['owner_id' => $owner->id]);

        // Historial con varios autores; el orden cronologico es el contrato.
        $ids = [];
        foreach ([
            ['author' => 'user', 'content' => 'hola'],
            ['author' => 'assistant', 'content' => 'buenas'],
            ['author' => 'assistant', 'content' => 'hice la tarea'],
            ['author' => 'user', 'content' => 'genial'],
        ] as $i => $m) {
            $ids[] = ChatMessage::factory()->create([
                'agent_id' => $agent->id,
                'user_id' => $m['author'] === 'user' ? $owner->id : null,
                'author' => $m['author'],
                'content' => $m['content'],
            ])->id;
        }

        $a = $this->actingAs($owner, 'sanctum')->getJson("/api/v1/agents/{$agent->id}/messages")->json('messages');
        $b = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/agents/{$agent->id}/messages")->json('messages');

        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertSame($ids, array_map(fn ($m) => (int) $m['id'], $a));
    }

    public function test_sends_from_one_client_are_broadcast_once_and_visible_to_the_other(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $agent = Agent::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'working',
            'runtime' => 'pi',
        ]);

        // En la v13 el broadcast viaja por cola (BroadcastEvent); la fakeamos
        // para no depender de un Reverb en el entorno de test.
        Queue::fake();

        // El cliente A (owner) envia dos mensajes.
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/agents/{$agent->id}/messages", ['content' => 'uno'])
            ->assertCreated();
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/agents/{$agent->id}/messages", ['content' => 'dos'])
            ->assertCreated();

        // Un broadcast por envio, en el canal del agente y con el nombre del
        // contrato {type, payload, at}.
        $broadcasts = Queue::pushed(BroadcastEvent::class, function ($job) use ($agent) {
            $event = $job->event;
            $channels = array_map(
                fn ($c) => $c instanceof Channel ? $c->name : (string) $c,
                $event->broadcastOn()
            );

            return in_array('agent.'.$agent->id, $channels, true)
                && $event->broadcastAs() === 'agent-event';
        });
        $this->assertCount(2, $broadcasts);
        $this->assertSame(2, ChatMessage::where('agent_id', $agent->id)->count());

        // El cliente B (admin) ve el mismo historial, en el mismo orden.
        $history = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/agents/{$agent->id}/messages")
            ->json('messages');

        $this->assertSame(['uno', 'dos'], array_map(fn ($m) => $m['content'], $history));
        $this->assertSame('user', $history[0]['author']);
    }

    public function test_agent_messages_with_run_meta_are_visible_and_synthetic_hidden(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->create(['owner_id' => $owner->id]);

        // El sink guarda los finales de corrida con meta {run_id: ...}; antes
        // del fix, `meta->synthetic` NULL + `!= true` ocultaba todos.
        ChatMessage::factory()->create([
            'agent_id' => $agent->id, 'author' => 'user', 'user_id' => $owner->id,
            'content' => 'haz X',
        ]);
        ChatMessage::factory()->create([
            'agent_id' => $agent->id, 'author' => 'agent',
            'content' => 'hecho X', 'meta' => ['run_id' => null],
        ]);
        ChatMessage::factory()->create([
            'agent_id' => $agent->id, 'author' => 'user', 'user_id' => $owner->id,
            'content' => 'ok', 'meta' => ['run_id' => 'abc'],
        ]);
        ChatMessage::factory()->create([
            'agent_id' => $agent->id, 'author' => 'agent',
            'content' => 'ok!', 'meta' => ['run_id' => 'abc'],
        ]);
        ChatMessage::factory()->create([
            'agent_id' => $agent->id, 'author' => 'user', 'user_id' => $owner->id,
            'content' => '(sintetico)', 'meta' => ['synthetic' => true],
        ]);

        $messages = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/agents/{$agent->id}/messages")
            ->json('messages');

        $contents = array_map(fn ($m) => $m['content'], $messages);
        $this->assertContains('hecho X', $contents);
        $this->assertContains('ok!', $contents);
        $this->assertNotContains('(sintetico)', $contents);
    }
}

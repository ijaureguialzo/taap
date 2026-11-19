<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentManager;
use App\Agents\ChatService;
use App\Agents\QuotaExceededException;
use App\Enums\AgentStatus;
use App\Jobs\BridgeSendMessage;
use App\Models\Agent;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Envio desde el chat (fix-chat-viewport-send): el envio a un agente
 * detenido lo arranca y encola el mensaje; en espera de aprobacion se
 * rechaza; en ejecucion se encola sin tocar el ciclo de vida.
 */
class ChatSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'es']);
    }

    /**
     * El manager no debe tocar Docker en el entorno de test: un mock
     * cuyo start() emula la transicion al estado Starting.
     */
    protected function fakeManager(): AgentManager
    {
        $manager = $this->createMock(AgentManager::class);
        $manager->method('start')->willReturnCallback(
            function (Agent $agent): Agent {
                $agent->forceFill([
                    'status' => AgentStatus::Starting,
                    'started_at' => now(),
                ])->save();

                return $agent;
            }
        );
        $this->app->instance(AgentManager::class, $manager);

        return $manager;
    }

    public function test_send_to_stopped_agent_starts_it_and_queues_the_message(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->create([
            'owner_id' => $owner->id,
            'status' => AgentStatus::Stopped,
            'runtime' => 'pi',
        ]);

        $this->fakeManager()->expects($this->once())->method('start')->with($agent);
        Queue::fake();

        $message = $this->app->make(ChatService::class)->send($agent, $owner, 'hola');

        $agent->refresh();
        $this->assertSame(AgentStatus::Starting, $agent->status);
        $this->assertSame('user', $message->author);
        $this->assertSame('hola', $message->content);
        Queue::assertPushedOn('taap-bridge', BridgeSendMessage::class);
    }

    public function test_send_to_running_agent_queues_without_touching_lifecycle(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->running()->create([
            'owner_id' => $owner->id,
            'runtime' => 'pi',
        ]);

        $this->fakeManager()->expects($this->never())->method('start');
        Queue::fake();

        $message = $this->app->make(ChatService::class)->send($agent, $owner, 'hola');

        $agent->refresh();
        $this->assertSame(AgentStatus::Idle, $agent->status);
        $this->assertSame('hola', $message->content);
        Queue::assertPushedOn('taap-bridge', BridgeSendMessage::class);
    }

    public function test_send_while_waiting_for_approval_is_rejected_and_not_persisted(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->create([
            'owner_id' => $owner->id,
            'status' => AgentStatus::WaitingApproval,
            'runtime' => 'pi',
        ]);

        $this->fakeManager()->expects($this->never())->method('start');
        Queue::fake();

        $this->expectException(QuotaExceededException::class);
        $this->app->make(ChatService::class)->send($agent, $owner, 'hola');
        $this->assertSame(0, ChatMessage::where('agent_id', $agent->id)->count());
    }

    public function test_empty_message_is_rejected(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->running()->create([
            'owner_id' => $owner->id,
            'runtime' => 'pi',
        ]);

        $this->fakeManager()->expects($this->never())->method('start');
        Queue::fake();

        $this->expectException(\RuntimeException::class);
        $this->app->make(ChatService::class)->send($agent, $owner, '   ');
        $this->assertSame(0, ChatMessage::where('agent_id', $agent->id)->count());
    }
}

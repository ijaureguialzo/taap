<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentEvent;
use App\Agents\EventSink;
use App\Broadcasts\AgentEventBroadcast;
use App\Models\Agent;
use App\Models\AgentEventRow;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\TestCase;

/**
 * EventSink (interactive-agent-chat 1.2, design D1):
 * - los deltas (message/thinking) SÍ se difunden por el canal `agent.{id}`
 *   (se retira la exclusión v1) y siguen sin persistirse como fila;
 * - `message.final` persiste `meta.thinking` (el razonamiento completo del
 *   turno, separado de `content`) junto al `meta.run_id` actual.
 */
class EventSinkTest extends TestCase
{
    use RefreshDatabase;

    /** @var QueueFake */
    protected $queueFake;

    protected function setUp(): void
    {
        parent::setUp();

        // Queue::fake() re-llamado crea UN fake nuevo (vacío): se guarda la
        // referencia creada una sola vez.
        $this->queueFake = Queue::fake();
    }

    protected function agent(string $state = 'running'): Agent
    {
        $owner = User::factory()->create();

        return Agent::factory()->$state()->create(['owner_id' => $owner->id]);
    }

    /** @return list<AgentEventBroadcast> los broadcast colados por el sink */
    protected function broadcasts(): array
    {
        return array_map(
            fn (BroadcastEvent $job) => $job->event,
            $this->queueFake->pushed(BroadcastEvent::class)->all()
        );
    }

    public function test_message_delta_is_broadcast_to_the_agent_channel_and_not_persisted(): void
    {
        // Working: sin efecto de estado (un idle pasaría a working y
        // persistiría la status.change, no el delta).
        $agent = $this->agent('working');
        $sink = new EventSink;

        $sink->dispatch($agent->refresh(), AgentEvent::messageDelta('run-1', 'hola'));

        // El canal es el público `agent.{id}` (el mismo que web/iOS escuchan).
        $broadcast = collect($this->broadcasts())
            ->first(fn (AgentEventBroadcast $b) => $b->type === 'message.delta');
        $this->assertNotNull($broadcast, 'falta el broadcast del delta: '.json_encode(array_map(fn ($b) => $b->type, $this->broadcasts())));
        $this->assertSame('agent.'.$agent->id, $broadcast->broadcastOn()[0]->name);
        $this->assertSame('agent-event', $broadcast->broadcastAs());
        $this->assertSame(
            ['agent_id' => $agent->id, 'type' => 'message.delta', 'payload' => ['run_id' => 'run-1', 'content' => 'hola']],
            array_intersect_key($broadcast->broadcastWith(), ['agent_id' => 1, 'type' => 1, 'payload' => 1])
        );

        // No persiste como fila (spec agent-chat: los deltas solo el final).
        $this->assertSame(0, AgentEventRow::where('agent_id', $agent->id)->count());
    }

    public function test_thinking_delta_is_broadcast_and_not_persisted(): void
    {
        $agent = $this->agent('working');
        $sink = new EventSink;

        $sink->dispatch($agent->refresh(), AgentEvent::thinkingDelta('run-1', 'muy interesante...'));

        $broadcast = collect($this->broadcasts())
            ->first(fn (AgentEventBroadcast $b) => $b->type === 'thinking.delta');
        $this->assertNotNull($broadcast, 'falta el broadcast del thinking.delta');
        $this->assertSame('agent.'.$agent->id, $broadcast->broadcastOn()[0]->name);

        $this->assertSame(0, AgentEventRow::where('agent_id', $agent->id)->count());
    }

    public function test_non_delta_events_keep_persisting_and_broadcasting(): void
    {
        $agent = $this->agent();
        $sink = new EventSink;

        $sink->dispatch($agent->refresh(), AgentEvent::runStart('run-1'));

        // run.start + la status.change('working') que el sink aplica como
        // efecto de estado (el agente estaba idle).
        $this->assertSame(1, AgentEventRow::where('agent_id', $agent->id)->where('type', 'run.start')->count());
        $this->assertTrue(
            collect($this->broadcasts())->contains(fn (AgentEventBroadcast $b) => $b->type === 'run.start')
        );
        $this->assertSame('working', $agent->refresh()->status->value, 'el delta de estado se aplica y difunde');
    }

    public function test_message_final_persists_thinking_in_meta_separated_from_content(): void
    {
        $agent = $this->agent();
        $sink = new EventSink;

        $sink->dispatch($agent->refresh(), AgentEvent::messageFinal(
            'run-1',
            'La respuesta final.',
            'El razonamiento completo del turno.'
        ));

        $message = ChatMessage::where('agent_id', $agent->id)->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertSame('agent', $message->author);
        $this->assertSame('La respuesta final.', $message->content, 'el contenido NO incluye el razonamiento fundido');
        $this->assertSame(['run_id' => 'run-1', 'thinking' => 'El razonamiento completo del turno.'], $message->meta);

        $this->assertTrue(
            collect($this->broadcasts())->contains(fn (AgentEventBroadcast $b) => $b->type === 'message.final')
        );
    }

    public function test_message_final_without_thinking_persists_empty_meta_thinking(): void
    {
        $agent = $this->agent();
        $sink = new EventSink;

        $sink->dispatch($agent->refresh(), AgentEvent::messageFinal('run-1', 'Solo texto.'));

        $message = ChatMessage::where('agent_id', $agent->id)->latest('id')->first();
        $this->assertSame(['run_id' => 'run-1', 'thinking' => ''], $message->meta);
    }

    public function test_re_emitted_identical_final_is_still_deduplicated(): void
    {
        // Pi reemite message_end del historial al re-adjuntar: el anti-dup de
        // (run_id, contenido, razonamiento) sigue cubriéndolo.
        $agent = $this->agent();
        $sink = new EventSink;
        $event = AgentEvent::messageFinal('run-1', 'Hola.', 'Pensé esto.');

        $sink->dispatch($agent->refresh(), $event);
        $sink->dispatch($agent->refresh(), $event);

        $this->assertSame(1, ChatMessage::where('agent_id', $agent->id)->count());
    }
}

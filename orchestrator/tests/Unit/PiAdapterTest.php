<?php

namespace Tests\Unit;

use App\Agents\AgentEvent;
use App\Agents\EventSink;
use App\Agents\PiAdapter;
use App\Models\Agent;
use App\Models\AgentToken;
use App\Models\User;
use App\Support\DockerClient;
use App\Support\ProcessStreams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PiAdapter (interactive-agent-chat 2.2): el dialecto RPC de
 * pi-coding-agent con razonamiento (docs json.md/message-types.md de
 * 1.6.0): `thinking_delta` -> `thinking.delta` (deja de fundirse en
 * `message.delta`) y bloques `thinking` de `message_end` -> `thinking`
 * del `message.final`. Docker simulado; el stdout de pi se simula
 * escribiendo lineas en $dir/out (el mismo mecanismo que usa el bridge).
 */
class PiAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected EventSink $sink;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->dir = sys_get_temp_dir().'/taap-pi-'.uniqid();
        mkdir($this->dir.'/rpc', 0777, true);
        $this->sink = new EventSink;
    }

    protected function tearDown(): void
    {
        foreach ((array) @scandir($this->dir.'/rpc') as $f) {
            if ($f !== '.' && $f !== '..') {
                @unlink($this->dir.'/rpc/'.$f);
            }
        }
        @rmdir($this->dir.'/rpc');
        @rmdir($this->dir);

        parent::tearDown();
    }

    protected function makeAdapter(Agent $agent): PiAdapter
    {
        $docker = $this->createMock(DockerClient::class);
        $docker->method('rpcDir')->willReturn($this->dir.'/rpc');
        $docker->method('status')->willReturn('running');
        $docker->method('coprocessAlive')->willReturn(true);
        $streams = $this->createMock(ProcessStreams::class);
        $docker->method('attachReader')->willReturn($streams);
        $docker->method('startWriter')->willReturn($streams);
        $docker->method('killCoprocess');

        return new PiAdapter($docker, $this->sink);
    }

    protected function agent(): Agent
    {
        $user = User::factory()->create();
        $token = AgentToken::issueFor($a = Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => 'pi',
            'status' => 'starting',
            'container_name' => 'taap-agent-pi-test',
            'container_id' => 'cid-test',
        ]));
        $token->save();

        return $a;
    }

    /** Escribe una linea RPC (u otra cosa) en el `out` simulado. */
    protected function out(array|string $obj): void
    {
        $line = is_string($obj) ? $obj : json_encode($obj);
        file_put_contents($this->dir.'/rpc/out', $line."\n", FILE_APPEND);
    }

    protected function pump(PiAdapter $adapter, Agent $agent): array
    {
        $events = $adapter->drain($agent);
        foreach ($events as $event) {
            $this->sink->dispatch($agent->refresh(), $event);
        }

        return $events;
    }

    /**
     * Un turno sintetizado (text+thinking, shapes de json.md 1.6.0)
     * produce los tres shapes de salida normalizados.
     */
    public function test_turn_with_text_and_thinking_maps_to_separated_shapes(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $this->out(['type' => 'agent_start', 'id' => 'x1']);

        // Razonamiento en streaming (thinking_delta de un bloque de thinking)
        $this->out([
            'type' => 'message_update',
            'assistantMessageEvent' => ['type' => 'thinking_start', 'contentIndex' => 0],
        ]);
        $this->out([
            'type' => 'message_update',
            'assistantMessageEvent' => ['type' => 'thinking_delta', 'contentIndex' => 0, 'delta' => 'Debo'],
        ]);
        $this->out([
            'type' => 'message_update',
            'assistantMessageEvent' => ['type' => 'thinking_delta', 'contentIndex' => 0, 'delta' => ' mirar primero.'],
        ]);
        $this->out([
            'type' => 'message_update',
            'assistantMessageEvent' => ['type' => 'thinking_end', 'contentIndex' => 0, 'content' => 'Debo mirar primero.'],
        ]);

        // Texto en streaming (el thinking NO debe aparecer en message.delta)
        $this->out([
            'type' => 'message_update',
            'assistantMessageEvent' => ['type' => 'text_start', 'contentIndex' => 1],
        ]);
        $this->out([
            'type' => 'message_update',
            'assistantMessageEvent' => ['type' => 'text_delta', 'contentIndex' => 1, 'delta' => 'Hay 3 ficheros.'],
        ]);

        // Mensaje final: bloques thinking + text (ThinkingContent de message-types.md)
        $this->out([
            'type' => 'message_end',
            'message' => [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'Debo mirar primero.'],
                    ['type' => 'text', 'text' => 'Hay 3 ficheros.'],
                ],
            ],
        ]);
        $this->out(['type' => 'agent_settled', 'aborted' => false]);

        $events = $this->pump($adapter, $agent);
        $types = array_map(fn (AgentEvent $e) => $e->type, $events);

        $this->assertContains('thinking.delta', $types, 'falta thinking.delta en '.json_encode($types));
        $this->assertContains('message.delta', $types, 'falta message.delta en '.json_encode($types));
        $this->assertContains('message.final', $types, 'falta message.final en '.json_encode($types));

        // 1) thinking.delta: shape {run_id, content}
        $tdeltas = collect($events)->filter(fn (AgentEvent $e) => $e->type === 'thinking.delta')
            ->map(fn (AgentEvent $e) => $e->payload['content'])->implode('');
        $this->assertSame('Debo mirar primero.', $tdeltas, 'los thinking_delta se concatenan en el flujo thinking');

        // 2) message.delta: SOLO el texto (el thinking no se funde en el texto)
        $mdeltas = collect($events)->filter(fn (AgentEvent $e) => $e->type === 'message.delta')
            ->map(fn (AgentEvent $e) => $e->payload['content'])->implode('');
        $this->assertSame('Hay 3 ficheros.', $mdeltas, 'el thinking debe de fundirse en message.delta');

        // 3) message.final: content y thinking SEPARADOS
        $final = collect($events)->first(fn (AgentEvent $e) => $e->type === 'message.final');
        $this->assertNotNull($final);
        $this->assertSame('Hay 3 ficheros.', $final->payload['content']);
        $this->assertSame('Debo mirar primero.', $final->payload['thinking']);
    }

    /** Mensaje sin texto (solo thinking+toolcall): no emite final (conducta actual). */
    public function test_thinking_only_message_emits_no_final_but_streams_thinking(): void
    {
        $agent = $this->agent();
        $adapter = $this->makeAdapter($agent);
        $adapter->attach($agent);

        $this->out(['type' => 'agent_start', 'id' => 'x1']);
        $this->out([
            'type' => 'message_update',
            'assistantMessageEvent' => ['type' => 'thinking_delta', 'contentIndex' => 0, 'delta' => 'vamos a ejecutar...'],
        ]);
        $this->out([
            'type' => 'message_end',
            'message' => [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'vamos a ejecutar...'],
                    ['type' => 'toolCall', 'id' => 'tc1', 'toolName' => 'bash', 'arguments' => ['command' => 'ls']],
                ],
            ],
        ]);

        $events = $this->pump($adapter, $agent);
        $types = array_map(fn (AgentEvent $e) => $e->type, $events);

        $this->assertNotContains('message.final', $types, 'sin texto no hay mensaje asientado');
        $this->assertContains('thinking.delta', $types, 'el razonamiento de ese paso sí se vio en vivo');
    }
}

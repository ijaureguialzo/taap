<?php

namespace Tests\Unit;

use App\Agents\AgentEvent;
use App\Agents\EventSink;
use App\Agents\HermesAdapter;
use App\Models\Agent;
use App\Models\AgentToken;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use App\Support\DockerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * HermesAdapter (interactive-agent-chat 2.5): el gateway relays el SSE del
 * Model Proxy, que expone el razonamiento en `delta.reasoning_content`
 * (Qwen3/DeepSeek) y eventos propios `hermes.tool.progress` (verificado en
 * el spike 2.5 contra la cadena real gateway->proxy->orcarouter). El body
 * de la respuesta simulada es el shape REAL capturado en el spike.
 */
class HermesAdapterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<AgentEvent> */
    protected array $events = [];

    protected HermesAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        // El broadcast va en cola (el sink no bloquea el bucle del bridge):
        // con Queue::fake no hay llamada real al Reverb.
        Queue::fake();

        $docker = $this->createMock(DockerClient::class);
        $docker->method('status')->willReturn('running');
        $docker->method('rpcDir')->willReturn(sys_get_temp_dir().'/taap-hermes-rpc');

        $sink = new class extends EventSink
        {
            /** @var list<AgentEvent> */
            public array $events = [];

            public function dispatch(Agent $agent, AgentEvent $event): void
            {
                $this->events[] = $event;
                parent::dispatch($agent, $event);
            }
        };

        $this->events = &$sink->events;
        $this->adapter = new HermesAdapter($docker, $sink);
    }

    protected function agent(): Agent
    {
        $user = User::factory()->create();
        $provider = ModelProvider::factory()->create([
            'type' => 'openai',
            'created_by' => $user->id,
        ]);
        $model = Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => 'orcarouter/Qwen3.8-27B-Uncensored-MLX',
            'name' => 'Qwen 3.8 27B',
        ]);

        $agent = Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => 'hermes',
            'status' => 'starting',
            'container_name' => 'taap-agent-hermes-test',
            'container_id' => 'cid-test',
            'model_id' => $model->id,
        ]);
        AgentToken::issueFor($agent);

        return $agent;
    }

    /** El SSE REAL del spike 2.5 (gateway Hermes -> Qwen3 razonando). */
    protected function spikeSse(): string
    {
        $chunk = fn (array|\stdClass $delta, ?string $finish, array $extra = []) => 'data: '.json_encode([
            'id' => 'chatcmpl-09860913db454645b788fc06473f5',
            'object' => 'chat.completion.chunk',
            'created' => 1795475184,
            'model' => 'hermes-agent',
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
            ...$extra,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        $progress = fn (string $status, string $tool, string $label, string $id) =>
            "event: hermes.tool.progress\n".
            'data: '.json_encode(['tool' => $tool, 'label' => $label, 'toolCallId' => $id, 'status' => $status])."\n\n";

        return $chunk(['role' => 'assistant'], null)
            . ": keepalive\n\n"
            . $chunk(['reasoning_content' => "The user is asking for 17 × 23. Let me verify with a tool.\n"], null)
            . $progress('running', 'terminal', 'python3 -c "print(17 * 23)"', 'call_1_0')
            . $progress('completed', 'terminal', '', 'call_1_0')
            . $chunk(['reasoning_content' => "17 × 23 = 17 × 20 + 17 × 3 = 340 + 51 = 391. Verificado.\n"], null)
            . $chunk(['content' => "\nStep by step: 391.\n\nLa respuesta es 391 (verificada)."], null)
            . $chunk(new \stdClass, 'stop', ['usage' => ['prompt_tokens' => 34469, 'completion_tokens' => 273, 'total_tokens' => 34742]])
            . "data: [DONE]\n\n";
    }

    public function test_stream_with_reasoning_maps_to_separated_thinking(): void
    {
        $agent = $this->agent();

        Http::fake(fn (Request $request) => Http::response($this->spikeSse(), 200, [
            'Content-Type' => 'text/event-stream',
        ]));

        $this->adapter->sendUserMessage($agent, 'Cuanto es 17 por 23?', 1);

        $types = array_map(fn (AgentEvent $e) => $e->type, $this->events);

        // run.start/working al abrir; final + run.end + idle al cerrar
        $this->assertContains('thinking.delta', $types, 'falta thinking.delta en '.json_encode($types));
        $this->assertContains('message.delta', $types, 'falta message.delta en '.json_encode($types));
        $this->assertContains('tool.start', $types, 'falta tool.start (hermes.tool.progress) en '.json_encode($types));
        $this->assertContains('tool.result', $types, 'falta tool.result (hermes.tool.progress) en '.json_encode($types));

        // 1) thinking: los dos razonamientos concatenados, SEPARADOS
        $thinking = collect($this->events)->filter(fn ($e) => $e->type === 'thinking.delta')
            ->map(fn ($e) => $e->payload['content'])->implode('');
        $this->assertSame(
            "The user is asking for 17 × 23. Let me verify with a tool.\n17 × 23 = 17 × 20 + 17 × 3 = 340 + 51 = 391. Verificado.\n",
            $thinking
        );

        // 2) message: SOLO el contenido visible
        $content = collect($this->events)->filter(fn ($e) => $e->type === 'message.delta')
            ->map(fn ($e) => $e->payload['content'])->implode('');
        $this->assertSame("\nStep by step: 391.\n\nLa respuesta es 391 (verificada).", $content);
        $this->assertStringNotContainsString('Let me verify', $content, 'el razonamiento no se funde en el texto');

        // 3) tools del gateway
        $tool = collect($this->events)->first(fn ($e) => $e->type === 'tool.start');
        $this->assertSame('terminal', $tool->payload['tool']);
        $this->assertSame('python3 -c "print(17 * 23)"', $tool->payload['args']['label']);

        // 4) el final lleva el razonamiento completo, separado (el contenido
        //    llega trimado: el adapter no deja los finales con lineas en blanco
        //    en los extremos; los deltas en vivo conservan lo bruto)
        $final = collect($this->events)->first(fn ($e) => $e->type === 'message.final');
        $this->assertNotNull($final);
        $this->assertSame(trim("\nStep by step: 391.\n\nLa respuesta es 391 (verificada)."), $final->payload['content']);
        $this->assertSame(trim($thinking), $final->payload['thinking']);

        // y el sink lo asento en la meta del mensaje de chat
        $row = \App\Models\ChatMessage::where('agent_id', $agent->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($final->payload['thinking'], $row->meta['thinking'] ?? null);

        // el agente queda idle tras el turno
        $this->assertSame('idle', $agent->refresh()->status->value);
    }

    /**
     * Model Proxy + mergeReasoning: si el modelo SOLO pensó, el proxy
     * fusiona el razonamiento en content; el SSE lleva el MISMO texto por
     * `content` y `reasoning_content`. El final deduplica (solo texto).
     */
    public function test_merged_reasoning_is_not_doubled_in_final(): void
    {
        $agent = $this->agent();

        $sse = 'data: '.json_encode(['choices' => [['delta' => ['reasoning_content' => 'Solo pensé esto.'], 'finish_reason' => null]]])."\n\n"
            . 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Solo pensé esto.'], 'finish_reason' => null]]])."\n\n"
            . 'data: '.json_encode(['choices' => [['delta' => new \stdClass, 'finish_reason' => 'stop']]])."\n\n"
            . "data: [DONE]\n\n";

        Http::fake(fn (Request $request) => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']));

        $this->adapter->sendUserMessage($agent, 'piensa', 1);

        $final = collect($this->events)->first(fn ($e) => $e->type === 'message.final');
        $this->assertNotNull($final);
        $this->assertSame('Solo pensé esto.', $final->payload['content']);
        $this->assertSame('', $final->payload['thinking'], 'texto y razonamiento identicos: se deja solo en el texto');
    }

    /** Sin razonamiento: thinking vacio en el final (limitacion registrada). */
    public function test_turn_without_reasoning_has_empty_thinking(): void
    {
        $agent = $this->agent();

        $sse = 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Listo.'], 'finish_reason' => null]]])."\n\n"
            . 'data: '.json_encode(['choices' => [['delta' => new \stdClass, 'finish_reason' => 'stop']]])."\n\n"
            . "data: [DONE]\n\n";

        Http::fake(fn (Request $request) => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']));

        $this->adapter->sendUserMessage($agent, 'ok', 1);

        $final = collect($this->events)->first(fn ($e) => $e->type === 'message.final');
        $this->assertSame('Listo.', $final->payload['content']);
        $this->assertSame('', $final->payload['thinking']);
    }
}

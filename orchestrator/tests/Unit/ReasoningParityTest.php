<?php

namespace Tests\Unit;

use App\Agents\AgentEvent;
use App\Agents\ClaudeAdapter;
use App\Agents\CodexAdapter;
use App\Agents\EventSink;
use App\Agents\HermesAdapter;
use App\Agents\OpenCodeAdapter;
use App\Agents\PiAdapter;
use App\Models\Agent;
use App\Models\AgentToken;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use App\Support\DockerClient;
use App\Support\ProcessStreams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Paridad de razonamiento entre los 5 runtimes (interactive-agent-chat 2.7,
 * escenario *Reasoning parity* de agent-runtimes): para cada adapter, un
 * «turno con razonamiento + texto» (con los shapes REALES de cada superficie,
 * verificados en los spikes) debe producir el MISMO contrato normalizado:
 *
 *   - `thinking.delta` {run_id, content} en streaming (nunca vacío en el turno)
 *   - `message.delta`  {run_id, content} que NO contiene el razonamiento
 *   - `message.final`  {run_id, content, thinking} con el razonamiento
 *     SEPARADO del contenido (la pipeline recibe dos flujos; la UI pinta la
 *     sección «Razonamiento» aparte del texto)
 *
 * El CONTENIDO es distinto por runtime (cada modelo/runtime razona a su
 * manera); el contrato que se afirma es el de forma y separación.
 */
class ReasoningParityTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected EventSink $sink;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->dir = sys_get_temp_dir().'/taap-parity-'.uniqid();
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

    /** Escribe una linea en el `out` simulado (transporte RPC/stdio/SSE). */
    protected function out(array|string $obj): void
    {
        $line = is_string($obj) ? $obj : json_encode($obj);
        file_put_contents($this->dir.'/rpc/out', $line."\n", FILE_APPEND);
    }

    protected function makeAgent(string $runtime): Agent
    {
        $user = User::factory()->create();
        $provider = ModelProvider::factory()->create(['type' => 'openai', 'created_by' => $user->id]);
        $model = Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => 'parity-model',
            'name' => 'Parity',
        ]);
        $agent = Agent::factory()->create([
            'owner_id' => $user->id,
            'runtime' => $runtime,
            'status' => 'starting',
            'container_name' => "taap-agent-{$runtime}-parity",
            'container_id' => 'cid-parity',
            'model_id' => $model->id,
        ]);
        AgentToken::issueFor($agent);

        return $agent;
    }

    protected function dockerMock(): DockerClient
    {
        $docker = $this->createMock(DockerClient::class);
        $docker->method('rpcDir')->willReturn($this->dir.'/rpc');
        $docker->method('status')->willReturn('running');
        $docker->method('coprocessAlive')->willReturn(true);
        $docker->method('startedAt')->willReturn('t0');
        $streams = $this->createMock(ProcessStreams::class);
        $docker->method('attachReader')->willReturn($streams);
        $docker->method('startWriter')->willReturn($streams);
        $docker->method('killCoprocess');

        return $docker;
    }

    protected function pump(\App\Agents\AgentRuntimeAdapter $adapter, Agent $agent): array
    {
        $events = $adapter->drain($agent);
        foreach ($events as $event) {
            $this->sink->dispatch($agent->refresh(), $event);
        }

        return $events;
    }

    /**
     * El contrato de paridad (el mismo para los 5 runtimes):
     *  1. hay thinking.delta, todos con shape {run_id, content} y run_id no nulo;
     *  2. el final lleva thinking no vacío SEPARADO del contenido;
     *  3. el razonamiento no aparece fundido en el texto (deltas ni final);
     *  4. el flujo thinking completo coincide con el thinking del final
     *     (lo que se vio en vivo es lo que queda en la historia).
     *
     * @param  list<AgentEvent>  $events
     */
    protected function assertParity(array $events): void
    {
        $deltas = collect($events)->filter(fn (AgentEvent $e) => $e->type === 'thinking.delta')->values();
        $this->assertTrue($deltas->isNotEmpty(), 'el turno debe producir thinking.delta');
        foreach ($deltas as $e) {
            $this->assertSame(['run_id', 'content'], array_keys($e->payload), 'shape de thinking.delta');
            $this->assertNotSame(null, $e->payload['run_id'], 'thinking.delta debe ir ligado al run');
            $this->assertIsString($e->payload['content']);
        }

        $finals = collect($events)->filter(fn (AgentEvent $e) => $e->type === 'message.final')->values();
        $this->assertTrue($finals->isNotEmpty(), 'el turno debe asentar un message.final');
        $final = $finals->last();
        $content = (string) $final->payload['content'];
        $thinking = (string) ($final->payload['thinking'] ?? '');
        $this->assertNotSame('', $content, 'el final debe tener contenido visible');
        $this->assertNotSame('', $thinking, 'el final debe llevar el razonamiento');
        $this->assertStringNotContainsString($thinking, $content, 'el razonamiento no se funde en el contenido final');
        $this->assertStringNotContainsString(substr($thinking, 0, 12), $content, 'ni un prefijo del razonamiento cae en el contenido');

        // Lo que se vio en vivo (los deltas) = lo que queda en la historia (el final)
        $this->assertSame(trim($thinking), trim($deltas->map(fn (AgentEvent $e) => $e->payload['content'])->implode('')),
            'el flujo thinking emitido debe coincidir con el thinking del final');

        // El texto en streaming nunca contiene el razonamiento
        $text = collect($events)->filter(fn (AgentEvent $e) => $e->type === 'message.delta')
            ->map(fn (AgentEvent $e) => $e->payload['content'])->implode('');
        if ($text !== '') {
            $this->assertStringNotContainsString(substr($thinking, 0, 12), $text, 'el razonamiento no cae en message.delta');
        }
    }

    /* ── Pi (RPC stdio; shapes de pi-coding-agent 1.6.0) ── */

    public function test_pi_parity(): void
    {
        $agent = $this->makeAgent('pi');
        $adapter = new PiAdapter($this->dockerMock(), $this->sink);
        $adapter->attach($agent);

        $this->out(['type' => 'agent_start', 'id' => 'parity-pi']);
        $this->out(['type' => 'message_update', 'assistantMessageEvent' => ['type' => 'thinking_delta', 'contentIndex' => 0, 'delta' => 'Razonando en el turno: debo verificar antes. ']]);
        $this->out(['type' => 'message_update', 'assistantMessageEvent' => ['type' => 'text_delta', 'contentIndex' => 1, 'delta' => 'Verificado. ']]);
        $this->out(['type' => 'message_update', 'assistantMessageEvent' => ['type' => 'text_delta', 'contentIndex' => 1, 'delta' => 'Todo ok.']]);
        $this->out([
            'type' => 'message_end',
            'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => 'Razonando en el turno: debo verificar antes. '],
                ['type' => 'text', 'text' => 'Verificado. Todo ok.'],
            ]],
        ]);
        $this->out(['type' => 'agent_settled', 'aborted' => false]);

        $this->assertParity($this->pump($adapter, $agent));
    }

    /* ── Claude (sidecar: protocolo TAAP v1 por stdio) ── */

    public function test_claude_parity(): void
    {
        $agent = $this->makeAgent('claude');
        $adapter = new ClaudeAdapter($this->dockerMock(), $this->sink);
        $adapter->attach($agent);

        $at = now()->toIso8601String();
        $this->out(['protocol' => 1, 'type' => 'thinking.delta', 'payload' => ['run_id' => 'parity-claude', 'content' => 'Razonando en el turno: debo verificar antes. '], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'message.delta', 'payload' => ['run_id' => 'parity-claude', 'content' => 'Verificado. '], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'message.delta', 'payload' => ['run_id' => 'parity-claude', 'content' => 'Todo ok.'], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'message.final', 'payload' => [
            'run_id' => 'parity-claude',
            'content' => 'Verificado. Todo ok.',
            'thinking' => 'Razonando en el turno: debo verificar antes. ',
        ], 'at' => $at]);
        $this->out(['protocol' => 1, 'type' => 'run.end', 'payload' => ['run_id' => 'parity-claude', 'ok' => true], 'at' => $at]);

        $this->assertParity($this->pump($adapter, $agent));
    }

    /* ── Codex (app-server 0.171; fixture REAL del spike 2.1) ── */

    public function test_codex_parity(): void
    {
        $agent = $this->makeAgent('codex');
        $adapter = new CodexAdapter($this->dockerMock(), $this->sink);
        $adapter->attach($agent);

        $fixture = base_path('../openspec/changes/interactive-agent-chat/fixtures/spike-codex-reasoning.jsonl');
        $this->assertFileExists($fixture);
        foreach (file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $this->out(trim($line));
        }

        $events = $this->pump($adapter, $agent);

        // El fixture es un stream MUESTREADO (los deltas intermedios se
        // omitieron al capturarlo): el flujo visto es un subconjunto del
        // razonamiento completo; la paridad se afirma sobre el final.
        $final = collect($events)->filter(fn (AgentEvent $e) => $e->type === 'message.final')->last();
        $this->assertNotNull($final);
        $thinking = (string) $final->payload['thinking'];
        $content = (string) $final->payload['content'];
        $this->assertNotSame('', $thinking, 'el fixture debe producir razonamiento en el final');
        $this->assertStringNotContainsString(substr($thinking, 0, 12), $content, 'razonamiento separado del contenido');

        $deltas = collect($events)->filter(fn (AgentEvent $e) => $e->type === 'thinking.delta');
        $this->assertTrue($deltas->isNotEmpty(), 'los summaryTextDelta del fixture -> thinking.delta');
        foreach ($deltas as $e) {
            $this->assertSame(['run_id', 'content'], array_keys($e->payload));
            $this->assertNotSame(null, $e->payload['run_id']);
        }
    }

    /* ── OpenCode (API 1.19.6: partes reasoning + text) ── */

    public function test_opencode_parity(): void
    {
        $agent = $this->makeAgent('opencode');
        $sid = 'ses_parity_opencode';
        $agent->forceFill(['config' => ['session_id' => $sid]])->saveQuietly();
        $agent->refresh();

        $adapter = new OpenCodeAdapter($this->dockerMock(), $this->sink);
        $b = 'http://taap-agent-opencode-parity:4096';
        Http::fake([
            $b.'/api/health' => Http::response(['healthy' => true]),
            $b.'/session' => Http::response(['id' => $sid, 'title' => 't']),
            $b.'/session/*' => Http::response(['id' => $sid]),
            $b.'/api/*' => Http::response(null, 204),
            $b.'/permission' => Http::response([]),
            $b.'/*' => Http::response([]),
        ]);

        $adapter->attach($agent);
        $adapter->writeMessage($agent, 'hola');

        $p = fn (array $extra) => file_put_contents($this->dir.'/rpc/out', 'data: '.json_encode($extra)."\n", FILE_APPEND);
        $part = fn (string $id, string $type, string $text, string $msg) => [
            'id' => $id, 'sessionID' => $sid, 'messageID' => $msg, 'type' => $type, 'text' => $text, 'time' => ['start' => 1],
        ];

        $p(['id' => 'e1', 'type' => 'message.updated', 'properties' => ['sessionID' => $sid, 'info' => ['id' => 'msg_p', 'role' => 'assistant']]]);
        $p(['id' => 'e2', 'type' => 'message.part.updated', 'properties' => ['sessionID' => $sid, 'part' => $part('prt_r', 'reasoning', '', 'msg_p')]]);
        $p(['id' => 'e3', 'type' => 'message.part.delta', 'properties' => ['sessionID' => $sid, 'messageID' => 'msg_p', 'partID' => 'prt_r', 'field' => 'text', 'delta' => 'Razonando en el turno: debo verificar antes. ']]);
        $p(['id' => 'e4', 'type' => 'message.part.updated', 'properties' => ['sessionID' => $sid, 'part' => $part('prt_r', 'reasoning', 'Razonando en el turno: debo verificar antes. ', 'msg_p')]]);
        $p(['id' => 'e5', 'type' => 'message.part.updated', 'properties' => ['sessionID' => $sid, 'part' => $part('prt_t', 'text', '', 'msg_p')]]);
        $p(['id' => 'e6', 'type' => 'message.part.delta', 'properties' => ['sessionID' => $sid, 'messageID' => 'msg_p', 'partID' => 'prt_t', 'field' => 'text', 'delta' => 'Verificado. Todo ok.']]);
        $p(['id' => 'e7', 'type' => 'message.part.updated', 'properties' => ['sessionID' => $sid, 'part' => $part('prt_t', 'text', 'Verificado. Todo ok.', 'msg_p')]]);
        $p(['id' => 'e8', 'type' => 'session.status', 'properties' => ['sessionID' => $sid, 'status' => ['type' => 'idle']]]);

        $this->assertParity($this->pump($adapter, $agent));
    }

    /* ── Hermes (gateway SSE: reasoning_content + content) ── */

    public function test_hermes_parity(): void
    {
        $agent = $this->makeAgent('hermes');

        $chunk = fn (array|\stdClass $delta, ?string $finish) => 'data: '.json_encode([
            'id' => 'parity-hermes', 'object' => 'chat.completion.chunk', 'created' => 1, 'model' => 'hermes-agent',
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
        ], JSON_UNESCAPED_UNICODE)."\n\n";

        $sse = $chunk(['role' => 'assistant'], null)
            . $chunk(['reasoning_content' => 'Razonando en el turno: debo verificar antes. '], null)
            . $chunk(['content' => 'Verificado. Todo ok.'], null)
            . $chunk(new \stdClass, 'stop')
            . "data: [DONE]\n\n";

        Http::fake(fn (Request $request) => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']));

        // Un sink que registra: los eventos de Hermes los emite el adapter
        // el mismo (no hay drain): se interceptan aqui.
        $recorder = new class extends EventSink
        {
            /** @var list<AgentEvent> */
            public array $events = [];

            public function dispatch(Agent $agent, AgentEvent $event): void
            {
                $this->events[] = $event;
                parent::dispatch($agent, $event);
            }
        };
        $adapter = new HermesAdapter($this->dockerMock(), $recorder);
        $adapter->sendUserMessage($agent, 'hola');

        $events = $recorder->events;
        // el thinking llega en un solo delta; el content es otro
        $this->assertSame('Razonando en el turno: debo verificar antes. ', collect($events)
            ->filter(fn (AgentEvent $e) => $e->type === 'thinking.delta')
            ->map(fn (AgentEvent $e) => $e->payload['content'])->implode(''));
        $this->assertParity($events);
    }
}

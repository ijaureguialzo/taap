<?php

namespace App\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Support\DockerClient;
use Illuminate\Support\Str;

/**
 * Adaptador Pi (superficie nativa: `pi --mode rpc`, JSONL por stdio).
 *
 * La maquinaria de la superficie (coprocesos, drain, watchdog, cola) vive
 * en {@see StdioRpcAdapter}; este aportara el dialecto RPC de pi-coding-agent.
 * Mapa de eventos (ver docs/rpc.md y json.md de pi-coding-agent):
 *   agent_start            -> run.start
 *   agent_settled{aborted}-> run.end
 *   message_update (delta) -> message.delta
 *   message_end (assistant)-> message.final
 *   tool_execution_start   -> tool.start
 *   tool_execution_end     -> tool.result
 *   extension_ui_request   -> approval.request (dialogos)
 */
class PiAdapter extends StdioRpcAdapter
{
    public function __construct(
        DockerClient $docker,
        EventSink $sink,
    ) {
        parent::__construct($docker, $sink);
    }

    protected function name(): string
    {
        return 'Pi';
    }

    /** Sondeo get_state del modo RPC (la respuesta marca "ready" en el sink). */
    public function probePayload(Agent $agent): array
    {
        return ['type' => 'get_state', 'id' => 'taap-ready-'.$agent->id.'-'.time()];
    }

    public function sendUserMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        if (! $this->ensureAttached($agent)) {
            throw new \RuntimeException('No hay sesion RPC viva para el agente');
        }

        // Si el agente está en plena corrida, cola el mensaje (FIFO)
        $extra = $agent->status === AgentStatus::Working
            ? ['streamingBehavior' => 'followUp']
            : [];

        $this->writePrompt($agent, $content, $extra);
    }

    /**
     * Envio de mensaje sin adjuntar: escribe el prompt en el IPC (el bucle
     * writer del bridge lo entrega al FIFO). El worker de cola no spawnea
     * coprocesos: evita writer duplicados.
     */
    public function writeMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        // Sin attach aqui: el bridge es el unico que spawnea coprocesos
        // (evita writer duplicados por carrera). El prompt queda en el
        // fichero y el bucle writer del bridge lo entrega al FIFO.
        if (! isset($this->sessions[$agent->id])) {
            $dir = $this->docker->rpcDir($agent->id);
            if (! is_dir($dir)) {
                throw new \RuntimeException('No hay area RPC para el agente');
            }
        }

        $extra = $agent->status->value === 'working'
            ? ['streamingBehavior' => 'followUp']
            : [];

        $this->writePrompt($agent, $content, $extra);
    }

    public function resolveApproval(Agent $agent, string $approvalId, bool $approved, ?string $note = null): void
    {
        // El shape cubre los tres dialogos del modo RPC: confirm lee
        // `confirmed`, select/input leen `value`. Sin `confirmed`, un
        // confirm aprobado se resolveria como denegado.
        $this->write($agent, [
            'id' => $approvalId,
            'type' => 'extension_ui_response',
            'confirmed' => $approved,
            'value' => $note ?? ($approved ? 'ok' : 'denied'),
            'cancelled' => false,
        ]);

        $this->sink->dispatch($agent, AgentEvent::approvalResolved(null, $approvalId, $approved, $note));
    }

    public function applyConfig(Agent $agent): void
    {
        // La config nativa (models.json, mcp.json, agent.env) se reescribe
        // por el renderer; un restart del contenedor la aplica y la sesion
        // se reanuda con el mismo --session-id.
        app(AgentManager::class)->restart($agent);
        $this->attach($agent);
    }

    /**
     * Dialecto RPC de Pi -> AgentEvent.
     *
     * @param  array<string, mixed>  $event
     * @return list<AgentEvent>
     */
    protected function normalize(array $event, int $agentId): array
    {
        $type = $event['type'] ?? '';
        // Pi no lleva run_id en sus eventos RPC: se correlaciona localmente.
        // agent_start abre una corrida (id nuevo) y agent_settled la cierra;
        // todo lo intermedio hereda el id.
        if ($type === 'agent_start') {
            $runId = Str::uuid();
            $this->sessions[$agentId]['run_id'] = $runId;
        } else {
            $runId = $this->sessions[$agentId]['run_id'] ?? $event['run_id'] ?? null;
        }
        $out = [];

        switch ($type) {
            case 'response':
                if (($event['command'] ?? '') === 'get_state' && ($event['success'] ?? false)) {
                    $out[] = AgentEvent::statusChange('ready');
                } elseif (($event['success'] ?? false) === false) {
                    $out[] = AgentEvent::error((string) ($event['error'] ?? 'comando RPC falló'));
                }
                break;

            case 'agent_start':
                $out[] = AgentEvent::runStart((string) ($runId ?? Str::uuid()));
                $out[] = AgentEvent::statusChange('working');
                break;

            case 'agent_settled':
                $out[] = AgentEvent::runEnd((string) ($runId ?? 'run'), ! ($event['aborted'] ?? false));
                $out[] = AgentEvent::statusChange('idle');
                $this->sessions[$agentId]['run_id'] = null;
                break;

            case 'message_update':
                $inner = $event['assistantMessageEvent'] ?? [];
                if (($inner['type'] ?? '') === 'text_delta') {
                    $out[] = AgentEvent::messageDelta($runId, (string) ($inner['delta'] ?? ''));
                } elseif (isset($inner['delta'])) {
                    $out[] = AgentEvent::messageDelta($runId, (string) $inner['delta']);
                }
                break;

            case 'message_end':
                $message = $event['message'] ?? [];
                if (($message['role'] ?? '') === 'assistant') {
                    $content = $this->assistantText($message);
                    if ($content !== '') {
                        $out[] = AgentEvent::messageFinal($runId, $content);
                    }
                }
                break;

            case 'tool_execution_start':
                $out[] = AgentEvent::toolStart(
                    $runId,
                    (string) ($event['toolName'] ?? 'tool'),
                    (array) ($event['args'] ?? [])
                );
                break;

            case 'tool_execution_update':
                $partial = $event['partialResult']['content'][0]['text'] ?? null;
                if ($partial !== null) {
                    $out[] = AgentEvent::toolResult($runId, (string) ($event['toolName'] ?? 'tool'), true, (string) $partial);
                }
                break;

            case 'tool_execution_end':
                $text = $event['result']['content'][0]['text'] ?? '';
                $out[] = AgentEvent::toolResult(
                    $runId,
                    (string) ($event['toolName'] ?? 'tool'),
                    ! ($event['isError'] ?? false),
                    (string) $text
                );
                break;

            case 'extension_ui_request':
                // confirm: {title, message}; select/input: {title, options|placeholder}
                $title = (string) ($event['title'] ?? '');
                $body = trim((string) ($event['message'] ?? $event['prompt'] ?? ''));
                $label = $title !== '' && $body !== '' ? $title.': '.$body
                    : ($title !== '' ? $title : ($body !== '' ? $body : 'Se requiere una decision'));
                $out[] = AgentEvent::approvalRequest(
                    $runId,
                    (string) ($event['id'] ?? Str::uuid()),
                    $label,
                    (array) ($event['options'] ?? [])
                );
                break;

            default:
                // compaction, turn_*, session, etc.: se registran crudos
                if (in_array($type, ['compaction_start', 'compaction_end', 'auto_retry_start', 'auto_retry_end', 'extension_error'], true)) {
                    $out[] = AgentEvent::error("{$type}");
                }
        }

        return $out;
    }

    /** @param array<string, mixed> $message */
    protected function assistantText(array $message): string
    {
        $content = $message['content'] ?? '';
        if (is_string($content)) {
            return $content;
        }

        $parts = [];
        foreach ((array) $content as $block) {
            if (is_array($block) && isset($block['text'])) {
                $parts[] = $block['text'];
            }
        }

        return implode('', $parts);
    }
}

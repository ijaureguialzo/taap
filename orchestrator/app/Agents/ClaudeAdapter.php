<?php

namespace App\Agents;

use App\Models\Agent;
use App\Support\DockerClient;
use Illuminate\Support\Facades\Log;

/**
 * Adaptador Claude Code (superficie nativa: sidecar Node con Claude Agent
 * SDK, JSONL por stdio).
 *
 * La normalizacion vive en el sidecar (agents/claude/sidecar): emite el
 * shape EXACTO de AgentEvent mas `protocol: 1` (diseño D1: el SDK evoluciona
 * rapido; un bump del SDK = rebuild de la imagen, nunca del orquestador).
 * Este adaptador es un pase-through VALIDADO: comprueba version/tipo/campos
 * y construye el AgentEvent; si una linea no encaja, se loguea y se descarta
 * (nunca un evento roto en la pipeline).
 *
 * Maquinaria de la superficie (coprocesos, drain, watchdog, cola):
 * {@see StdioRpcAdapter}.
 */
class ClaudeAdapter extends StdioRpcAdapter
{
    /** Protocolo TAAP v1 (debe coincidir con el sidecar). */
    protected const PROTOCOL = 1;

    /** Tipos de evento del protocolo v1 (los diez de AgentEvent). */
    protected const EVENT_TYPES = [
        'message.delta', 'message.final', 'tool.start', 'tool.result',
        'approval.request', 'approval.resolved', 'status.change',
        'run.start', 'run.end', 'error',
    ];

    public function __construct(
        DockerClient $docker,
        EventSink $sink,
    ) {
        parent::__construct($docker, $sink);
    }

    protected function name(): string
    {
        return 'Claude';
    }

    /** Sondeo: el sidecar responde con `status.ready` (o waiting-approval). */
    public function probePayload(Agent $agent): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'id' => 'taap-ready-'.$agent->id.'-'.time(),
            'type' => 'get_state',
        ];
    }

    public function sendUserMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        if (! $this->ensureAttached($agent)) {
            throw new \RuntimeException('No hay sesion RPC viva para el agente');
        }

        // El sidecar cola en FIFO los prompts que llegan con un turno en
        // vuelo: no hay campo extra de follow-up en el protocolo v1.
        $this->writePrompt($agent, $content);
    }

    /**
     * Worker de cola: escribe el prompt en el IPC sin spawneaar coprocesos
     * (el bridge es el unico que los spawnea; evita writer duplicados).
     */
    public function writeMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        if (! isset($this->sessions[$agent->id])) {
            $dir = $this->docker->rpcDir($agent->id);
            if (! is_dir($dir)) {
                throw new \RuntimeException('No hay area RPC para el agente');
            }
        }

        $this->writePrompt($agent, $content);
    }

    /** El prompt del protocolo v1 lleva la version (la base no la inyecta). */
    protected function writePrompt(Agent $agent, string $content, array $extra = []): void
    {
        parent::writePrompt($agent, $content, ['protocol' => self::PROTOCOL, ...$extra]);
    }

    public function resolveApproval(Agent $agent, string $approvalId, bool $approved, ?string $note = null): void
    {
        // La UI envia la opcion elegida en `note` (`once`/`always`); un
        // texto libre se pasa como nota y la opcion se deduce del booleano.
        $option = in_array($note, ['once', 'always', 'deny'], true)
            ? $note
            : ($approved ? 'once' : 'deny');
        $note = in_array($note, ['once', 'always', 'deny'], true) ? null : $note;

        $this->write($agent, [
            'protocol' => self::PROTOCOL,
            'id' => $approvalId,
            'type' => 'approval_response',
            'approval_id' => $approvalId,
            'approved' => $approved,
            'option' => $option,
            'note' => $note,
        ]);

        $this->sink->dispatch($agent, AgentEvent::approvalResolved(null, $approvalId, $approved, $note));
    }

    public function applyConfig(Agent $agent): void
    {
        // El sidecar re-lee /config/claude.json en cada turno (config_apply:
        // next-turn): basta con re-renderizar el plano; SIN reinicio, la
        // sesion la reanuda el SDK con `resume`.
        app(ConfigRenderer::class)->render($agent);
    }

    /**
     * Pase-through validado del protocolo v1 -> AgentEvent.
     *
     * @param  array<string, mixed>  $event
     * @return list<AgentEvent>
     */
    protected function normalize(array $event, int $agentId): array
    {
        if (($event['protocol'] ?? null) !== self::PROTOCOL) {
            Log::debug('Claude: evento sin version v1, descartado: '.substr(json_encode($event), 0, 200));

            return [];
        }

        $type = $event['type'] ?? '';
        if (! in_array($type, self::EVENT_TYPES, true)) {
            Log::debug('Claude: tipo de evento desconocido «'.$type.'»');

            return [];
        }

        $p = $event['payload'] ?? [];
        if (! is_array($p)) {
            return [];
        }

        $at = isset($event['at']) ? $this->parseAt((string) $event['at']) : null;

        $runId = isset($p['run_id']) && is_string($p['run_id']) && $p['run_id'] !== '' ? $p['run_id'] : null;

        $factory = match ($type) {
            'message.delta' => AgentEvent::messageDelta($runId, (string) ($p['content'] ?? '')),
            'message.final' => AgentEvent::messageFinal($runId, (string) ($p['content'] ?? ''), (string) ($p['thinking'] ?? '')),
            'tool.start' => AgentEvent::toolStart($runId, (string) ($p['tool'] ?? 'tool'), (array) ($p['args'] ?? [])),
            'tool.result' => AgentEvent::toolResult($runId, (string) ($p['tool'] ?? 'tool'), (bool) ($p['ok'] ?? true), (string) ($p['summary'] ?? '')),
            'approval.request' => AgentEvent::approvalRequest($runId, (string) ($p['id'] ?? ''), (string) ($p['prompt'] ?? ''), (array) ($p['options'] ?? [])),
            'approval.resolved' => AgentEvent::approvalResolved($runId, (string) ($p['id'] ?? ''), (bool) ($p['approved'] ?? false), isset($p['note']) && $p['note'] !== '' ? (string) $p['note'] : null),
            'status.change' => AgentEvent::statusChange((string) ($p['status'] ?? ''), isset($p['detail']) ? (string) $p['detail'] : null),
            'run.start' => AgentEvent::runStart((string) ($p['run_id'] ?? '')),
            'run.end' => AgentEvent::runEnd((string) ($p['run_id'] ?? ''), (bool) ($p['ok'] ?? true)),
            'error' => AgentEvent::error((string) ($p['message'] ?? 'error')),
        };

        if ($at !== null) {
            $factory->at = $at;
        }

        return [$factory];
    }

    protected function parseAt(string $iso): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return null;
        }
    }
}

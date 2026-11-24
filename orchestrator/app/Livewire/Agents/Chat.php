<?php

namespace App\Livewire\Agents;

use App\Agents\ChatService;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentEventRow;
use App\Models\ChatMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Cliente de chat (web): historial + streaming en vivo por el canal
 * Reverb `agent.{id}`.
 *
 * Streaming gestionado por el cliente (interactive-agent-chat 3.1): este
 * componente solo mantiene la EXISTENCIA del bloque (`streaming`); el
 * texto/razonamiento lo pinta el JS local en contenedores `wire:ignore`
 * (sin round-trip por token). Al `message.final` la burbuja se asienta
 * desde la BD (historial + secciones Razonamiento/Actividad).
 */
class Chat extends Component
{
    public Agent $agent;

    public string $content = '';

    /**
     * Existencia del bloque en streaming: `null` = sin bloque, `'live'` =
     * el blade renderiza el esqueleto. El TEXTO no vive aqui: el JS aplica
     * los deltas localmente (3.1: no hay round-trip por token); el servidor
     * solo crea el esqueleto (run.start / primer delta visto) y lo cierra
     * (message.final).
     */
    public ?string $streaming = null;

    /**
     * Progreso del run actual (ultima herramienta/estado) para el indicador.
     *
     * @var array{kind: string, label: string, detail?: ?string}|null
     */
    public ?array $activity = null;

    /** id de la aprobacion pendiente (approval.request). */
    public ?string $pendingApproval = null;

    /**
     * Detalle de la aprobacion pendiente para la tarjeta: prompt legible y
     * opciones (Claude: aprobar / «permitir siempre» / denegar).
     *
     * @var array{prompt: ?string, options: list<array{value: string, label: string}>}|null
     */
    public ?array $pendingApprovalInfo = null;

    /**
     * Estado en vivo por el canal (4.2): gobierna el disabled del input y
     * el aviso de ayuda; fallback al estado del modelo (mount/recarga).
     */
    public ?string $liveStatus = null;

    public function mount(Agent $agent): void
    {
        $this->agent = $agent;
        $this->liveStatus = $agent->status->value;

        // 3.4: si el agente pidió aprobacion con la web cerrada, la tarjeta
        // aparece al abrir (nunca una espera sin forma de responder).
        $this->loadPendingApprovals();
    }

    /**
     * Acepta el contenido explicito del cliente (Enter/click via JS) para
     * que no dependa del round-trip de `wire:model` (debounce 400ms):
     * pulsar Enter justo tras teclear no debe perder el mensaje.
     */
    public function send(?string $content = null): void
    {
        $content = trim($content ?? $this->content);
        if ($content === '') {
            return;
        }

        $user = Auth::user();
        if (! $user->isAdmin() && $user->id !== $this->agent->owner_id) {
            return;
        }

        try {
            app(ChatService::class)->send($this->agent, $user, $content);
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->content = '';
        // Esqueleto inmediato (el run.start lo confirma por canal; si el
        // agente esta detenido, el arrancarlo lo abre igualmente).
        $this->streaming = 'live';
        $this->activity = null;

        // JS lo usa para vaciar el input enfocado (Livewire no pisa el DOM
        // de un input enfocado: sin esto el texto viejo seguiria visible
        // tras el envío).
        $this->dispatch('message-sent');
    }

    public function approve(bool $approved, ?string $note = null): void
    {
        $approvalId = $this->pendingApproval;
        if (! $approvalId) {
            return;
        }

        $this->pendingApproval = null;
        $this->pendingApprovalInfo = null;

        app(ChatService::class)->resolveApproval(
            $this->agent,
            Auth::user(),
            $approvalId,
            $approved,
            $note,
        );
    }

    /**
     * Recibe los eventos del canal `agent.{id}` (via JS/Echo).
     *
     * 3.1: `message.delta`/`thinking.delta` NO cambian estado del servidor
     * (el JS los aplica localmente); solo crean el esqueleto si se perdio
     * el `run.start` (la pagina se abrio a mitad del turno): un cambio de
     * estado, no uno por token.
     *
     * @param  array<string, mixed>  $payload
     */
    public function agentEvent(array $payload): void
    {
        if (($payload['agent_id'] ?? null) != $this->agent->id) {
            return;
        }

        $type = $payload['type'] ?? '';
        $data = $payload['payload'] ?? [];

        switch ($type) {
            case 'message.delta':
            case 'thinking.delta':
                if ($this->streaming === null) {
                    $this->streaming = 'live';
                }
                break;

            case 'message.final':
                // El mensaje ya esta persistido; el historial (y sus
                // secciones Razonamiento/Actividad) se repinta.
                $this->streaming = null;
                $this->activity = null;
                // El turno ha terminado: la tarjeta deja de serlo aunque
                // el status.change(idle) llegue un milisegundo mas tarde
                // (un turno no puede terminar con una aprobacion activa).
                $this->pendingApproval = null;
                $this->pendingApprovalInfo = null;
                $this->refreshHistory();
                break;

            case 'run.start':
                if ($this->streaming === null) {
                    $this->streaming = 'live';
                }
                $this->activity = ['kind' => 'run', 'label' => __('chat.run')];
                break;

            case 'run.end':
                $this->activity = null;
                break;

            case 'tool.start':
                $args = (array) ($data['args'] ?? []);
                $this->activity = [
                    'kind' => 'tool',
                    'label' => (string) ($data['tool'] ?? 'tool'),
                    'detail' => $this->toolDetail($args),
                ];
                break;

            // El tipo normalizado es `tool.result` (AgentEvent::toolResult);
            // el listener anterior escuchaba `tool.end` (inexistente) y el
            // indicador de herramienta vivia para siempre.
            case 'tool.result':
                $this->activity = null;
                break;

            case 'error':
                $this->activity = [
                    'kind' => 'error',
                    'label' => (string) ($data['message'] ?? __('chat.error')),
                ];
                break;

            case 'approval.request':
                $this->pendingApproval = isset($data['id']) ? (string) $data['id'] : null;
                $this->pendingApprovalInfo = [
                    'prompt' => isset($data['prompt']) ? (string) $data['prompt'] : null,
                    'options' => $this->approvalOptions($data),
                ];
                break;

            case 'approval.resolved':
                if (($data['id'] ?? null) === $this->pendingApproval) {
                    $this->pendingApproval = null;
                    $this->pendingApprovalInfo = null;
                }
                break;

            case 'status.change':
                $this->liveStatus = isset($data['status']) ? (string) $data['status'] : $this->liveStatus;
                // 3.4: el agente empieza a esperar aprobacion y la tarjeta
                // no existe (el request llego con la web cerrada): re-sinc
                // desde lo persistido.
                if (($data['status'] ?? null) === 'waiting-approval' && $this->pendingApproval === null) {
                    $this->loadPendingApprovals();
                }
                break;
        }
    }

    /**
     * 3.4: cargas las aprobaciones NO resueltas (misma semantica NULL que
     * `ChatController::messages`: `payload->resolved != true`).
     */
    public function loadPendingApprovals(): void
    {
        $row = AgentEventRow::where('agent_id', $this->agent->id)
            ->where('type', 'approval.request')
            ->whereNot('payload->resolved', true)
            ->latest('occurred_at')
            ->first();

        if (! $row || ($row->payload['id'] ?? null) === null) {
            $this->pendingApproval = null;
            $this->pendingApprovalInfo = null;

            return;
        }

        $p = $row->payload ?? [];
        $this->pendingApproval = (string) $p['id'];
        $this->pendingApprovalInfo = [
            'prompt' => isset($p['prompt']) ? (string) $p['prompt'] : null,
            'options' => $this->approvalOptions($p),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{value: string, label: string}>
     */
    protected function approvalOptions(array $data): array
    {
        $options = [];
        foreach ((array) ($data['options'] ?? []) as $opt) {
            if (is_array($opt) && isset($opt['value'])) {
                $options[] = [
                    'value' => (string) $opt['value'],
                    'label' => (string) ($opt['label'] ?? $opt['value']),
                ];
            }
        }

        return $options;
    }

    /**
     * Detalle legible del tool.start por dialecto (command/label/…).
     *
     * @param  array<string, mixed>  $args
     */
    protected function toolDetail(array $args): ?string
    {
        $detail = (string) ($args['label'] ?? $args['command'] ?? $args['filePath'] ?? $args['pattern'] ?? $args['url'] ?? $args['query'] ?? '');
        if ($detail === '') {
            return null;
        }

        return mb_strimwidth($detail, 0, 80, '…');
    }

    /**
     * Estado efectivo (4.2): el en vivo por canal gana; sin eventos (mount,
     * recarga) cae al estado persistido del modelo.
     */
    public function effectiveStatus(): AgentStatus
    {
        if ($this->liveStatus !== null) {
            try {
                return AgentStatus::fromString($this->liveStatus);
            } catch (\Throwable) {
                // valor desconocido: fallback
            }
        }

        return $this->agent->status;
    }

    protected function refreshHistory(): void
    {
        // Livewire 4 re-renderiza en cada update (render()); la consulta del
        // historial se ejecuta de nuevo con el mensaje finalizado. (v3 usaba
        // forceRefresh(); ya no existe).
    }

    /** @return array<int, array<string, mixed>> */
    protected function history(): array
    {
        return ChatMessage::where('agent_id', $this->agent->id)
            ->latest('id')
            ->take(200)
            ->get()
            ->reverse()
            ->map(fn (ChatMessage $m) => [
                'id' => $m->id,
                'author' => $m->author,
                'content' => $m->content,
                'at' => $m->created_at?->toIso8601String(),
                'meta' => $m->meta ?? null,
            ])
            ->all();
    }

    /**
     * 3.3: actividad de los turnos asientados (tool/error) en un lote por
     * `run_id` del historial; el blade la pinta colapsada en la burbuja.
     *
     * @param  array<int, array<string, mixed>>  $history
     * @return array<int, list<array{type: string, tool: ?string, ok: bool, summary: string, at: string}>>
     */
    protected function activitiesByRunFor(array $history): array
    {
        $runIds = collect($history)
            ->filter(fn (array $m) => ($m['author'] ?? '') === 'agent' && ! empty($m['meta']['run_id']))
            ->map(fn (array $m) => (string) $m['meta']['run_id'])
            ->unique()
            ->values()
            ->all();

        if ($runIds === []) {
            return [];
        }

        return AgentEventRow::where('agent_id', $this->agent->id)
            ->whereIn('type', ['tool.start', 'tool.result', 'error'])
            ->whereIn('payload->run_id', $runIds)
            ->orderBy('occurred_at')
            ->get()
            ->groupBy(fn (AgentEventRow $row) => (string) ($row->payload['run_id'] ?? ''))
            ->map(fn ($rows) => $rows->map(fn (AgentEventRow $row) => [
                'type' => $row->type,
                'tool' => isset($row->payload['tool']) ? (string) $row->payload['tool'] : null,
                'ok' => $row->type === 'tool.result' ? (bool) ($row->payload['ok'] ?? true) : true,
                'summary' => match ($row->type) {
                    'error' => (string) ($row->payload['message'] ?? ''),
                    'tool.start' => $this->toolDetail($row->payload['args'] ?? []),
                    default => (string) ($row->payload['summary'] ?? ''),
                },
                'at' => $row->occurred_at->toIso8601String(),
            ])->all())
            ->filter(fn (array $lines) => $lines !== [])
            ->all();
    }

    public function render(): View
    {
        $history = $this->history();

        return view('livewire.agents.chat', [
            'history' => $history,
            'activities' => $this->activitiesByRunFor($history),
        ]);
    }
}

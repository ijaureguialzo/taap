<?php

namespace App\Agents;

use App\Broadcasts\AgentEventBroadcast;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentEventRow;
use App\Models\ChatMessage;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Unico punto de entrada de los eventos normalizados (design.md D2).
 *
 * - Persiste la cronologia (agent_events)
 * - Difunde a la Web/iOS por el canal Reverb `agent.{id}`
 * - Efectos de estado: run/agent_start -> working; settled -> idle;
 *   message.final -> ChatMessage; status.change -> agents.status
 */
class EventSink
{
    public function dispatch(Agent $agent, AgentEvent $event): void
    {
        $row = new AgentEventRow([
            'agent_id' => $agent->id,
            'type' => $event->type,
            'payload' => $event->payload,
            'occurred_at' => $event->at,
        ]);

        // Spec (agent-chat): los deltas no se persisten, solo el final
        // (el razonamiento viaja en `meta.thinking` del final, design D1).
        if ($event->type !== 'message.delta' && $event->type !== 'thinking.delta') {
            $row->save();
        }

        // Excepcion: un message.final REEMITIDO (pi lo reemite del
        // historial al re-adjuntar) NO se difunde: el cliente ya lo
        // procesó y un segundo message.final cerraria de nuevo el
        // esqueleto / la tarjeta de aprobacion. Se comprueba ANTES de
        // applySideEffects: una vez persistido, el final propio ya seria
        // el "ultimo" y la comprobacion siempre daría duplicado.
        $finalDuplicate = $event->type === 'message.final'
            && $this->finalIsDuplicate($agent, $event->payload);

        $this->applySideEffects($agent, $row);

        // En cola: el broadcast no debe bloquear el bucle del bridge.
        // Los deltas SÍ se difunden por el canal (interactive-agent-chat D1/
        // D2): la web los aplica localmente sin round-trip por token; los
        // suscriptores por canal son pocos (dueño/admin).
        if (! $finalDuplicate) {
            Bus::dispatch(new BroadcastEvent(new AgentEventBroadcast(
                $agent->id,
                $event->type,
                $event->payload,
                $event->at->format('c'),
            )));
        }
    }

    protected function applySideEffects(Agent $agent, AgentEventRow $row): void
    {
        $payload = $row->payload ?? [];

        switch ($row->type) {
            case 'run.start':
            case 'message.delta':
            case 'thinking.delta':
                if (! $agent->status->isRunning() || $agent->status !== AgentStatus::Working) {
                    $this->setStatus($agent, AgentStatus::Working);
                }
                break;

            case 'run.end':
            case 'status.change':
                $status = $payload['status'] ?? null;
                if ($status) {
                    $map = [
                        // el runtime esta vivo y la sesion responde: "listo"
                        'ready' => AgentStatus::Idle,
                        'idle' => AgentStatus::Idle,
                        'working' => AgentStatus::Working,
                        'waiting-approval' => AgentStatus::WaitingApproval,
                        'error' => AgentStatus::Error,
                        'stopped' => AgentStatus::Stopped,
                        'offline' => AgentStatus::Offline,
                        'starting' => AgentStatus::Starting,
                    ];
                    if (isset($map[$status])) {
                        $this->setStatus($agent, $map[$status]);
                    }
                } elseif ($row->type === 'run.end') {
                    $this->setStatus($agent, AgentStatus::Idle);
                    // Si el turno termina con aprobaciones colgadas (abort),
                    // quedan sin efecto: la tarjeta de la web no debe
                    // reaparecer con una aprobacion de un turno muerto.
                    $this->resolvePendingApprovals($agent, 'run-ended');
                }
                break;

            case 'message.final':
                if (! $this->finalIsDuplicate($agent, $payload)) {
                    ChatMessage::create([
                        'agent_id' => $agent->id,
                        'author' => 'agent',
                        'content' => (string) ($payload['content'] ?? ''),
                        'meta' => [
                            'run_id' => $payload['run_id'] ?? null,
                            'thinking' => (string) ($payload['thinking'] ?? ''),
                        ],
                    ]);
                    $agent->forceFill(['last_active_at' => $row->occurred_at])->save();
                }
                break;

            case 'approval.request':
                if ($agent->status !== AgentStatus::WaitingApproval) {
                    $this->setStatus($agent, AgentStatus::WaitingApproval);
                }
                break;

            case 'approval.resolved':
                // Marca la fila del request como resuelta (sino queda
                // para siempre en pending_approvals; el whereNot con NULL
                // la escondia).
                $reqId = $payload['id'] ?? null;
                if ($reqId !== null) {
                    $requestRow = AgentEventRow::where('agent_id', $agent->id)
                        ->where('type', 'approval.request')
                        ->where('payload->id', $reqId)
                        ->where('payload->resolved', '!=', true)
                        ->latest('occurred_at')
                        ->first();
                    if ($requestRow) {
                        $p = $requestRow->payload;
                        $p['resolved'] = true;
                        $p['approved'] = $payload['approved'] ?? null;
                        $requestRow->forceFill(['payload' => $p])->save();
                    }
                }
                if ($agent->status === AgentStatus::WaitingApproval) {
                    $this->setStatus($agent, AgentStatus::Idle);
                }
                break;

            case 'error':
                Log::warning('Agente '.$agent->id.': '.($payload['message'] ?? 'error sin detalle'));
                break;
        }
    }

    /**
     * Anti-duplicidad (pi reemite `message_end` del historial al
     * re-adjuntar/probar): el ultimo final persistido con el mismo
     * (run_id, contenido, razonamiento) se considera reemision.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function finalIsDuplicate(Agent $agent, array $payload): bool
    {
        $last = ChatMessage::where('agent_id', $agent->id)
            ->where('author', 'agent')
            ->latest('id')
            ->first();

        return (bool) ($last && $last->content === (string) ($payload['content'] ?? '')
            && ($last->meta['run_id'] ?? null) === ($payload['run_id'] ?? null)
            && ($last->meta['thinking'] ?? '') === (string) ($payload['thinking'] ?? ''));
    }

    /**
     * Marca como resueltas (superadas) las aprobaciones colgadas del
     * agente: no tienen ya sentido (el turno ha terminado o el agente se
     * ha detenido) y la web las re-montaria como tarjeta en cada carga.
     */
    public function resolvePendingApprovals(Agent $agent, string $reason): void
    {
        AgentEventRow::where('agent_id', $agent->id)
            ->where('type', 'approval.request')
            ->whereNot('payload->resolved', true)
            ->get()
            ->each(function (AgentEventRow $row) use ($reason): void {
                $p = $row->payload ?? [];
                $p['resolved'] = true;
                $p['outcome'] = 'superseded';
                $p['reason'] = $reason;
                $row->forceFill(['payload' => $p])->save();
            });
    }

    protected function setStatus(Agent $agent, AgentStatus $status): void
    {
        if ($agent->status === $status) {
            return;
        }

        Log::info("EventSink: setStatus {$agent->id} ".$agent->status->value.' -> '.$status->value.' de: '.collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4))->map(fn ($f) => ($f['class'] ?? '?').'::'.$f['function'])->implode(' <- '));
        $agent->status = $status;
        if ($status === AgentStatus::Working || $status === AgentStatus::Idle) {
            $agent->last_active_at = now();
        }
        $agent->save();

        // Avisamos el cambio a quien no vio los eventos intermedios
        $this->dispatch(
            $agent->refresh(),
            AgentEvent::statusChange($status->value)
        );
    }
}

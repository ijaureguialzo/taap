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
        if ($event->type !== 'message.delta') {
            $row->save();
        }

        $this->applySideEffects($agent, $row);

        // En cola: el broadcast no debe bloquear el bucle del bridge.
        // v1: los deltas no se difunden por cola (volumen); la Web recibe
        // final/status. La difusion token-a-token llega en v1.1.
        if ($event->type !== 'message.delta') {
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
                }
                break;

            case 'message.final':
                // Anti-duplicidad: pi reemite message_end del historial al
                // re-adjuntar/probar. Se deduplica por (run_id, contenido):
                // si el ultimo final es identico, se omite.
                $content = (string) ($payload['content'] ?? '');
                $runId = $payload['run_id'] ?? null;
                $last = ChatMessage::where('agent_id', $agent->id)
                    ->where('author', 'agent')
                    ->latest('id')
                    ->first();
                if (! ($last && $last->content === $content
                    && ($last->meta['run_id'] ?? null) === $runId)) {
                    ChatMessage::create([
                        'agent_id' => $agent->id,
                        'author' => 'agent',
                        'content' => $content,
                        'meta' => ['run_id' => $runId],
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

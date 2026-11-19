<?php

namespace App\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentEventRow;
use App\Jobs\SendMessageToChannel;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

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
            Queue::push(SendMessageToChannel::class, [
                'channel' => 'agent.' . $agent->id,
                'event' => 'agent-event',
                'data' => [
                    'id' => $row->id,
                    'agent_id' => $agent->id,
                    'type' => $event->type,
                    'payload' => $event->payload,
                    'at' => $event->at->format('c'),
                ],
            ]);
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
                if ($agent->status === AgentStatus::WaitingApproval) {
                    $this->setStatus($agent, AgentStatus::Idle);
                }
                break;

            case 'error':
                Log::warning('Agente ' . $agent->id . ': ' . ($payload['message'] ?? 'error sin detalle'));
                break;
        }
    }

    protected function setStatus(Agent $agent, AgentStatus $status): void
    {
        if ($agent->status === $status) {
            return;
        }

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

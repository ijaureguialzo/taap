<?php

namespace App\Agents;

use App\Enums\AgentRuntime;
use App\Enums\AgentStatus;
use App\Jobs\BridgeResolveApproval;
use App\Jobs\BridgeSendMessage;
use App\Models\Agent;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Punto de entrada del chat (spec agent-chat): valida, persiste el mensaje
 * de usuario, lo entrega al runtime (vía cola del bridge) y difunde a los
 * clientes.
 */
class ChatService
{
    public function __construct(
        protected EventSink $sink,
        protected AgentManager $manager,
    ) {}

    /**
     * @throws \RuntimeException si el agente no puede aceptar mensajes
     */
    public function send(Agent $agent, User $user, string $content): ChatMessage
    {
        $content = trim($content);
        if ($content === '') {
            throw new \RuntimeException('Mensaje vacío');
        }

        if ($agent->status === AgentStatus::WaitingApproval) {
            throw new QuotaExceededException('El agente espera una aprobación pendiente');
        }
        if (! $agent->status->isRunning()) {
            throw new \RuntimeException('El agente no está en ejecución');
        }

        $message = ChatMessage::create([
            'agent_id' => $agent->id,
            'author' => 'user',
            'user_id' => $user->id,
            'content' => $content,
        ]);

        // Nota: NO se dispatcha messageFinal para el mensaje de usuario:
        // la fila ya se persistio arriba y el sink lo guardaria de nuevo
        // como mensaje de agente (eco). La web ya lo tiene optimisticamente.
        if ($agent->runtime === AgentRuntime::Hermes) {
            // Coproceso: la corrida HTTP larga no bloquea nada
            $this->spawnHermesTurn($agent, $message);
        } else {
            // Pi: el bridge escribe el prompt en el RPC (cola FIFO nativa)
            BridgeSendMessage::dispatch($agent->id, $content, $user->id)->onQueue('taap-bridge');
        }

        $agent->forceFill(['last_active_at' => now()])->save();

        return $message;
    }

    public function resolveApproval(Agent $agent, User $user, string $approvalId, bool $approved, ?string $note = null): void
    {
        ChatMessage::create([
            'agent_id' => $agent->id,
            'author' => 'user',
            'user_id' => $user->id,
            'content' => $approved ? '✔ Aprobado' : '✖ Denegado',
            'meta' => [
                'approval_id' => $approvalId,
                'approved' => $approved,
                'note' => $note,
            ],
        ]);

        $this->sink->dispatch($agent, AgentEvent::approvalResolved(null, $approvalId, $approved, $note));

        if ($agent->runtime === AgentRuntime::Hermes) {
            $this->spawnHermesTurn($agent, null, $approved ? '[aprobado] Continúa.' : '[denegado] Detente.');
        } else {
            BridgeResolveApproval::dispatch($agent->id, $approvalId, $approved, $note)->onQueue('taap-bridge');
        }
    }

    protected function spawnHermesTurn(Agent $agent, ?ChatMessage $message, ?string $content = null): void
    {
        if ($message === null && $content !== null) {
            $message = ChatMessage::create([
                'agent_id' => $agent->id,
                'author' => 'user',
                'content' => $content,
                'meta' => ['synthetic' => true],
            ]);
        }

        // Coproceso desatendido: al morir el padre (worker HTTP) queda
        // re-padrido bajo init y sigue hasta terminar la corrida.
        //
        // El worker HTTP arranca con cwd=public/ (php -S), asi que se usa
        // la ruta absoluta de artisan y el binario PHP real.
        $spec = sprintf(
            '%s %s taap:hermes-turn %d %d >/dev/null 2>&1',
            PHP_BINARY,
            escapeshellarg(base_path('artisan')),
            $agent->id,
            (int) ($message->id ?? 0)
        );

        try {
            Process::command($spec)->start();
            Log::info("ChatService: coproceso hermes lanzado (msg {$message->id}, {$agent->id})");
        } catch (\Throwable $e) {
            Log::error('ChatService: fallo lanzando coproceso hermes: '.$e->getMessage());
        }
    }
}

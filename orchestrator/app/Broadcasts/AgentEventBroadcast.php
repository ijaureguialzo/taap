<?php

namespace App\Broadcasts;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\Channel;

/**
 * Difusion de un evento del agente por Reverb (canal `agent.{id}`).
 *
 * DTO de difusion (no es modelo): se colas via Bus y el framework lo
 * envuelve en el job BroadcastEvent estandar (serializacion probada).
 */
class AgentEventBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $agentId,
        public string $type,
        public array $payload,
        public string $at,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('agent.' . $this->agentId)];
    }

    public function broadcastAs(): string
    {
        return 'agent-event';
    }

    public function broadcastWith(): array
    {
        return [
            'agent_id' => $this->agentId,
            'type' => $this->type,
            'payload' => $this->payload,
            'at' => $this->at,
        ];
    }
}

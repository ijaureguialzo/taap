<?php

namespace App\Jobs;

use App\Agents\AgentEvent;
use App\Agents\EventSink;
use App\Agents\RuntimeAdapters;
use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Orden del demonio del bridge: BridgeSendMessage.
 */
class BridgeSendMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 7200;

    public function __construct(
        public int $agentId,
        public string $content,
        public ?int $userId = null,
    ) {}

    public function handle(RuntimeAdapters $adapters, EventSink $sink): void
    {
        $agent = Agent::find($this->agentId);
        if (! $agent) {
            return;
        }

        try {
            $adapters->for($agent)->writeMessage($agent, $this->content, $this->userId);
        } catch (\Throwable $e) {
            $sink->dispatch($agent, AgentEvent::error('No se pudo entregar el mensaje: '.$e->getMessage()));
            $sink->dispatch($agent, AgentEvent::statusChange('error'));
        }
    }
}

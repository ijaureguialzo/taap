<?php

namespace App\Jobs;

use App\Agents\RuntimeAdapters;

use App\Agents\AgentEvent;
use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Orden del demonio del bridge: BridgeResolveApproval.
 */

class BridgeResolveApproval implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

public function __construct(
        public int $agentId,
        public string $approvalId,
        public bool $approved,
        public ?string $note = null,
    ) {}

    public function handle(RuntimeAdapters $adapters, EventSink $sink): void
    {
        $agent = Agent::find($this->agentId);
        if (! $agent) {
            return;
        }

        $adapters->for($agent)->resolveApproval($agent, $this->approvalId, $this->approved, $this->note);
    }
}

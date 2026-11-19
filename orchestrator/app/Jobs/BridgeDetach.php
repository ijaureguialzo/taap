<?php

namespace App\Jobs;

use App\Agents\RuntimeAdapters;

use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Orden del demonio del bridge: BridgeDetach.
 */

class BridgeDetach implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 10;

public function __construct(public int $agentId) {}

    public function handle(RuntimeAdapters $adapters): void
    {
        if ($agent = Agent::find($this->agentId)) {
            $adapters->for($agent)->detach($agent);
        }
    }
}

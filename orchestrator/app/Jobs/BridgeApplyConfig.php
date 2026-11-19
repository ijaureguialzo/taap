<?php

namespace App\Jobs;

use App\Agents\ConfigRenderer;
use App\Agents\EgressSync;
use App\Agents\EventSink;
use App\Agents\RuntimeAdapters;
use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Orden del demonio del bridge: BridgeApplyConfig.
 */
class BridgeApplyConfig implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $agentId) {}

    public function handle(RuntimeAdapters $adapters, EventSink $sink): void
    {
        $agent = Agent::find($this->agentId);
        if (! $agent) {
            return;
        }

        app(ConfigRenderer::class)->render($agent);
        $adapters->for($agent)->applyConfig($agent);
        app(EgressSync::class)->sync();
    }
}

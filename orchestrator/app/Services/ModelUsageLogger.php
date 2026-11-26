<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\ModelUsage;

/**
 * Best-effort telemetry write for Model Proxy calls. Never throws:
 * telemetry must not break the inference path. Logging happens in the
 * same process that served the response, with the data already in hand.
 */
class ModelUsageLogger
{
    public function record(
        Agent $agent,
        ?ModelProvider $provider,
        ?Model $model,
        int $tokensInput = 0,
        int $tokensOutput = 0,
        ?int $status = null,
        int $latencyMs = 0,
        bool $stream = false,
    ): void {
        try {
            ModelUsage::create([
                'agent_id' => $agent->id,
                'provider_id' => $provider?->id,
                'model_id' => $model?->id,
                'tokens_input' => $tokensInput,
                'tokens_output' => $tokensOutput,
                'status' => $status,
                'latency_ms' => $latencyMs,
                'stream' => $stream,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('ModelUsage: escritura fallida (best-effort)', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

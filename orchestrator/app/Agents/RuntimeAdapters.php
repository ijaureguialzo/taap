<?php

namespace App\Agents;

use App\Models\Agent;

/** Resolve el adaptador adecuado para un agente según su runtime. */
class RuntimeAdapters
{
    public function __construct(
        protected PiAdapter $pi,
        protected HermesAdapter $hermes,
    ) {
    }

    public function for(Agent $agent): AgentRuntimeAdapter
    {
        return match ($agent->runtime) {
            \App\Enums\AgentRuntime::Pi => $this->pi,
            \App\Enums\AgentRuntime::Hermes => $this->hermes,
            default => throw new \RuntimeException("Runtime sin adaptador: {$agent->runtime->value}"),
        };
    }
}

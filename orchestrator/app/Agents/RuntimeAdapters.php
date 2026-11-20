<?php

namespace App\Agents;

use App\Enums\AgentRuntime;
use App\Models\Agent;

/** Resolve el adaptador adecuado para un agente según su runtime. */
class RuntimeAdapters
{
    public function __construct(
        protected PiAdapter $pi,
        protected HermesAdapter $hermes,
        protected ClaudeAdapter $claude,
        protected CodexAdapter $codex,
        protected OpenCodeAdapter $opencode,
    ) {}

    public function for(Agent $agent): AgentRuntimeAdapter
    {
        return match ($agent->runtime) {
            AgentRuntime::Pi => $this->pi,
            AgentRuntime::Hermes => $this->hermes,
            AgentRuntime::Claude => $this->claude,
            AgentRuntime::Codex => $this->codex,
            AgentRuntime::OpenCode => $this->opencode,
        };
    }
}

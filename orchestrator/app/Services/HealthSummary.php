<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentEvent;
use App\Models\ModelProvider;

/**
 * Agregado de salud operativo para el panel admin (ops-health-dashboard).
 * Lee datos ya persistidos: agents.status, agent_events (fallos) y
 * model_providers (salud). Sin tablas nuevas.
 */
class HealthSummary
{
    /**
     * @return array{
     *   agents_by_status: array<string, int>,
     *   recent_failures: array<int, array{agent_id: int, name: ?string, runtime: ?string, cause: string, at: ?string}>,
     *   providers: array<int, array{id: int, name: string, type: ?string, status: string, last_checked_at: ?string}>
     * }
     */
    public function __invoke(): array
    {
        $agentsByStatus = Agent::selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        $failures = AgentEvent::with('agent:id,name,runtime')
            ->where('type', 'error')
            ->latest('occurred_at')
            ->limit(20)
            ->get()
            ->map(function (AgentEvent $event) {
                $payload = (array) $event->payload;

                return [
                    'agent_id' => (int) $event->agent_id,
                    'name' => $event->agent?->name,
                    'runtime' => $event->agent?->runtime?->value ?? null,
                    'cause' => (string) ($payload['message'] ?? 'error'),
                    'at' => $event->occurred_at?->toIso8601String(),
                ];
            })
            ->values()
            ->toArray();

        $providers = ModelProvider::orderBy('name')->get()->map(fn (ModelProvider $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'type' => $p->type,
            'status' => $p->status ?? 'unknown',
            'last_checked_at' => $p->last_checked_at?->toIso8601String(),
        ])->all();

        return [
            'agents_by_status' => $agentsByStatus,
            'recent_failures' => $failures,
            'providers' => $providers,
        ];
    }
}

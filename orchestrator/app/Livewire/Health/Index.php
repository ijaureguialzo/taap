<?php

namespace App\Livewire\Health;

use App\Services\HealthSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Index extends Component
{
    public function render(): View
    {
        $lastNotifications = DB::table('settings')
            ->where('key', 'like', 'failure_notification_last_%')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $parts = explode('_', $row->key, 4); // failure_notification_last_<agentId>
                $agentId = (int) ($parts[3] ?? 0);
                $agent = \App\Models\Agent::find($agentId);
                $payload = json_decode($row->value, true);

                return [
                    'agent_id' => $agentId,
                    'agent_name' => $agent?->name,
                    'status' => $payload['status'] ?? 'unknown',
                    'http_code' => $payload['http_code'] ?? null,
                    'error' => $payload['error'] ?? null,
                    'at' => $payload['at'] ?? null,
                ];
            })->values();

        return view('livewire.health.index', [
            'summary' => app(HealthSummary::class)->__invoke(),
            'lastNotifications' => $lastNotifications,
        ]);
    }
}

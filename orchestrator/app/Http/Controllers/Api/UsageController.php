<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\ModelUsage;
use App\Models\ModelProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    /**
     * Uso de modelos (admin): filas paginadas + agregados por agente/proveedor.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'agent_id' => ['nullable', 'integer', 'exists:agents,id'],
            'provider_id' => ['nullable', 'integer', 'exists:model_providers,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = ModelUsage::with('agent:id,name,owner_id')->latest('created_at');

        if ($data['agent_id'] ?? null) {
            $q->where('agent_id', $data['agent_id']);
        }
        if ($data['provider_id'] ?? null) {
            $q->where('provider_id', $data['provider_id']);
        }
        if ($data['from'] ?? null) {
            $q->where('created_at', '>=', $data['from'].' 00:00:00');
        }
        if ($data['to'] ?? null) {
            $q->where('created_at', '<=', $data['to'].' 23:59:59');
        }

        $perPage = min((int) ($data['per_page'] ?? 50), 100);
        $page = (int) ($data['page'] ?? 1);
        $rows = $q->paginate($perPage, ['*'], 'page', $page);

        $base = ModelUsage::query();
        if ($data['from'] ?? null) {
            $base->where('created_at', '>=', $data['from'].' 00:00:00');
        }
        if ($data['to'] ?? null) {
            $base->where('created_at', '<=', $data['to'].' 23:59:59');
        }
        if ($data['provider_id'] ?? null) {
            $base->where('provider_id', $data['provider_id']);
        }

        $totals = (clone $base)->selectRaw('COUNT(*) as requests, SUM(tokens_input) as tokens_in, SUM(tokens_output) as tokens_out, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) as errors, AVG(latency_ms) as latency_avg')->first();

        $perAgent = (clone $base)
            ->selectRaw('agent_id, COUNT(*) as requests, SUM(tokens_input) as tokens_in, SUM(tokens_output) as tokens_out, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) as errors, AVG(latency_ms) as latency_avg')
            ->groupBy('agent_id')
            ->orderByDesc('requests')
            ->limit(20)
            ->get();

        $agentNames = Agent::whereIn('id', $perAgent->pluck('agent_id'))->pluck('name', 'id');

        $perProvider = (clone $base)
            ->selectRaw('provider_id, COUNT(*) as requests, SUM(tokens_input) as tokens_in, SUM(tokens_output) as tokens_out, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) as errors, AVG(latency_ms) as latency_avg')
            ->groupBy('provider_id')
            ->orderByDesc('requests')
            ->get();

        $providerNames = ModelProvider::whereIn('id', $perProvider->pluck('provider_id'))->pluck('name', 'id');

        return response()->json([
            'data' => collect($rows->items())->map(fn (ModelUsage $row) => [
                'id' => $row->id,
                'agent_id' => $row->agent_id,
                'agent_name' => $row->agent?->name,
                'provider_id' => $row->provider_id,
                'model_id' => $row->model_id,
                'tokens_input' => $row->tokens_input,
                'tokens_output' => $row->tokens_output,
                'status' => $row->status,
                'latency_ms' => $row->latency_ms,
                'stream' => $row->stream,
                'created_at' => $row->created_at?->toIso8601String(),
            ]),
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
            'last_page' => $rows->lastPage(),
            'totals' => [
                'requests' => (int) ($totals->requests ?? 0),
                'tokens_in' => (int) ($totals->tokens_in ?? 0),
                'tokens_out' => (int) ($totals->tokens_out ?? 0),
                'errors' => (int) ($totals->errors ?? 0),
                'latency_avg_ms' => round((float) ($totals->latency_avg ?? 0), 1),
            ],
            'per_agent' => $perAgent->map(fn ($a) => [
                'agent_id' => $a->agent_id,
                'agent_name' => $agentNames[$a->agent_id] ?? null,
                'requests' => (int) $a->requests,
                'tokens_in' => (int) $a->tokens_in,
                'tokens_out' => (int) $a->tokens_out,
                'errors' => (int) $a->errors,
                'latency_avg_ms' => round((float) $a->latency_avg, 1),
            ])->values(),
            'per_provider' => $perProvider->map(fn ($p) => [
                'provider_id' => $p->provider_id,
                'provider_name' => $providerNames[$p->provider_id] ?? null,
                'requests' => (int) $p->requests,
                'tokens_in' => (int) $p->tokens_in,
                'tokens_out' => (int) $p->tokens_out,
                'errors' => (int) $p->errors,
                'latency_avg_ms' => round((float) $p->latency_avg, 1),
            ])->values(),
        ]);
    }

    protected function requireAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }
}

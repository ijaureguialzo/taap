<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    /**
     * Lista las entradas de auditoría (admin) con filtros y paginación.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'target_type' => ['nullable', 'string', 'max:40'],
            'actor_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = AuditLog::with(['actor'])->latest('created_at');

        if ($data['action'] ?? null) {
            $q->where('action', $data['action']);
        }
        if ($data['target_type'] ?? null) {
            $q->where('target_type', $data['target_type']);
        }
        if ($data['actor_id'] ?? null) {
            $q->where('actor_id', $data['actor_id']);
        }
        if ($data['from'] ?? null) {
            $q->where('created_at', '>=', $data['from'].' 00:00:00');
        }
        if ($data['to'] ?? null) {
            $q->where('created_at', '<=', $data['to'].' 23:59:59');
        }

        $perPage = min((int) ($data['per_page'] ?? 50), 100);
        $page = (int) ($data['page'] ?? 1);
        $entries = $q->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($entries->items())->map(fn (AuditLog $e) => [
                'id' => $e->id,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                'action' => $e->action,
                'target_type' => $e->target_type,
                'target_id' => $e->target_id,
                'metadata' => $e->metadata,
                'created_at' => $e->created_at?->toIso8601String(),
            ]),
            'total' => $entries->total(),
            'page' => $entries->currentPage(),
            'per_page' => $entries->perPage(),
            'last_page' => $entries->lastPage(),
        ]);
    }

    protected function requireAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }
}

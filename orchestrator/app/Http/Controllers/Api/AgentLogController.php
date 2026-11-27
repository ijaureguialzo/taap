<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\AgentLogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentLogController extends Controller
{
    /**
     * Tail de logs del contenedor del agente (owner/admin).
     */
    public function logs(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize($request, $agent);

        $data = $request->validate([
            'tail' => ['nullable', 'integer', 'in:50,200,500'],
        ]);

        $result = app(AgentLogs::class)->tail($agent, (int) ($data['tail'] ?? 200));

        return response()->json([
            'status' => $result['status'],
            'lines' => $result['lines'],
        ]);
    }

    protected function authorize(Request $request, Agent $agent): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $agent->owner_id !== $user->id) {
            abort(404);
        }
    }
}

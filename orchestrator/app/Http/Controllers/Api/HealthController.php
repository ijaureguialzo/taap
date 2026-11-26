<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HealthSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    /**
     * Resumen de salud operativo (admin): agentes por estado + últimos
     * fallos + salud de proveedores.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $summary = app(HealthSummary::class)->__invoke();

        return response()->json($summary);
    }

    protected function requireAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }
}

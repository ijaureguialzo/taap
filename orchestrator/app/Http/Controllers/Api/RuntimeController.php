<?php

namespace App\Http\Controllers\Api;

use App\Enums\AgentRuntime;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RuntimeController extends Controller
{
    /**
     * Registro de runtimes expuesto a los clientes (ios-model-runtime-compatibility,
     * design D2): EXACTAMENTE los runtimes del registro, con campos minimos
     * (id, label y formatos de alambre que consume). El resto de la entrada
     * del registro (imagen, volumes, command…) son detalles de despliegue
     * internos y no se exponen. Asi un runtime nuevo llega a los clientes
     * sin cambios en ellos (spec agent-runtimes).
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'runtimes' => collect(AgentRuntime::supported())->map(fn (string $id) => AgentRuntime::fromString($id))->map(
                fn (AgentRuntime $runtime) => [
                    'id' => $runtime->value,
                    'label' => $runtime->label(),
                    'formats' => $runtime->modelFormats(),
                ]
            )->all(),
        ]);
    }
}

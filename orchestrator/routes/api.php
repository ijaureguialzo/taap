<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\ModelController;
use App\Http\Controllers\Api\RuntimeController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Proxy\McpBridgeController;
use App\Http\Controllers\Proxy\ModelProxyController;
use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| API publica de TAAP (v1).
| Autenticacion: token Sanctum (Authorization: Bearer) para la app iOS y la
| Web (que usa cookies de sesion en la ruta /, no esta).
*/
Route::prefix('v1')->group(function () {
    /* auth */
    Route::post('auth/login', [AuthController::class, 'login']);

    // (autorizacion via el mismo criterio que AgentController) (protocolo Pusher): la app firma su suscripcion.
    // Respuesta: { auth: "key:signature" }
    Route::post('broadcasting/auth', function (Request $request) {
        $channel = (string) $request->input('channel_name', '');
        $socketId = (string) $request->input('socket_id', '');

        $name = str_replace('private-', '', $channel);
        if (! str_starts_with($name, 'agent.')) {
            return response()->json(['message' => 'Canal no autorizado'], 403);
        }

        $parts = explode('.', $name);
        $agent = Agent::find((int) ($parts[1] ?? 0));
        $user = $request->user();
        if (! $agent || (! $user->isAdmin() && $agent->owner_id !== $user->id)) {
            return response()->json(['message' => 'Canal no autorizado'], 403);
        }

        // Firma Pusher: hmac-sha256("canal:socket_id:payload", app_secret)
        $conn = config('broadcasting.connections.reverb');
        $payload = json_encode(['user_id' => $user->id, 'user_info' => ['name' => $user->name]]);
        $sig = hash_hmac('sha256', $channel.':'.$socketId.':'.$payload, $conn['secret']);

        return response()->json(['auth' => $conn['key'].':'.$sig]);
    })->middleware('auth:sanctum');

    // Config del canal WSS (la web la recibe en el layout; la app la pide aqui)
    Route::get('reverb-config', function () {
        $conn = config('broadcasting.connections.reverb');
        $public = config('taap.public');

        return response()->json([
            'config' => [
                'key' => $conn['key'],
                'appId' => $conn['app_id'],
                'host' => $public['host'],
                'port' => (int) $public['port'],
                'scheme' => $public['scheme'],
            ],
        ]);
    })->middleware('auth:sanctum');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('auth/tokens', [AuthController::class, 'tokens']);
        Route::post('auth/tokens', [AuthController::class, 'createToken']);
        Route::delete('auth/tokens/{tokenId}', [AuthController::class, 'deleteToken']);
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);

        /* agentes */
        Route::get('agents', [AgentController::class, 'index']);
        Route::post('agents', [AgentController::class, 'store']);
        Route::get('agents/{agent}', [AgentController::class, 'show']);
        Route::put('agents/{agent}', [AgentController::class, 'update']);
        Route::delete('agents/{agent}', [AgentController::class, 'destroy']);
        Route::post('agents/{agent}/start', [AgentController::class, 'start']);
        Route::post('agents/{agent}/stop', [AgentController::class, 'stop']);
        Route::post('agents/{agent}/restart', [AgentController::class, 'restart']);
        Route::get('agents/{agent}/status', [AgentController::class, 'status']);

        /* chat */
        Route::get('agents/{agent}/messages', [ChatController::class, 'messages']);
        Route::get('agents/{agent}/timeline', [ChatController::class, 'timeline']);
        Route::post('agents/{agent}/messages', [ChatController::class, 'send']);
        Route::post('agents/{agent}/approvals/{approvalId}', [ChatController::class, 'approve']);

        /* runtimes del registro (ios-model-runtime-compatibility: los
           clientes construyen sus selectores desde esta lista, no desde
           hardcode) */
        Route::get('runtimes', [RuntimeController::class, 'index']);

        /* modelos (ver: todos; crear: admin) */
        Route::get('models', [ModelController::class, 'index']);
        Route::get('providers', [ModelController::class, 'providers']);
        Route::post('providers', [ModelController::class, 'storeProvider']);
        Route::put('providers/{provider}', [ModelController::class, 'updateProvider']);
        Route::delete('providers/{provider}', [ModelController::class, 'deleteProvider']);
        Route::post('models', [ModelController::class, 'store']);
        Route::delete('models/{model}', [ModelController::class, 'delete']);

        /* MCP (ver: todos; crear: admin) */
        Route::get('mcp', [McpController::class, 'index']);
        Route::post('mcp', [McpController::class, 'store']);
        Route::put('mcp/{server}', [McpController::class, 'update']);
        Route::delete('mcp/{server}', [McpController::class, 'destroy']);

        /* ficheros (shared-filesystem 4.1): la raiz se resuelve por rol */
        Route::get('files', [FileController::class, 'index']);
        Route::get('files/download', [FileController::class, 'download']);
        Route::post('files/folders', [FileController::class, 'storeFolder']);
        Route::post('files/rename', [FileController::class, 'rename']);
        Route::post('files/uploads', [FileController::class, 'upload']);
        Route::delete('files', [FileController::class, 'destroy']);

        /* usuarios (admin) */ Route::get('users', [UserController::class, 'index']);
        Route::put('users/{user}', [UserController::class, 'update']);
    });
});

/*
| Superficies para los CONTENEDORES de agente (token de agente, no token de
| usuario). Estas rutas NO piden auth de usuario: la autenticacion la hace
| el propio controlador con el Bearer del token de agente.
*/
Route::prefix('proxy/agents/{agent}')->group(function () {
    Route::post('v1/chat/completions', [ModelProxyController::class, '__invoke']);
    Route::post('v1/messages', [ModelProxyController::class, '__invoke']);
    Route::post('v1/responses', [ModelProxyController::class, 'responses']);
    // Servicio del CLI Claude (spike add-claude-code-runtime 1.2): el CLI
    // los consulta al arrancar cada query.
    Route::get('v1/models', [ModelProxyController::class, 'models']);
    Route::match(['head', 'get'], 'api/hello', [ModelProxyController::class, 'hello']);
});

Route::post('mcp/agents/{agent}/bridge', [McpBridgeController::class, '__invoke']);

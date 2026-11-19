<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\ModelController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Proxy\McpBridgeController;
use App\Http\Controllers\Proxy\ModelProxyController;
use Illuminate\Support\Facades\Route;

/*
| API publica de TAAP (v1).
| Autenticacion: token Sanctum (Authorization: Bearer) para la app iOS y la
| Web (que usa cookies de sesion en la ruta /, no esta).
*/
Route::prefix('v1')->group(function () {
    /* auth */
    Route::post('auth/login', [AuthController::class, 'login']);

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
        Route::post('agents/{agent}/messages', [ChatController::class, 'send']);
        Route::post('agents/{agent}/approvals/{approvalId}', [ChatController::class, 'approve']);

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

        /* usuarios (admin) */
        Route::get('users', [UserController::class, 'index']);
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
});

Route::post('mcp/agents/{agent}/bridge', [McpBridgeController::class, '__invoke']);


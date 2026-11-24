<?php

use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\SetLocale;
use App\Services\FileSystemException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserRole::class,
            'locale' => SetLocale::class,
        ]);

        $middleware->append(SetLocale::class);

        // spa-theme-persistence D1: la cookie taap-theme la escribe
        // JavaScript en texto plano (para que el <html> servido lleve el
        // tema y la navegacion wire:navigate lo conserve): no cifrarla —
        // un decrypt() fallido la dejaría en null y la respuesta la
        // re-cifraria, pisando el valor del cliente.
        $middleware->encryptCookies(except: ['taap-theme']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // FS compartido: el servicio lleva su codigo HTTP (404/409/413/422/500).
        $exceptions->render(function (FileSystemException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                throw $e;
            }

            return response()->json(['error' => $e->getMessage()], $e->status);
        });
    })->create();

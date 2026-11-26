<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registro público conmutable (spec users-and-roles): mientras el admin lo
 * tenga desactivado, /register se rechaza con redirección a home.
 */
class EnsureRegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(Settings::class)->isRegistrationEnabled()) {
            return $next($request);
        }

        return redirect()->route('home')->with('error', __('register.closed'));
    }
}

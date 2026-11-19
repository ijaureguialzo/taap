<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * i18n (design.md D11): la interfaz se localiza segun el idioma preferido
 * del usuario (users.locale); en peticiones sin autenticar, Accept-Language.
 * El CONTENIDO (nombres de agentes, chat, modelos, MCP) nunca se traduce.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locales = config('taap.locales', ['es', 'en', 'eu']);
        if (! is_array($locales) || $locales === []) {
            $locales = ['es', 'en', 'eu'];
        }

        $locale = $request->user()?->locale;
        $locale ??= $this->fromAcceptLanguage($request);

        if (! in_array($locale, $locales, true)) {
            // Spec localizacion: default sin preferencia = `es`.
            $default = config('taap.default_locale', 'es');
            $locale = is_string($default) && $default !== '' ? $default : 'es';
        }

        app()->setLocale($locale);

        return $next($request);
    }

    protected function fromAcceptLanguage(Request $request): ?string
    {
        $header = $request->header('Accept-Language');
        if (! $header) {
            return null;
        }

        $accepted = config('taap.locales', ['es', 'en', 'eu']);

        foreach (array_map('trim', explode(',', explode(';', $header)[0])) as $tag) {
            $base = strtolower(substr($tag, 0, 2));
            if (in_array($base, $accepted, true)) {
                return $base;
            }
        }

        return null;
    }
}

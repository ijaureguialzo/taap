<?php

namespace App\Support;

/**
 * Decide si la aplicacion debe generar sus URLs con https (assets de
 * Vite, redirects, url()/asset()).
 *
 * Tri-estado de TAAP_FORCE_HTTPS (change configurable-https-urls,
 * design D1): true -> fuerza https; false -> fuerza http; null (o un
 * string que env() no castea a bool) -> derivacion del esquema de
 * APP_URL, el estado que 2f56d5b dejo: el dev local en Docker con
 * APP_URL=http no debe redirigir a https.
 */
final class ForceHttps
{
    public static function resolve(mixed $explicit, string $appUrl): bool
    {
        if (is_bool($explicit)) {
            return $explicit;
        }

        return str_starts_with($appUrl, 'https://');
    }
}

<?php

namespace App\Support;

/**
 * Identidad upstream compartida (agent-runtime-reliability, design D1).
 *
 * Algunos gateways de modelos validan el header `Host` contra una lista
 * permitida (llama.cpp `--allowed-host`, uvicorn `--allowed-hosts`, ...).
 * `host.docker.internal` es solo un alias para llegar al host desde un
 * contenedor: el servidor se ve a sí mismo por 127.0.0.1, y es ese el
 * `Host` que hay que presentar. El proxy de modelos y el sondeo de salud
 * usan esta misma convención — un gateway que sirve el tráfico de agentes
 * a través del proxy debe pasar también la comprobación de salud.
 */
class UpstreamIdentity
{
    /**
     * Valor del header `Host` a presentar para un `base_url` upstream:
     * `host[:puerto]`, con el mapeo `host.docker.internal → 127.0.0.1`;
     * `null` si la URL no declara host (el cliente HTTP decide).
     */
    public static function hostFor(string $baseUrl): ?string
    {
        $host = parse_url($baseUrl, \PHP_URL_HOST) ?: '';
        if ($host === '') {
            return null;
        }

        $port = parse_url($baseUrl, \PHP_URL_PORT);
        $host = $host === 'host.docker.internal' ? '127.0.0.1' : $host;

        return $host.($port ? ':'.$port : '');
    }
}

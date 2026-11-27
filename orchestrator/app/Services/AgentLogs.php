<?php

namespace App\Services;

use App\Models\Agent;
use App\Support\DockerClient;

/**
 * Tail de logs del contenedor del agente (agent-log-viewer).
 * Lee la salida de `docker logs --tail N` vía DockerClient (sin infraestructura
 * de streaming). Devuelve líneas para pintar en la vista; sin interpretación.
 */
class AgentLogs
{
    public function __construct(protected DockerClient $docker) {}

    /**
     * @return array{status: string, lines: list<string>}
     *   status = 'ok' | 'no-container' | 'unavailable'
     *   status = 'ok': líneas del tail (vacías si el contenedor no ha escrito nada)
     *   status = 'no-container': el contenedor no existe o no tiene salida (el
     *             comando docker logs falló con "No such container")
     *   status = 'unavailable': docker no respondió / error de ejecución
     */
    public function tail(Agent $agent, int $tail = 200): array
    {
        $tail = min(max($tail, 1), 500);

        $containerName = $agent->container_name;

        if (blank($containerName)) {
            return ['status' => 'no-container', 'lines' => []];
        }

        try {
            $result = $this->docker->logsWithStatus($containerName, $tail);
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'lines' => []];
        }

        $stderr = (string) ($result['stderr'] ?? '');

        if ($result['exitCode'] !== 0) {
            // "No such container" / "No such object": el contenedor no existe.
            if (preg_match('/no such container|no such object|not (found|known)/i', $stderr)) {
                return ['status' => 'no-container', 'lines' => []];
            }

            return ['status' => 'unavailable', 'lines' => []];
        }

        $output = (string) ($result['stdout'] ?? '');

        if ($output === '') {
            return ['status' => 'ok', 'lines' => []];
        }

        $lines = array_values(array_filter(array_map(
            fn ($line) => trim($line),
            preg_split("/\r\n|\n|\r/", $output) ?: []
        )));

        return ['status' => 'ok', 'lines' => $lines];
    }
}

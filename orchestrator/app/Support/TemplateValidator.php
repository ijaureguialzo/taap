<?php

namespace App\Support;

use App\Enums\AgentRuntime;
use App\Models\AgentTemplate;
use App\Models\McpServer;
use App\Models\Model;
use Illuminate\Validation\ValidationException;

/**
 * Validación de una plantilla de agente (agent-templates).
 * Devuelve los datos saneados listos para persistir; lanza
 * ValidationException (formato del resto de la app) si hay un problema.
 *
 * Reglas:
 * - runtime: debe estar en el registro (AgentRuntime::supported()).
 * - modelo (nullable): debe existir y ser compatible con el runtime.
 * - mcp_server_ids (nullable): todos deben existir como servidores del catálogo.
 * - cpu (nullable): >= 0 y <= 32. memoria (nullable): cadena <= 32 chars.
 * - name: nombre normalizado (trim), único para su owner.
 */
class TemplateValidator
{
    /**
     * @param  string  $name  Nombre de la plantilla.
     * @param  string  $runtime  Runtime del registro.
     * @param  int|null  $modelId  Modelo sugerido (null lo borra).
     * @param  array<int>|null  $mcpServerIds  Servidores MCP precargados.
     * @param  bool  $internet  Internet por defecto.
     * @param  float|null  $cpu  Límite CPU (nullable).
     * @param  string|null  $memory  Límite memoria (nullable).
     * @param  int  $ownerId  Propietario de la plantilla.
     * @param  int|null  $ignoreId  Id de la plantilla en edición (único por owner).
     */
    public function validate(
        string $name,
        string $runtime,
        ?int $modelId,
        ?array $mcpServerIds,
        bool $internet,
        ?float $cpu,
        ?string $memory,
        int $ownerId,
        ?int $ignoreId = null,
    ): array {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => ['Nombre inválido (máx. 100 caracteres)']]);
        }

        $supported = AgentRuntime::supported();
        if (! in_array($runtime, $supported, true)) {
            throw ValidationException::withMessages(['runtime' => ['Runtime no soportado']]);
        }

        $runtimeEnum = AgentRuntime::fromString($runtime);

        if ($modelId !== null) {
            $model = Model::with('provider')->find($modelId);
            if (! $model) {
                throw ValidationException::withMessages(['model_id' => ['El modelo no existe']]);
            }
            if (! $model->isCompatibleWith($runtimeEnum)) {
                throw ValidationException::withMessages(['model_id' => ['El modelo no es compatible con el runtime']]);
            }
        }

        $mcpServerIds = $mcpServerIds === null ? null : array_values(array_unique(array_map('intval', $mcpServerIds)));
        if ($mcpServerIds !== null && $mcpServerIds !== []) {
            $found = McpServer::whereIn('id', $mcpServerIds)->pluck('id')->toArray();
            $missing = array_diff($mcpServerIds, $found);
            if ($missing !== []) {
                throw ValidationException::withMessages(['mcp_server_ids' => ['Algunos servidores MCP no existen: '.implode(', ', $missing)]]);
            }
        }

        if ($cpu !== null && ($cpu < 0 || $cpu > 32)) {
            throw ValidationException::withMessages(['cpu' => ['CPU debe estar entre 0 y 32']]);
        }

        if ($memory !== null && (Trim($memory) === '' || mb_strlen($memory) > 32)) {
            throw ValidationException::withMessages(['memory' => ['Memoria inválida (máx. 32 caracteres)']]);
        }

        $duplicate = AgentTemplate::where('owner_id', $ownerId)
            ->where('name', $name);
        if ($ignoreId !== null) {
            $duplicate->whereNot('id', $ignoreId);
        }
        if ($duplicate->exists()) {
            throw ValidationException::withMessages(['name' => ['Ya existe una plantilla con ese nombre']]);
        }

        return [
            'name' => $name,
            'runtime' => $runtime,
            'model_id' => $modelId,
            'mcp_server_ids' => $mcpServerIds,
            'internet' => $internet,
            'cpu' => $cpu,
            'memory' => $memory !== null ? trim($memory) : null,
        ];
    }
}

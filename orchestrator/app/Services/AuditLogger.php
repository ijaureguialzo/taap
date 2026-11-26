<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de acciones de administrador.
 *
 * La escritura es deliberadamente simple: una fila por acción, dentro de la
 * misma transacción del handler admin (el caller envuelve la operación en
 * DB::transaction). La auditoría nunca se sacrifica en silencio: si la
 * escritura falla, el rollback de la operación también falla.
 */
class AuditLogger
{
    /**
     * Registra una entrada de auditoría.
     *
     * @param  string  $action  Código de acción estable (user.created, provider.deleted, ...).
     * @param  string  $targetType  'user'|'provider'|'model'|'mcp'|'agent'|'settings'.
     * @param  string|null  $targetId  Identificador del objetivo (string para no asumir int ni nulidad).
     * @param  array<string, mixed>  $metadata  Valores no sensibles (nunca tokens/passwords).
     */
    public function log(User $actor, string $action, string $targetType, ?string $targetId = null, array $metadata = []): void
    {
        DB::table('audit_log')->insert([
            'actor_id' => $actor->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $metadata === [] ? null : json_encode($metadata),
            'created_at' => now(),
        ]);
    }
}

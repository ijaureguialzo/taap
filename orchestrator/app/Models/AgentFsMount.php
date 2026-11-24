<?php

namespace App\Models;

use App\Enums\FsMountMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carpeta del FS compartido conectada a un agente (shared-filesystem,
 * design D4): `path` canónico dentro del árbol + modo de acceso. El
 * directorio real debe existir al guardar (lo valida el servicio, no la DB).
 */
class AgentFsMount extends Model
{
    protected $fillable = [
        'agent_id',
        'path',
        'mode',
    ];

    protected function casts(): array
    {
        return [
            'mode' => FsMountMode::class,
        ];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Mounts expuestos al contenedor (design D2: `disabled` = sin montaje,
     * invisible para el agente).
     *
     * @param  Builder<AgentFsMount>  $query
     * @return Builder<AgentFsMount>
     */
    public function scopeExposed(Builder $query): Builder
    {
        return $query->where('mode', '!=', FsMountMode::Disabled->value);
    }
}

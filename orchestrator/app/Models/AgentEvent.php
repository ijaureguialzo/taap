<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de SOLO LECTURA sobre la tabla agent_events (la escritura la hace
 * AgentEventRow via EventSink).
 *
 * NO implementa ShouldBroadcast a proposito: el broadcast real viaja por
 * App\Broadcasts\AgentEventBroadcast (payload {type, payload, at}). Si este
 * modelo implementara ShouldBroadcast, el framework auto-difundiria cada
 * instancia en cada evento de ciclo de vida (booted, ...) y con cola sync
 * explotaria por recursion en Model::boot.
 */
class AgentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['agent_id', 'type', 'payload', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

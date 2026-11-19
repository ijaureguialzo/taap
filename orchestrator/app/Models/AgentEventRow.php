<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila de la cronologia agent_events.
 *
 * No hereda del modelo base (AgentEvent, que difunde al guardarse): la
 * difusion la hace EventSink via SendMessageToChannel, sin duplicidad.
 */
class AgentEventRow extends Model
{
    public $timestamps = false;

    protected $table = 'agent_events';

    protected $fillable = ['agent_id', 'type', 'payload', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\AgentEventRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila de la cronologia agent_events (la escritura la hace EventSink).
 *
 * La difusion al canal `agent.{id}` la hace EventSink via
 * BroadcastEvent(AgentEventBroadcast); la lectura la hace el modelo
 * App\Models\AgentEvent (agent.{id} -> pending approvals, lineas de
 * tiempo) que NO difunde.
 *
 * @use HasFactory<AgentEventRowFactory>
 */
class AgentEventRow extends Model
{
    /** @use HasFactory<AgentEventRowFactory> */
    use HasFactory;

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

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

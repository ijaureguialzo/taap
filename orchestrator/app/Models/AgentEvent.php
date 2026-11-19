<?php

namespace App\Models;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentEvent extends Model implements ShouldBroadcast
{
    use InteractsWithSockets;

    public $timestamps = false;

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

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('agent.' . $this->agent_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'agent-event';
    }
}

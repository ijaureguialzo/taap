<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentToken extends Model
{
    protected $fillable = ['agent_id', 'token', 'token_hash', 'token_prefix'];

    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public static function issueFor(Agent $agent): self
    {
        $plain = 'taap-' . \Illuminate\Support\Str::random(config('taap.proxy.token_length', 48));

        return new static([
            'agent_id' => $agent->id,
            'token' => $plain,
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 12),
        ]);
    }
}

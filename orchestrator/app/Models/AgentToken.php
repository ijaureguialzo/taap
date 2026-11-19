<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

// final: issueFor() usa new static() (phpstan: new.static en no-final)
final class AgentToken extends Model
{
    protected $fillable = ['agent_id', 'token', 'token_hash', 'token_prefix'];

    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public static function issueFor(Agent $agent): static
    {
        $plain = 'taap-'.Str::random(config('taap.proxy.token_length', 48));

        return new self([
            'agent_id' => $agent->id,
            'token' => $plain,
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 12),
        ]);
    }
}

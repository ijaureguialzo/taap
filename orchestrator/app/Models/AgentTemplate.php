<?php

namespace App\Models;

use App\Enums\AgentRuntime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTemplate extends Model
{
    protected $fillable = [
        'owner_id', 'name', 'runtime', 'model_id', 'mcp_server_ids',
        'internet', 'cpu', 'memory',
    ];

    protected $casts = [
        'internet' => 'boolean',
        'mcp_server_ids' => 'array',
        'cpu' => 'decimal:2',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Model::class, 'model_id');
    }

    public function runtimeEnum(): ?AgentRuntime
    {
        try {
            return AgentRuntime::fromString($this->runtime);
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class McpServer extends Model
{
    protected $table = 'mcp_servers';

    protected $fillable = [
        'name', 'type', 'command', 'args', 'env', 'url', 'headers', 'config', 'enabled', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'args' => 'array',
            'env' => 'encrypted:array',
            'headers' => 'encrypted:array',
            'config' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Agent, $this>
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_mcp_servers');
    }
}

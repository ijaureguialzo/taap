<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMcpServer extends Model
{
    public $timestamps = false;

    protected $fillable = ['agent_id', 'mcp_server_id'];

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<McpServer, $this>
     */
    public function mcpServer(): BelongsTo
    {
        return $this->belongsTo(McpServer::class);
    }
}

<?php

namespace App\Models;

use App\Agents\AgentManager;
use App\Enums\AgentRuntime;
use App\Enums\AgentStatus;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agent extends Model
{
    /**
     * Relaciones nullable en runtime (no todo agente tiene token/modelo):
     * el tag @property-read anula la inferencia de la relacion y la marca
     * como nullable, como lo es de verdad.
     *
     * @property-read AgentToken|null $token
     * @property-read \App\Models\Model|null $model
     */
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'owner_id', 'name', 'description', 'runtime', 'status', 'container_name',
        'container_id', 'ip', 'image', 'internet_enabled', 'auto_restart', 'model_id',
        'system_prompt', 'working_dir', 'config', 'last_active_at', 'started_at',
    ];

    protected function casts(): array
    {
        return [
            'runtime' => AgentRuntime::class,
            'status' => AgentStatus::class,
            'internet_enabled' => 'boolean',
            'auto_restart' => 'boolean',
            'config' => 'array',
            'last_active_at' => 'datetime',
            'started_at' => 'datetime',
        ];
    }

    /* ---------- relaciones ---------- */

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<\App\Models\Model, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Model::class);
    }

    /**
     * @return HasOne<AgentToken, $this>
     */
    public function token(): HasOne
    {
        return $this->hasOne(AgentToken::class);
    }

    /**
     * @return BelongsToMany<McpServer, $this>
     */
    public function mcpServers(): BelongsToMany
    {
        return $this->belongsToMany(McpServer::class, 'agent_mcp_servers');
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * @return HasMany<AgentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AgentEvent::class);
    }

    /* ---------- helpers de dominio ---------- */

    public function isRunning(): bool
    {
        return $this->status->isRunning();
    }

    /**
     * @param  Builder<Agent>  $query
     * @return Builder<Agent>
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('owner_id', $userId);
    }

    /** El admin ve todos; el usuario, los suyos. */
    /**
     * @param  Builder<Agent>  $query
     * @return Builder<Agent>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin()
            ? $query
            : $query->ownedBy($user->id);
    }

    /** Token plano del agente (generado una vez; nunca se vuelve a mostrar completo). */
    public function plainToken(): ?string
    {
        static $cache = [];
        if (! $this->token) {
            return null;
        }

        return $cache[$this->token->id] ?? $this->token->token;
    }

    /** Nombre de contenedor derivado (estable). */
    public function containerNameAttribute(?string $value): ?string
    {
        return $value;
    }

    public function defaultContainerName(): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $this->name));
        $slug = trim(substr($slug, 0, 40), '-');

        return config('taap.agents.container_prefix').$this->id.'-'.$slug;
    }

    /** Ruta del Model Proxy que este agente debe usar. */
    public function modelProxyUrl(): string
    {
        return '/api/proxy/agents/'.$this->id;
    }

    /** Puerto base de la puerta MCP expuesta por el orquestador. */
    public function mcpBridgeUrl(): string
    {
        return '/api/mcp/agents/'.$this->id.'/bridge';
    }

    public function manager(): AgentManager
    {
        return app(AgentManager::class);
    }
}

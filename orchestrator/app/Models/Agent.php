<?php

namespace App\Models;

use App\Agents\AgentManager;
use App\Enums\AgentRuntime;
use App\Enums\AgentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agent extends Model
{
    /** @use HasFactory<\Database\Factories\AgentFactory> */
    use HasFactory, SoftDeletes;

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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Model::class);
    }

    public function token(): HasOne
    {
        return $this->hasOne(AgentToken::class);
    }

    public function mcpServers(): HasMany
    {
        return $this->hasMany(AgentMcpServer::class)->with('mcpServer');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgentEvent::class);
    }

    /* ---------- helpers de dominio ---------- */

    public function isRunning(): bool
    {
        return $this->status->isRunning();
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('owner_id', $userId);
    }

    /** El admin ve todos; el usuario, los suyos. */
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

        return config('taap.agents.container_prefix') . $this->id . '-' . $slug;
    }

    /** Ruta del Model Proxy que este agente debe usar. */
    public function modelProxyUrl(): string
    {
        return '/api/proxy/agents/' . $this->id;
    }

    /** Puerto base de la puerta MCP expuesta por el orquestador. */
    public function mcpBridgeUrl(): string
    {
        return '/api/mcp/agents/' . $this->id . '/bridge';
    }

    public function manager(): AgentManager
    {
        return app(AgentManager::class);
    }
}

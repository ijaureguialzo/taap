<?php

namespace App\Models;

use App\Enums\AgentStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'locale',
        'active',
        'agent_quota',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'active' => 'boolean',
            'agent_quota' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class, 'owner_id');
    }

    public function runningAgentsCount(): int
    {
        return $this->agents()
            ->where('status', '!=', AgentStatus::Offline->value)
            ->where('status', '!=', AgentStatus::Stopped->value)
            ->count();
    }

    public function agentQuota(): int
    {
        return $this->agent_quota ?? (int) config('taap.agents.default_quota', 3);
    }

    public function canCreateAgent(): bool
    {
        return $this->runningAgentsCount() < $this->agentQuota();
    }
}

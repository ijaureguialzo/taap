<?php

namespace App\Models;

use App\Enums\AgentStatus;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements \Laravel\Fortify\Contracts\PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable, PasskeyAuthenticatable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'locale',
        'active',
        'agent_quota',
        'fs_slug',
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

    /**
     * FS compartido (shared-filesystem, design D4): el slug se asigna una
     * sola vez (en la creación) y permanece estable aunque cambie el nombre
     * de visualización (el slug es la fuente del mapa usuario→directorio).
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (blank($user->fs_slug)) {
                $user->fs_slug = static::resolveFsSlug($user);
            }
        });

        // shared-filesystem 2.4: todo usuario tiene su <raiz>/<fs_slug> desde
        // el alta (best-effort: si la raiz no es escribible, se avisa y la
        // creacion no falla; baseFor y taap:fs:ensure-user-dirs lo reintentan).
        static::created(function (User $user) {
            try {
                $user->ensureFsDir();
            } catch (\Throwable) {
                Log::warning('FS compartido: no se pudo crear el directorio de usuario', [
                    'user_id' => $user->id,
                    'fs_slug' => $user->fs_slug,
                ]);
            }
        });
    }

    /**
     * Slug base derivado del nombre (lowercase, transliterado); si el
     * nombre no produce alfanuméricos, el local part del email.
     */
    public static function slugifyName(string $name, ?string $email = null): string
    {
        $slug = trim(Str::slug($name), '-');

        if ($slug === '') {
            $local = ($email !== null && str_contains($email, '@')) ? explode('@', $email, 2)[0] : '';
            $slug = trim(Str::slug($local), '-');
        }

        return $slug !== '' ? $slug : 'user';
    }

    /**
     * Slug desambiguado contra el resto de usuarios: `ana`, `ana-2`,
     * `ana-3`... Solo se usa cuando el usuario aún no tiene uno.
     */
    public static function resolveFsSlug(User $user): string
    {
        $base = static::slugifyName((string) $user->name, $user->email);
        $candidate = $base;
        $suffix = 1;

        while (static::where('fs_slug', $candidate)->whereKeyNot($user->id)->exists()) {
            $candidate = $base.'-'.(++$suffix);
        }

        return $candidate;
    }

    /**
     * Backfill (migración): asigna slug a los usuarios que no lo tienen,
     * en orden de id para que el resultado sea determinista. Devuelve el
     * número de usuarios actualizados.
     */
    public static function backfillFsSlugs(): int
    {
        $updated = 0;

        static::whereNull('fs_slug')->orderBy('id')->each(function (User $user) use (&$updated) {
            $user->forceFill(['fs_slug' => static::resolveFsSlug($user)])->save();
            $updated++;
        });

        return $updated;
    }

    /**
     * Path canónico del home del usuario en el FS compartido (`/<fs_slug>`):
     * la raíz de su vista virtual (design D3).
     */
    public function fsHomePath(): ?string
    {
        return filled($this->fs_slug) ? '/'.$this->fs_slug : null;
    }

    /**
     * Asegura idempotente el directorio del usuario en el primer nivel de
     * la raíz física: `<fs_base>/<fs_slug>` (design D4: primer uso +
     * backfill). Devuelve el path (vía proceso). Sin slug: no hay nada
     * que asegurar.
     */
    public function ensureFsDir(): ?string
    {
        if (! filled($this->fs_slug)) {
            return null;
        }

        $base = rtrim((string) config('taap.fs.fs_base', '/data/fs'), '/');
        $dir = $base.'/'.$this->fs_slug;
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * @return HasMany<Agent, $this>
     */
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

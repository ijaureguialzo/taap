<?php

namespace App\Models;

use Database\Factories\ModelProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @use HasFactory<ModelProviderFactory>
 */
class ModelProvider extends Model
{
    /** @use HasFactory<ModelProviderFactory> */
    use HasFactory;

    protected $fillable = ['name', 'type', 'base_url', 'api_key', 'config', 'created_by', 'status', 'last_checked_at'];

    /** @var string proveedor que supera la comprobacion de conectividad */
    public const STATUS_OK = 'ok';

    /** @var string proveedor que no supera la comprobacion de conectividad */
    public const STATUS_ERROR = 'error';

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'config' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    public function isUnhealthy(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * @return HasMany<\App\Models\Model, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(\App\Models\Model::class, 'provider_id');
    }
}

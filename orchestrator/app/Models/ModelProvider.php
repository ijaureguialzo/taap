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

    protected $fillable = ['name', 'type', 'base_url', 'api_key', 'config', 'created_by'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'config' => 'array',
        ];
    }

    /**
     * @return HasMany<\App\Models\Model, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(\App\Models\Model::class, 'provider_id');
    }
}

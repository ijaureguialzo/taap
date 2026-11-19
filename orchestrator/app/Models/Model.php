<?php

namespace App\Models;

use Database\Factories\ModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read ModelProvider|null $provider
 */
class Model extends EloquentModel
{
    /** @use HasFactory<ModelFactory> */
    use HasFactory;

    protected $table = 'models';

    protected $fillable = [
        'provider_id', 'model_id', 'name', 'context_window', 'max_tokens', 'capabilities', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ModelProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ModelProvider::class, 'provider_id');
    }

    /**
     * @return HasMany<Agent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function fullId(): string
    {
        return $this->provider->name.'/'.$this->model_id;
    }
}

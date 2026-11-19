<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelProvider extends Model
{
    protected $fillable = ['name', 'type', 'base_url', 'api_key', 'config', 'created_by'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'config' => 'array',
        ];
    }

    public function models(): HasMany
    {
        return $this->hasMany(\App\Models\Model::class, 'provider_id');
    }
}

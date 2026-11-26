<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila de telemetría de una petición al Model Proxy (model-usage-tracking).
 * Solo lectura desde la app: escritura best-effort una vez por respuesta.
 */
class ModelUsage extends Model
{
    public $timestamps = false;

    protected $table = 'model_usage';

    protected $fillable = [
        'id',
        'agent_id',
        'provider_id',
        'model_id',
        'tokens_input',
        'tokens_output',
        'status',
        'latency_ms',
        'stream',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'stream' => 'boolean',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ModelProvider::class, 'provider_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(Model::class, 'model_id');
    }
}

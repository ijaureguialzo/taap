<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uso de modelos por petición al Model Proxy (model-usage-tracking).
 * Tabla de telemetría best-effort: si la escritura falla, el proxy sirve
 * igual la respuesta. Sin retención automática (decisión de esta versión).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('model_providers')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('models')->nullOnDelete();
            $table->unsignedInteger('tokens_input')->default(0);
            $table->unsignedInteger('tokens_output')->default(0);
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->boolean('stream')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agent_id', 'created_at']);
            $table->index(['provider_id', 'created_at']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_usage');
    }
};

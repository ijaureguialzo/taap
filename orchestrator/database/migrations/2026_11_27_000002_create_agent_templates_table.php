<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de agente (agent-templates): presets por usuario con
 * runtime/modelo/MCP/internet/límites como punto de partida del flujo
 * de creación. CASCADE: al borrar un usuario, sus plantillas también.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('runtime', 32);
            $table->foreignId('model_id')->nullable()->constrained('models')->nullOnDelete();
            $table->json('mcp_server_ids')->nullable();
            $table->boolean('internet')->default(true);
            $table->decimal('cpu', 5, 2)->nullable();
            $table->string('memory', 32)->nullable();
            $table->timestamps();

            $table->unique(['owner_id', 'name']);
            $table->index(['owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_templates');
    }
};

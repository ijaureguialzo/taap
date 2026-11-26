<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria de acciones de administrador (admin-audit-trail).
 * Tabla inmutable: las filas se escriben en el mismo request de la operacion
 * admin; no hay UPDATE ni DELETE desde la app (solo lectura).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 60);
            $table->string('target_type', 40);
            $table->string('target_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};

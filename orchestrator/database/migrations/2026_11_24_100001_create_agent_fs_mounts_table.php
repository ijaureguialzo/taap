<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS compartido (shared-filesystem, design D4): carpetas del arbol
 * conectado a cada agente, con modo de acceso. `path` es el path CANONICO
 * dentro del arbol (/<fs_slug>/carpeta; design D3) y es la fuente unica de
 * los mounts del contenedor, el prompt del agente y la UI. Es TABLA (no
 * JSON en agent.config): es consultable ("que agentes tienen conectada esta
 * carpeta") y valida mejor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_fs_mounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('mode', 16)->default('read_write');
            $table->timestamps();

            $table->unique(['agent_id', 'path']);
            $table->index(['agent_id', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_fs_mounts');
    }
};

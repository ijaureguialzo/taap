<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Búsqueda full-text sobre chat_messages (chat-search-export):
 * columna generada tsvector + índice GIN (solo Postgres).
 * En sqlite no hay columna generada: el servicio de búsqueda usa un
 * LIKE equivalente (misma escala de datos, el servidor real es Postgres).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->tsvector('body_tsvector')
                ->virtualAs("to_tsvector('simple', coalesce(content, ''))", true);
        });

        // Índice GIN (Postgres): indexamos la columna generada.
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->index('body_tsvector', 'chat_messages_body_tsvector_gin', 'gin');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex('chat_messages_body_tsvector_gin');
            $table->dropColumn('body_tsvector');
        });
    }
};

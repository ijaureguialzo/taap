<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_providers', function (Blueprint $table) {
            // Salud persistida (design D2): null = nunca comprobado,
            // 'ok' = sano, 'error' = no sano. last_checked_at = fecha de la
            // última comprobación de conectividad.
            $table->string('status', 16)->nullable();
            $table->timestamp('last_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('model_providers', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_checked_at']);
        });
    }
};

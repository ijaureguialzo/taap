<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS compartido (shared-filesystem, design D4): slug estable por usuario,
 * nombre del directorio en el primer nivel de la raíz física. Se asigna
 * una vez (creación o backfill) y permanece estable aunque cambie el nombre
 * de visualización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('fs_slug', 60)->nullable()->unique()->after('agent_quota');
        });

        User::backfillFsSlugs();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['fs_slug']);
            $table->dropColumn('fs_slug');
        });
    }
};

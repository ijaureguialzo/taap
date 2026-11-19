<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('user')->index()->after('password');
            $table->boolean('active')->default(true)->after('role');
            $table->string('locale', 2)->default('es')->after('active');
            $table->unsignedSmallInteger('agent_quota')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'active', 'locale', 'agent_quota']);
        });
    }
};

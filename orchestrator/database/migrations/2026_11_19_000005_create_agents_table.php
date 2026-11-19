<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name')->index();
            $table->string('description')->nullable();
            $table->string('runtime', 32)->index(); // pi | hermes | ...
            $table->string('status', 24)->default('offline')->index(); // offline|starting|idle|working|waiting-approval|error|stopped
            $table->string('container_name')->nullable()->unique();
            $table->string('container_id')->nullable()->index();
            $table->string('ip', 45)->nullable(); // IP fija en taap-internal
            $table->string('image');
            $table->boolean('internet_enabled')->default(false);
            $table->boolean('auto_restart')->default(true);
            $table->foreignId('model_id')->nullable()->constrained('models')->nullOnDelete();
            $table->text('system_prompt')->nullable();
            $table->string('working_dir')->nullable();
            $table->json('config')->nullable(); // ajustes extra por runtime
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'status']);
        });

        // sqlite no soporta ->where() en indexes: parcial a manopla
        DB::statement('CREATE UNIQUE INDEX agents_name_active ON agents (name) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};

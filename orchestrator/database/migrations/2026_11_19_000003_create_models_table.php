<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('model_providers')->cascadeOnDelete();
            $table->string('model_id'); // id del modelo en el proveedor
            $table->string('name');
            $table->unsignedInteger('context_window')->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->json('capabilities')->nullable(); // ["tools","vision","streaming"]
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['provider_id', 'model_id']);
            $table->index(['provider_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('models');
    }
};

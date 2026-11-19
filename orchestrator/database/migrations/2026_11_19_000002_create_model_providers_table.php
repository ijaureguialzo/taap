<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type', 32)->default('openai'); // openai | anthropic | other
            $table->string('base_url');
            $table->text('api_key')->nullable(); // cifrado (cast encrypted)
            $table->json('config')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_providers');
    }
};

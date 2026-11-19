<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->unique()->constrained('agents')->cascadeOnDelete();
            $table->text('token'); // cifrado; necesario para renderizar la config
            $table->string('token_hash', 64)->unique(); // sha256 para lookup en el proxy
            $table->string('token_prefix', 12); // para mostrar en la UI
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tokens');
    }
};

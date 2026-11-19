<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('type', 32)->index(); // message.delta|message.final|tool.start|...
            $table->json('payload');
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['agent_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_events');
    }
};

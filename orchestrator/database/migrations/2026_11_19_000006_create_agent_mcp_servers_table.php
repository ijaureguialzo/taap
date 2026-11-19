<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_mcp_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('mcp_server_id')->constrained('mcp_servers')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['agent_id', 'mcp_server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_mcp_servers');
    }
};

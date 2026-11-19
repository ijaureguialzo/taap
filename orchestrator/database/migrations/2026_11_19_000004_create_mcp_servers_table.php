<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type', 16)->default('url'); // stdio | url
            $table->string('command')->nullable(); // stdio
            $table->json('args')->nullable();
            $table->json('env')->nullable(); // pares nombre->valor (secrets cifrados)
            $table->string('url')->nullable(); // url
            $table->json('headers')->nullable();
            $table->json('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_servers');
    }
};

<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\McpServer;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Database\Seeder;

class TaapSeeder extends Seeder
{
    public function run(): void
    {
        // Usuario admin inicial (contrasena: taap1234 - cambiar en el primer login)
        $admin = User::firstOrCreate(
            ['email' => 'admin@taap.local'],
            [
                'name' => 'Administrador',
                'password' => 'taap1234',
                'role' => UserRole::Admin,
                'locale' => 'es',
                'email_verified_at' => now(),
            ]
        );

        // Provider de ejemplo: el Model Proxy se apoya en claves de estos
        $provider = ModelProvider::firstOrCreate(
            ['name' => 'openai'],
            [
                'type' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'created_by' => $admin->id,
            ]
        );

        Model::firstOrCreate(
            ['provider_id' => $provider->id, 'model_id' => 'gpt-5.2'],
            ['name' => 'GPT 5.2', 'context_window' => 272000, 'max_tokens' => 128000]
        );
        Model::firstOrCreate(
            ['provider_id' => $provider->id, 'model_id' => 'gpt-5.2-mini'],
            ['name' => 'GPT 5.2 Mini', 'context_window' => 128000, 'max_tokens' => 64000]
        );

        // Servidor MCP de ejemplo (filesystem via stdio, dentro del contenedor)
        McpServer::firstOrCreate(
            ['name' => 'filesystem'],
            [
                'type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/workspace'],
                'created_by' => $admin->id,
            ]
        );
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_200_when_database_is_up(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_health_endpoint_returns_500_when_database_is_unreachable(): void
    {
        config([
            'app.debug' => false,
            'database.connections.broken' => [
                'driver' => 'pgsql',
                'url' => null,
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'broken',
                'username' => 'broken',
                'password' => 'broken',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
            ],
        ]);
        config(['database.default' => 'broken']);

        $this->get('/up')->assertStatus(500);
    }
}

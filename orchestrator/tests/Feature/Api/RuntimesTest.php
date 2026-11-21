<?php

namespace Tests\Feature\Api;

use App\Enums\AgentRuntime;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registro de runtimes expuesto por la API (ios-model-runtime-compatibility,
 * spec agent-runtimes "Registro expuesto a los clientes"): la lista contiene
 * exactamente los runtimes del registro, con id, etiqueta y formatos.
 */
class RuntimesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_receives_exactly_the_registry_runtimes(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/runtimes')
            ->assertOk()
            ->assertJson([
                'runtimes' => [
                    ['id' => 'pi', 'label' => 'Pi', 'formats' => ['openai']],
                    ['id' => 'hermes', 'label' => 'Hermes', 'formats' => ['openai']],
                    ['id' => 'claude', 'label' => 'Claude Code', 'formats' => ['anthropic']],
                    ['id' => 'codex', 'label' => 'Codex', 'formats' => ['openai']],
                    ['id' => 'opencode', 'label' => 'OpenCode', 'formats' => ['openai', 'anthropic']],
                ],
            ]);

        // "Exactamente" los del registro: ni mas ni menos, en orden de registro.
        $body = $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/runtimes')
            ->json('runtimes');

        $this->assertCount(5, $body);
        $this->assertSame(AgentRuntime::supported(), array_column($body, 'id'));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/runtimes')->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 10.3: pase de seguridad.
 *   - Autorizacion por token en API y en el auth del canal WSS
 *   - Enmascarado de secretos (claves de proveedor) en vistas y payloads
 *   - CSRF en el formulario web de login
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'es']);
    }

    /* ---------- API: token por usuario ---------- */

    public function test_api_requires_token(): void
    {
        $user = User::factory()->create();
        Agent::factory()->create(['owner_id' => $user->id]);

        $this->getJson('/api/v1/agents')->assertUnauthorized();
    }

    public function test_api_token_is_per_user(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/agents')
            ->assertOk()
            ->assertJsonCount(1, 'agents');

        // El extraño (con su propio token) no ve el agente del dueño.
        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/v1/agents')
            ->assertOk()
            ->assertJsonCount(0, 'agents');
    }

    public function test_api_rejects_invalid_token(): void
    {
        $this->getJson('/api/v1/agents', [
            'Authorization' => 'Bearer inexistente',
        ])->assertUnauthorized();
    }

    public function test_admin_can_see_others_agents(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        Agent::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/agents')
            ->assertOk()
            ->assertJsonCount(1, 'agents');
    }

    /* ---------- WSS: autorizacion de canal firmada ---------- */

    public function test_wss_auth_requires_token(): void
    {
        $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'agent.1',
            'socket_id' => '123.456',
        ])->assertUnauthorized();
    }

    public function test_wss_auth_owns_or_admin_only(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $agent = Agent::factory()->create(['owner_id' => $owner->id]);

        $body = [
            'channel_name' => 'agent.'.$agent->id,
            'socket_id' => '123.456',
        ];

        // Dueño: 200 con firma { key: hmac }.
        $resp = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', $body)
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9_-]+:[0-9a-f]{64}$/',
            (string) $resp->json('auth')
        );

        // Extraño: 403.
        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', $body)
            ->assertForbidden();

        // Admin: 200.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', $body)
            ->assertOk();
    }

    public function test_wss_auth_rejects_foreign_channels(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', [
                'channel_name' => 'othertopic.7',
                'socket_id' => '1.2',
            ])->assertForbidden();
    }

    /* ---------- Enmascarado de secretos ---------- */

    public function test_provider_key_never_leaks_via_api(): void
    {
        $admin = User::factory()->admin()->create();
        $provider = ModelProvider::factory()->create([
            'name' => 'secreto',
            'api_key' => 'sk-super-secreta-1234',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/providers')
            ->assertOk()
            ->assertJsonPath('providers.0.has_key', true)
            ->assertDontSee('sk-super-secreta-1234');
    }

    public function test_provider_key_never_renders_in_web(): void
    {
        $admin = User::factory()->admin()->create();
        ModelProvider::factory()->create([
            'name' => 'secreto',
            'api_key' => 'sk-super-secreta-1234',
        ]);

        // La web (cookies de sesion) renderiza la lista y el formulario
        // de edición sin la clave en claro.
        $this->actingAs($admin)
            ->get('/providers')
            ->assertOk()
            ->assertDontSee('sk-super-secreta-1234');
    }

    public function test_api_update_without_key_keeps_it(): void
    {
        $admin = User::factory()->admin()->create();
        $provider = ModelProvider::factory()->create([
            'name' => 'prov',
            'api_key' => 'sk-mantener',
        ]);

        // Update sin api_key: la clave existente se conserva.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/providers/{$provider->id}", [
                'name' => 'prov-renombrado',
                'type' => $provider->type,
                'base_url' => $provider->base_url,
            ])
            ->assertOk();

        $this->assertSame('sk-mantener', $provider->refresh()->api_key);

        // Update con api_key null: se borra.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/providers/{$provider->id}", [
                'name' => 'prov-renombrado',
                'type' => $provider->type,
                'base_url' => $provider->base_url,
                'api_key' => null,
            ])
            ->assertOk();

        $this->assertNull($provider->refresh()->api_key);
    }

    /* ---------- CSRF web ---------- */

    public function test_login_form_requires_csrf_token(): void
    {
        $user = User::factory()->create();

        // POST sin _token: rechazado (419).
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'contraseña',
        ])->assertSessionHasErrors();

        // Con _token del formulario: el intento de login sí se procesa
        // (falla por credenciales, pero no por CSRF).
        $this->withSession(['_token' => csrf_token()])
            ->post('/login', [
                '_token' => csrf_token(),
                'email' => $user->email,
                'password' => 'incorrecta',
            ])->assertSessionHasErrors('email');
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Api User',
            'email' => 'api-user@taap.test',
            'locale' => 'es',
        ]);
    }

    protected function actingAsUser(): self
    {
        $this->actingAs($this->user, 'sanctum');

        return $this;
    }

    public function test_user_can_update_name_and_locale_without_password(): void
    {
        $this->actingAsUser()
            ->putJson('/api/v1/auth/profile', [
                'name' => 'Nuevo Nombre',
                'locale' => 'eu',
            ])
            ->assertOk()
            ->assertJson(['user' => ['name' => 'Nuevo Nombre', 'locale' => 'eu']]);

        $this->assertSame('Nuevo Nombre', $this->user->fresh()->name);
        $this->assertSame('eu', $this->user->fresh()->locale);
    }

    public function test_password_change_requires_current_password(): void
    {
        $this->actingAsUser()
            ->putJson('/api/v1/auth/profile', [
                'name' => 'Api User',
                'locale' => 'es',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertFalse(Hash::check('password123', $this->user->fresh()->password));
    }

    public function test_password_change_requires_current_password_match(): void
    {
        $this->actingAsUser()
            ->putJson('/api/v1/auth/profile', [
                'name' => 'Api User',
                'locale' => 'es',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'current_password' => 'incorrecta-123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertFalse(Hash::check('password123', $this->user->fresh()->password));
    }

    public function test_password_change_applies_and_keeps_current_token(): void
    {
        // Token real (actingAs no pasa token): el keep-token rule de
        // changePassword requiere un token de Bearer real.
        $plain = $this->user->createToken('api')->plainTextToken;

        $this->withToken($plain)
            ->putJson('/api/v1/auth/profile', [
                'name' => 'Api User',
                'locale' => 'es',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'current_password' => 'password',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('password123', $this->user->fresh()->password));
        $this->assertSame(1, $this->user->tokens()->count(), 'el token actual sobrevive al cambio');
    }

    public function test_password_change_revokes_other_tokens(): void
    {
        $plain = $this->user->createToken('api')->plainTextToken;
        $this->user->createToken('otra');

        $this->withToken($plain)
            ->putJson('/api/v1/auth/profile', [
                'name' => 'Api User',
                'locale' => 'es',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'current_password' => 'password',
            ])
            ->assertOk();

        $this->assertSame(1, $this->user->tokens()->count(), 'solo sobrevive el token actual');
        $this->assertSame('api', $this->user->tokens()->first()->name);
    }
}

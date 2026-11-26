<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'twofa-api@taap.test',
        ]);
        $this->actingAs($this->user, 'sanctum');
    }

    private function loginWithPassword(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
    }

    public function test_user_without_two_factor_receives_token_on_login(): void
    {
        $response = $this->loginWithPassword($this->user);

        $response->assertOk()
            ->assertJson(['requires_two_factor' => false])
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_user_with_two_factor_enabled_is_challenged_on_login(): void
    {
        $this->user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $response = $this->loginWithPassword($this->user);

        $response->assertOk()
            ->assertJson(['requires_two_factor' => true])
            ->assertJsonStructure(['requires_two_factor', 'challenge'])
            ->assertJsonMissing(['token']);
    }

    public function test_recovery_code_login_works(): void
    {
        $this->user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $loginResponse = $this->loginWithPassword($this->user);
        $challenge = $loginResponse->json('challenge');

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge' => $challenge,
            'recovery_code' => 'recovery-code-1',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user']);

        // El código queda consumido (reemplazado).
        $fresh = $this->user->fresh();
        $codes = $fresh->recoveryCodes();
        $this->assertCount(2, $codes);
        $this->assertNotContains('recovery-code-1', $codes);
    }

    public function test_enable_confirm_and_disable_two_factor_via_api(): void
    {
        // Enable
        $response = $this->postJson('/api/v1/auth/profile/two-factor');
        $response->assertOk()
            ->assertJson(['enabled' => false, 'setup_pending' => true])
            ->assertJsonStructure(['secret', 'recovery_codes']);

        $secret = $response->json('secret');

        // Confirm con código real
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->postJson('/api/v1/auth/profile/two-factor/confirm', ['code' => $code])
            ->assertOk()
            ->assertJson(['enabled' => true]);

        $this->assertNotNull($this->user->fresh()->two_factor_confirmed_at);

        // Disable
        $this->deleteJson('/api/v1/auth/profile/two-factor')
            ->assertOk()
            ->assertJson(['enabled' => false]);

        $this->assertNull($this->user->fresh()->two_factor_secret);
    }

    public function test_status_includes_setup_pending(): void
    {
        $this->postJson('/api/v1/auth/profile/two-factor');

        $this->getJson('/api/v1/auth/profile/two-factor')
            ->assertOk()
            ->assertJson(['enabled' => false, 'setup_pending' => true]);
    }

    public function test_passkey_list_and_delete_via_api(): void
    {
        $passkey = $this->user->passkeys()->create([
            'name' => 'MacBook',
            'credential_id' => 'credid-'.uniqid(),
            'credential' => ['id' => 'credid-', 'rawId' => 'raw', 'type' => 'public-key', 'response' => []],
        ]);

        $this->getJson('/api/v1/auth/profile/passkeys')
            ->assertOk()
            ->assertJsonPath('passkeys.0.name', 'MacBook');

        $this->deleteJson('/api/v1/auth/profile/passkeys/'.$passkey->id)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
    }

    public function test_passkey_delete_of_other_user_returns_404(): void
    {
        $other = User::factory()->create(['email' => 'other-twofa@taap.test']);
        $passkey = $other->passkeys()->create([
            'name' => 'Other',
            'credential_id' => 'credid-'.uniqid(),
            'credential' => ['id' => 'credid-', 'rawId' => 'raw', 'type' => 'public-key', 'response' => []],
        ]);

        $this->deleteJson('/api/v1/auth/profile/passkeys/'.$passkey->id)
            ->assertStatus(404);
    }
}

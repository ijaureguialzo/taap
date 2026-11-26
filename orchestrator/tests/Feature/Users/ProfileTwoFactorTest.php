<?php

namespace Tests\Feature\Users;

use App\Livewire\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'twofa-user@taap.test',
        ]);
        $this->actingAs($this->user);
    }

    public function test_user_can_enable_and_confirm_two_factor_authentication(): void
    {
        // Activar: crea el secret sin confirmar.
        Livewire::test(Profile::class)
            ->call('enableTwoFactor');

        $user = $this->user->fresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        // Confirmar con un código válido.
        $code = $this->validTotpCode($user);

        Livewire::test(Profile::class)
            ->set('twoFactorCode', $code)
            ->call('confirmTwoFactor')
            ->assertHasNoErrors();

        $user = $this->user->fresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertNotEmpty($user->recoveryCodes());
    }

    public function test_invalid_totp_code_is_rejected(): void
    {
        Livewire::test(Profile::class)
            ->call('enableTwoFactor');

        Livewire::test(Profile::class)
            ->set('twoFactorCode', '000000')
            ->call('confirmTwoFactor')
            ->assertHasErrors(['twoFactorCode']);

        $this->assertNull($this->user->fresh()->two_factor_confirmed_at);
    }

    public function test_disabled_two_factor_flow_can_be_cancelled(): void
    {
        Livewire::test(Profile::class)
            ->call('enableTwoFactor');

        Livewire::test(Profile::class)
            ->call('cancelSetup');

        $this->assertNull($this->user->fresh()->two_factor_secret);
    }

    public function test_user_can_disable_two_factor_authentication(): void
    {
        $this->user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        Livewire::test(Profile::class)
            ->call('disableTwoFactor')
            ->assertHasNoErrors();

        $fresh = $this->user->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_confirmed_at);
    }

    public function test_recovery_codes_are_regenerated(): void
    {
        $this->user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['old-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        Livewire::test(Profile::class)
            ->call('regenerateRecoveryCodes');

        $fresh = $this->user->fresh();
        $codes = $fresh->recoveryCodes();
        $this->assertCount(8, $codes);
        $this->assertNotContains('old-code', $codes);
    }

    public function test_user_can_delete_a_passkey(): void
    {
        $passkey = $this->user->passkeys()->create([
            'name' => 'MacBook',
            'credential_id' => 'credid-'.uniqid(),
            'credential' => ['id' => 'credid-', 'rawId' => 'raw', 'type' => 'public-key', 'response' => []],
        ]);

        Livewire::test(Profile::class)
            ->call('deletePasskey', $passkey->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
    }

    private function validTotpCode(User $user): string
    {
        $secret = decrypt($user->two_factor_secret);
        $google2fa = app(\PragmaRX\Google2FA\Google2FA::class);

        return $google2fa->getCurrentOtp($secret);
    }
}

<?php

namespace App\Livewire;

use App\Support\UserLifecycle;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Component;
use Laravel\Passkeys\Actions\DeletePasskey;

class Profile extends Component
{
    public string $name = '';

    public string $locale = 'es';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    // --- 2FA (TOTP) ---
    public string $twoFactorCode = '';

    public bool $setupMode = false;

    public ?string $setupSecret = null;

    public ?string $setupQrSvg = null;

    public array $recoveryCodes = [];

    public string $passkeyName = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->locale = (string) $user->locale;
    }

    public function enableTwoFactor(): void
    {
        $user = auth()->user();

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            session()->flash('error', __('profile.two_factor_already_enabled'));

            return;
        }

        app(EnableTwoFactorAuthentication::class)($user);
        $user->refresh();

        $this->setupMode = true;
        $this->setupSecret = decrypt($user->two_factor_secret);
        $this->setupQrSvg = $user->twoFactorQrCodeSvg();
        $this->recoveryCodes = [];
    }

    public function confirmTwoFactor(): void
    {
        $this->validate([
            'twoFactorCode' => ['required', 'string'],
        ]);

        $user = auth()->user();

        if (blank($user->two_factor_secret)) {
            $this->setupMode = false;
            $this->setupSecret = null;
            $this->setupQrSvg = null;
            $this->recoveryCodes = [];
            $this->dispatch('twoFactorSetupCancelled');

            return;
        }

        try {
            app(ConfirmTwoFactorAuthentication::class)($user, $this->twoFactorCode);
        } catch (\Throwable) {
            $this->addError('twoFactorCode', __('profile.two_factor_invalid_code'));

            return;
        }

        $user->refresh();
        $this->setupMode = false;
        $this->setupSecret = null;
        $this->setupQrSvg = null;
        $this->twoFactorCode = '';
        $this->recoveryCodes = $user->recoveryCodes() ?? [];

        session()->flash('success', __('profile.two_factor_enabled'));
    }

    public function cancelSetup(): void
    {
        // Anula la fase de configuración SIN habilitar nada: borra el
        // secret pendiente para que el usuario empiece de nuevo.
        $user = auth()->user();

        if ($user->two_factor_secret && ! $user->two_factor_confirmed_at) {
            app(DisableTwoFactorAuthentication::class)($user);
        }

        $this->setupMode = false;
        $this->setupSecret = null;
        $this->setupQrSvg = null;
        $this->twoFactorCode = '';
        $this->dispatch('twoFactorSetupCancelled');
    }

    public function disableTwoFactor(): void
    {
        $user = auth()->user();

        if (! $user->two_factor_secret) {
            session()->flash('error', __('profile.two_factor_not_enabled'));

            return;
        }

        app(DisableTwoFactorAuthentication::class)($user);

        $this->setupMode = false;
        $this->setupSecret = null;
        $this->setupQrSvg = null;
        $this->recoveryCodes = [];

        session()->flash('success', __('profile.two_factor_disabled'));
    }

    public function regenerateRecoveryCodes(): void
    {
        $user = auth()->user();

        if (! $user->two_factor_secret || ! $user->two_factor_confirmed_at) {
            session()->flash('error', __('profile.two_factor_not_enabled'));

            return;
        }

        app(GenerateNewRecoveryCodes::class)($user);
        $user->refresh();

        $this->recoveryCodes = $user->recoveryCodes() ?? [];

        session()->flash('success', __('profile.recovery_codes_regenerated'));
    }

    public function dismissRecoveryCodes(): void
    {
        $this->recoveryCodes = [];
    }

    public function deletePasskey(int $passkeyId): void
    {
        $user = auth()->user();
        $passkey = $user->passkeys()->whereKey($passkeyId)->first();

        if ($passkey) {
            app(DeletePasskey::class)($user, $passkey);
            session()->flash('success', __('profile.passkey_deleted'));
        }
    }

    public function save(): void
    {
        $user = auth()->user();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['required', Rule::in(['es', 'en', 'eu'])],
        ];

        if ($this->password !== '') {
            $rules['password'] = ['required', 'string', Password::min(8), 'confirmed'];
            $rules['current_password'] = ['required', 'string', 'current_password'];
        }

        $this->validate($rules);

        $user->forceFill(['name' => $this->name, 'locale' => $this->locale])->save();
        session()->put('locale', $this->locale);
        app()->setLocale($this->locale);

        if ($this->password !== '') {
            app(UserLifecycle::class)->changePassword(
                $user,
                $this->password,
                keepSessionId: session()->getId(),
            );
            $this->reset(['current_password', 'password', 'password_confirmation']);
        }

        session()->flash('success', __('profile.saved'));
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.profile.index', [
            'user' => $user,
            'twoFactorEnabled' => (bool) ($user->two_factor_secret && $user->two_factor_confirmed_at),
            'twoFactorSetupMode' => $this->setupMode,
            'setupSecret' => $this->setupSecret,
            'setupQrSvg' => $this->setupQrSvg,
            'recoveryCodes' => $this->recoveryCodes,
            'passkeys' => $user->passkeys()->get(),
        ]);
    }
}

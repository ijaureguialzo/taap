<div class="profile">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><x-icon name="person-circle" class="me-2" />{{ __('profile.title') }}</h1>
        <a href="{{ route('agents.index') }}" wire:navigate class="btn btn-outline-secondary btn-sm">← {{ __('actions.back') }}</a>
    </div>

    <div class="card">
        <div class="card-body">
            <section>
                <h2 class="h6 mb-3">{{ __('profile.account') }}</h2>
                <div class="mb-3">
                    <label class="form-label" for="profile-name">{{ __('users.name') }}</label>
                    <input type="text" id="profile-name" wire:model="name" class="form-control @error('name') is-invalid @enderror" maxlength="255">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="profile-email">{{ __('users.email') }}</label>
                    <input type="email" id="profile-email" class="form-control" value="{{ $user->email }}" readonly tabindex="-1" aria-readonly="true">
                    <div class="form-text">{{ __('profile.email_readonly') }}</div>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="profile-locale">{{ __('users.language') }}</label>
                    <select id="profile-locale" wire:model.live="locale" class="form-select">
                        <option value="es">es</option>
                        <option value="en">en</option>
                        <option value="eu">eu</option>
                    </select>
                </div>
            </section>
        </div>

        <div class="card-body border-top">
            <section>
                <h2 class="h6 mb-2">{{ __('profile.password_title') }}</h2>
                <p class="small text-muted mb-3">{{ __('profile.password_hint') }}</p>
                <div class="mb-3">
                    <label class="form-label" for="current-password">{{ __('profile.current_password') }}</label>
                    <input type="password" id="current-password" wire:model="current_password" class="form-control @error('current_password') is-invalid @enderror" autocomplete="current-password">
                    @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="new-password">{{ __('profile.new_password') }}</label>
                    <input type="password" id="new-password" wire:model="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password">
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="new-password-confirmation">{{ __('profile.new_password_confirmation') }}</label>
                    <input type="password" id="new-password-confirmation" wire:model="password_confirmation" class="form-control" autocomplete="new-password">
                </div>
                <button class="btn btn-primary" wire:click="save"><x-icon name="check-lg" class="me-1" />{{ __('actions.save') }}</button>
            </section>
        </div>

        <div class="card-body border-top">
            <section>
                <h2 class="h6 mb-2"><x-icon name="shield-check" class="me-2" />{{ __('profile.two_factor_title') }}</h2>
                <p class="small text-muted mb-3">{{ __('profile.two_factor_hint') }}</p>

                @if ($recoveryCodes !== [])
                    <div class="alert alert-warning" role="alert">
                        <h3 class="h6 mb-2">{{ __('profile.recovery_codes_title') }}</h3>
                        <p class="small mb-2">{{ __('profile.recovery_codes_hint') }}</p>
                        <ul class="mb-2 font-monospace small">
                            @foreach ($recoveryCodes as $code)
                                <li>{{ $code }}</li>
                            @endforeach
                        </ul>
                        <button type="button" class="btn btn-sm btn-success" wire:click="dismissRecoveryCodes">{{ __('profile.recovery_codes_dismiss') }}</button>
                    </div>
                @endif

                @if ($twoFactorSetupMode)
                    <div class="p-3 border rounded-3 bg-light mb-3">
                        <p class="small mb-3">{{ __('profile.two_factor_setup_qr') }}</p>
                        @if ($setupQrSvg)
                            <div class="d-flex justify-content-center mb-3">{!! $setupQrSvg !!}</div>
                        @endif
                        <p class="small text-muted text-center mb-2">{{ __('profile.two_factor_enter_code') }}</p>
                        <input type="text" class="form-control text-center font-monospace mb-2" wire:model="twoFactorCode" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]*" maxlength="6">
                        @error('twoFactorCode')<div class="text-danger small mb-2 text-center">{{ $message }}</div>@enderror
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-primary" wire:click="confirmTwoFactor">{{ __('profile.two_factor_confirm') }}</button>
                            <button type="button" class="btn btn-outline-secondary" wire:click="cancelSetup">{{ __('profile.two_factor_cancel') }}</button>
                        </div>
                    </div>
                @elseif ($twoFactorEnabled)
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="regenerateRecoveryCodes">{{ __('profile.recovery_codes_regenerate') }}</button>
                        <button type="button" class="btn btn-outline-danger btn-sm" wire:confirm="{{ __('profile.two_factor_disable') }}" wire:click="disableTwoFactor">{{ __('profile.two_factor_disable') }}</button>
                    </div>
                @else
                    <button type="button" class="btn btn-primary" wire:click="enableTwoFactor"><x-icon name="shield-check" class="me-1" />{{ __('profile.two_factor_enable') }}</button>
                @endif
            </section>
        </div>

        <div class="card-body border-top">
            <section>
                <h2 class="h6 mb-2"><x-icon name="key" class="me-2" />{{ __('profile.passkey_title') }}</h2>
                <p class="small text-muted mb-3">{{ __('profile.passkey_hint') }}</p>

                @forelse ($passkeys as $passkey)
                    <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2" data-passkey-id="{{ $passkey->id }}">
                        <div>
                            <div class="small fw-semibold">{{ $passkey->name }}</div>
                            <div class="text-muted small">{{ $passkey->authenticator ?? $passkey->authenticator_icon_light ? '—' : '—' }}</div>
                        </div>
                        <button type="button" class="btn btn-outline-danger btn-sm" wire:click="deletePasskey({{ $passkey->id }})" wire:confirm="{{ __('profile.passkey_delete') }}">{{ __('profile.passkey_delete') }}</button>
                    </div>
                @empty
                    <p class="small text-muted">{{ __('profile.passkey_empty') }}</p>
                @endforelse

                <div class="d-flex gap-2 mt-2">
                    <input type="text" class="form-control form-control-sm" placeholder="{{ __('profile.passkey_name_label') }}" wire:model="passkeyName" maxlength="64">
                    <button type="button" class="btn btn-primary btn-sm" id="register-passkey-btn">{{ __('profile.passkey_register') }}</button>
                </div>
            </section>
        </div>
    </div>
</div>

@if (! $twoFactorEnabled && ! $twoFactorSetupMode)
    {{-- Enlace para las acciones de passkey, solo cuando hace falta (botón JS) --}}
@endif

<script>
// Registrar passkey (WebAuthn) vía endpoints de Fortify.
document.addEventListener('DOMContentLoaded', function () {
    function b64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        bytes.forEach(function (b) { binary += String.fromCharCode(b); });
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    function b64ToArr(s) {
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        const bin = atob(s);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return bytes.buffer;
    }

    document.getElementById('register-passkey-btn')?.addEventListener('click', async function () {
        const name = (document.querySelector('input[wire\:model="passkeyName"]')?.value || '').trim();
        if (!name) return;
        try {
            const options = await (await fetch('/user/passkeys/options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ name }),
            })).json();
            const cred = await navigator.credentials.create({ publicKey: options });
            const form = new FormData();
            form.append('name', name);
            form.append('credential', JSON.stringify({
                id: cred.id,
                rawId: b64(cred.rawId),
                type: cred.type,
                response: {
                    attestationObject: cred.response.attestationObject ? b64(cred.response.attestationObject) : undefined,
                    clientDataJSON: b64(cred.response.clientDataJSON),
                    transports: cred.response.transports,
                },
                clientExtensionResults: cred.getClientExtensionResults(),
            }));
            const res = await fetch('/user/passkeys', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: form,
            });
            if (!res.ok) throw new Error('registration failed');
            window.location.reload();
        } catch (e) {
            alert('{{ __('profile.passkey_registration_failed') }}');
        }
    });
});
</script>

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
    </div>
</div>

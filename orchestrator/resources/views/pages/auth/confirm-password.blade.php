<x-layouts::auth :title="__('auth.confirm_title')">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-2">{{ __('auth.confirm_title') }}</h1>
            <p class="text-muted small mb-4">{{ __('auth.confirm_help') }}</p>

            <form method="POST" action="{{ route('password.confirm') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label">{{ __('login.password') }}</label>
                    <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                           required autofocus autocomplete="current-password">
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn btn-primary w-100">{{ __('auth.confirm_submit') }}</button>
            </form>
        </div>
    </div>
</x-layouts::auth>

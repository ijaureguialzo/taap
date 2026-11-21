<x-layouts::auth :title="__('login.title')">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-4">{{ __('login.title') }}</h1>

            <form method="POST" action="{{ route('login.store') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label">{{ __('login.email') }}</label>
                    <div class="input-group">
                        <span class="input-group-text"><x-icon name="envelope" /></span>
                        <input type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror"
                               required autofocus autocomplete="email">
                    </div>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('login.password') }}</label>
                    <div class="input-group">
                        <span class="input-group-text"><x-icon name="key" /></span>
                        <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                               required autocomplete="current-password">
                    </div>
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember">
                    <label class="form-check-label" for="remember">{{ __('login.remember', ['d' => 'Recuérdame']) }}</label>
                </div>
                <button type="submit" class="btn btn-primary w-100"><x-icon name="person" class="me-1" />{{ __('login.submit') }}</button>
            </form>

            <p class="text-center small text-muted mt-3 mb-0">
                <a href="{{ route('register') }}" class="text-decoration-none">{{ __('login.register') }}</a>
            </p>
        </div>
    </div>
</x-layouts::auth>

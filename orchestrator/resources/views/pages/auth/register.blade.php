<x-layouts::auth :title="__('register.title')">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-4">{{ __('register.title') }}</h1>

            <form method="POST" action="{{ route('register.store') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label">{{ __('register.name') }}</label>
                    <input type="text" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror"
                           required autofocus autocomplete="name">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('register.email') }}</label>
                    <input type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror"
                           required autocomplete="email">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('register.password') }}</label>
                    <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                           required autocomplete="new-password">
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('register.password_confirmation') }}</label>
                    <input type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary w-100">{{ __('register.submit') }}</button>
            </form>

            <p class="text-center small text-muted mt-3 mb-0">
                <a href="{{ route('login') }}" class="text-decoration-none">{{ __('register.login') }}</a>
            </p>
        </div>
    </div>
</x-layouts::auth>

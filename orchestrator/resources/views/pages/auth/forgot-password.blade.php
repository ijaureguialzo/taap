<x-layouts::auth :title="'Restablecer contraseña'">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-3">Restablecer contraseña</h1>

            <p class="text-muted small mb-3">
                Indica tu correo: te enviaremos un enlace para restablecerla.
            </p>

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label">Correo electrónico</label>
                    <input type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required autofocus autocomplete="email">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn btn-primary w-100">Enviar enlace</button>
            </form>

            <p class="text-center small text-muted mt-3 mb-0">
                <a href="{{ route('login') }}" class="text-decoration-none">Volver al acceso</a>
            </p>
        </div>
    </div>
</x-layouts::auth>

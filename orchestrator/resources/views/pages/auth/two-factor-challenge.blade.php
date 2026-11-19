<x-layouts::auth :title="'Autenticación en dos pasos'">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-3">Código de verificación</h1>

            <p class="text-muted small mb-3">
                Introduce el código de verificación generado por tu aplicación
                de autenticación.
            </p>

            <form method="POST" action="{{ route('two-factor.login') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label">Código</label>
                    <input type="text" name="code" class="form-control @error('code') is-invalid @enderror" required autofocus autocomplete="one-time-code" pattern="[0-9]*" inputmode="numeric">
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn btn-primary w-100">Confirmar</button>
            </form>
        </div>
    </div>
</x-layouts::auth>

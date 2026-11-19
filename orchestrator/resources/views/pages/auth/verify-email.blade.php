<x-layouts::auth :title="'Confirmar correo'">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-3">Confirma tu correo</h1>

            <p class="text-muted small mb-3">
                Antes de continuar, confirma tu dirección de correo. Te hemos
                enviado un enlace de verificación a tu buzón.
            </p>

            <form class="d-inline" method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm">Reenviar</button>
            </form>

            <p class="text-center small text-muted mt-3 mb-0">
                <a href="{{ route('logout') }}" class="text-decoration-none"
                   onclick="event.preventDefault(); document.querySelector('form[method=POST]').submit();">
                    Cerrar sesión
                </a>
            </p>
        </div>
    </div>
</x-layouts::auth>

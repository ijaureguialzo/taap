<x-layouts::auth :title="null">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <p class="text-muted mb-3">{{ __('footer.tagline') }}</p>
            <ul class="list-unstyled small text-muted mb-4">
                <li class="mb-2">• {{ __('footer.feat1', ['d' => 'Agentes 24/7 en Docker, aislados']) }}</li>
                <li class="mb-2">• {{ __('footer.feat2', ['d' => 'Modelos por gateway propio o público']) }}</li>
                <li>• {{ __('footer.feat3', ['d' => 'Chat en tiempo real desde web y móvil']) }}</li>
            </ul>
            <a href="{{ route('login') }}" class="btn btn-primary w-100">{{ __('login.submit') }}</a>
            <p class="text-center small text-muted mt-3 mb-0">
                <a href="{{ route('register') }}" class="text-decoration-none">{{ __('login.register') }}</a>
            </p>
        </div>
    </div>
</x-layouts::auth>

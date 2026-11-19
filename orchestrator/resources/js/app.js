// TAAP v1: Bootstrap 5 (bundle: incluye Popper) + Alpine + Echo (Reverb).
import '../../node_modules/bootstrap/dist/js/bootstrap.bundle.min.js';
import Alpine from 'alpinejs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Alpine = Alpine;
Alpine.start();

// Cliente de broadcast (Reverb). La key/host llegan desde el layout via
// data-attrs (el backend inyecta config("broadcasting...")).
function initEcho() {
    const root = document.querySelector('[data-reverb]');
    if (!root || window.TaapEcho) {
        return;
    }
    const cfg = root.dataset.reverb ? JSON.parse(root.dataset.reverb) : {};
    if (!cfg.key) {
        return; // reverb no configurado: la UI funciona sin streaming
    }
    window.TaapEcho = new Echo({
        broadcaster: 'reverb',
        host: cfg.host,
        forceTLS: cfg.scheme === 'https',
        port: Number(cfg.port || (cfg.scheme === 'https' ? 443 : 80)),
        scheme: cfg.scheme,
        enabled: true,
        ...cfg,
        // pusher-js lee `key` para autenticarse
    });
    // pusher-js necesita la key explícitamente en las opciones
    const pusherOpts = {
        wsHost: cfg.host,
        wsPort: Number(cfg.port || 80),
        wssPort: Number(cfg.port || 443),
        forceTLS: cfg.scheme === 'https',
        enableStats: true,
        cluster: 'mt1',
        useTLS: cfg.scheme === 'https',
        authEndpoint: cfg.authEndpoint,
        auth: { headers: {} },
    };
    window.TaapPusher = new Pusher(cfg.key, pusherOpts);
    window.TaapEcho.connector = {
        pusher: () => window.TaapPusher,
    };
}

window.addEventListener('livewire:navigated', initEcho);
document.addEventListener('alpine:init', () => {
    Alpine.data('taap', {
        reverbReady: false,
        init() {
            initEcho();
            if (window.TaapPusher) {
                window.TaapPusher.connection.bind('connected', () => {
                    this.reverbReady = true;
                });
                window.TaapPusher.connection.bind('disconnected', () => {
                    this.reverbReady = false;
                });
            }
        },
    });
});

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
    // pusher-js exige `cluster` en la validacion aunque wsHost la sobreescribe.
    window.TaapPusher = new Pusher(cfg.key, {
        wsHost: cfg.host,
        wsPort: Number(cfg.port || 80),
        wssPort: Number(cfg.port || 443),
        forceTLS: cfg.scheme === 'https',
        useTLS: cfg.scheme === 'https',
        cluster: 'mt1',
        authEndpoint: cfg.authEndpoint,
        auth: { headers: {} },
    });
    // Echo reutiliza esa MISMA instancia (opción `client`): el connector
    // interno queda intacto y .channel() funciona. (El stub
    // `connector = {pusher: () => ...}` rompia TaapEcho.channel():
    // "connector.channel is not a function").
    window.TaapEcho = new Echo({
        broadcaster: 'reverb',
        client: window.TaapPusher,
        // Sin namespace: el emisor difunde como `agent-event` (crudo). El
        // default de laravel-echo (`App.Events`) haria que .listen()
        // registrara el callback como `App\\Events\\agent-event` y nunca
        // coincidiera con el nombre del evento en el cable.
        namespace: '',
        ...cfg,
    });
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

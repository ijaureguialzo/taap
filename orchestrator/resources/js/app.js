// TAAP v1: Bootstrap 5 (bundle: incluye Popper) + Alpine + Echo (Reverb).
import '../../node_modules/bootstrap/dist/js/bootstrap.bundle.min.js';
import Alpine from 'alpinejs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// livewire.js (classic <script> en el body; se ejecuta ANTES de este modulo)
// instala su propio Alpine (con plugins navigate/history) en window.Alpine.
// Su codigo de features referenciar el GLOBAL `Alpine` en el momento de la
// llamada: si lo pisamos con una segunda instancia normal, `Alpine.navigate`
// (plugin navigate) desaparece de bajo Livewire y todo `redirect(…, navigate:
// true)` (p. ej. tras crear un agente) muere en silencio (sin navegacion, sin
// fallback). Reutilizamos la instancia bundled (o la nuestra si no hay).
const TaapAlpine = window.Alpine ?? Alpine;
window.Alpine = TaapAlpine;
if (!TaapAlpine.__fromLivewire) {
    // Alpine standalone (sin livewire.js en la pagina): lo arrancamos nosotros.
    TaapAlpine.start();
}

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

// Tema (brand-identity D4): conmutador de tres estados. La RESOLUCION
// (localStorage -> sistema -> claro) ya la hizo el script inline del <head>
// antes del primer pintado; aqui se aplica la eleccion del usuario, se
// persiste y se marca el estado actual en los botones.
//
// spa-theme-persistence (D2/D3): la elección se espeja ADEMAS a la cookie
// taap-theme (texto plano: bootstrap/app.php configura EncryptCookies para
// no cifrarla), para que el <html> que renderiza el servidor lleve el
// atributo data-bs-theme y la sincronización de wire:navigate lo PRESERVE
// (el swap borra lo que el HTML recibido no trae y no re-ejecuta el
// script del <head>). El listener del conmutador va por DELEGACION en
// document (el document sobrevive al swap; el body, no) y el tema se
// re-aplica en livewire:navigated como red de seguridad.
const resolveTheme = (mode) => (mode === 'light' || mode === 'dark')
    ? mode
    : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

const storedThemeMode = () => {
    const saved = localStorage.getItem('taap-theme');
    return saved === 'light' || saved === 'dark' ? saved : 'auto';
};

// Espeja el tema RESUELTO a la cookie (con «auto» escribe el tema del
// sistema, nunca el literal): asi el servidor nunca queda desfasado.
const mirrorThemeCookie = (theme) => {
    document.cookie = 'taap-theme=' + theme + '; path=/; max-age=31536000';
};

const applyTheme = (theme) => {
    document.documentElement.setAttribute('data-bs-theme', theme);
    mirrorThemeCookie(theme);
};

// Eleccion explicita del usuario: persistir (con «auto» se quita la
// preferencia para seguir el sistema) y aplicar.
const applyThemeChoice = (mode) => {
    if (mode === 'auto') {
        localStorage.removeItem('taap-theme');
    } else {
        localStorage.setItem('taap-theme', mode);
    }
    applyTheme(resolveTheme(mode));
    syncThemeButtons();
};

// aria-pressed contra el DOM actual (los botones se recrean en cada
// navegacion SPA).
const syncThemeButtons = () => {
    const mode = storedThemeMode();
    document.querySelectorAll('[data-taap-theme-set]').forEach((b) => {
        b.setAttribute('aria-pressed', b.getAttribute('data-taap-theme-set') === mode ? 'true' : 'false');
    });
};

function initThemeSwitcher() {
    // Delegacion en document: un solo listener para toda la vida de la
    // sesion (window/document no se intercambian en wire:navigate).
    if (!window.__taapThemeDelegated) {
        window.__taapThemeDelegated = true;
        document.addEventListener('click', (e) => {
            const btn = (e.target && e.target.closest) ? e.target.closest('[data-taap-theme-set]') : null;
            if (btn) {
                applyThemeChoice(btn.getAttribute('data-taap-theme-set'));
            }
        });
    }
    if (!document.querySelector('[data-taap-theme-set]')) {
        return; // pagina de guest: no hay conmutador
    }
    // Red de seguridad: re-aplicar el tema resuelto (el swap borra el
    // atributo y el script del <head> no se re-ejecuta en la navegacion).
    applyTheme(resolveTheme(storedThemeMode()));
    syncThemeButtons();
    // El sistema cambia y el usuario va en «auto»: seguirlo en vivo.
    // (El MediaQueryList sobrevive al swap: registrarlo una sola vez.)
    if (storedThemeMode() === 'auto' && !window.__taapThemeSystemListener) {
        window.__taapThemeSystemListener = true;
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            applyTheme(e.matches ? 'dark' : 'light');
        });
    }
}

// Los componentes Bootstrap se inician una vez (contra el DOM inicial);
// tras un swap de wire:navigate el body nuevo queda sin listeners:
// re-inicializar los toggles (dropdown/collapse) sin instancia.
function reinitBootstrapToggles() {
    if (!window.bootstrap) {
        return;
    }
    const kinds = { dropdown: window.bootstrap.Dropdown, collapse: window.bootstrap.Collapse };
    Object.keys(kinds).forEach((kind) => {
        document.querySelectorAll('[data-bs-toggle="' + kind + '"]').forEach((el) => {
            if (!kinds[kind].getInstance(el)) {
                kinds[kind].getOrCreateInstance(el);
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initThemeSwitcher);
window.addEventListener('livewire:navigated', () => {
    initThemeSwitcher();
    reinitBootstrapToggles();
});
document.addEventListener('alpine:init', () => {
    TaapAlpine.data('taap', {
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

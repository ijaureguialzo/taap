// TAAP v1: Bootstrap 5 (bundle: incluye Popper) + Alpine + Echo (Reverb).
import '../../node_modules/bootstrap/dist/js/bootstrap.bundle.min.js';
import Alpine from 'alpinejs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

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

// =====================================================================
// TaapAgents: utilidades compartidas de agentes (interactive-agent-chat
// §4.1/§5.3). Las blades las usan desde el <head> (app.js se carga con
// vite ANTES que los scripts inline de los componentes; los retry de los
// setup inline cubren el caso de carga diferida).
// =====================================================================

// ── 4.1: suscripción al canal público `agent.{id}` ─────────────────────
// Mismo patrón para index, detalle y chat: suscripción con RE-SUSCRIPCIÓN
// robusta. pusher-js NO garantiza re-suscribir un canal que ya está en
// `subscribed === true` (cambio de transporte o reinicio de Reverb): el
// servidor pierde la suscripción y el canal muere en silencio (el socket
// sigue vivo y recibiendo pongs, pero nunca vuelve a recibir eventos).
//   - `connected`  -> re-suscribir en el socket nuevo.
//   - watchdog 30s -> red para zombis de TCP half-open que el cliente no
//     detecta (re-suscribir es barato e idempotente).
function subscribeAgent(agentId, handler) {
    let stopped = false;
    let timer = null;
    let listener = null;
    const ensure = () => {
        if (stopped || !window.TaapPusher || !window.TaapEcho) return;
        // `channel()` de pusher-js devuelve NULL si el canal no existe aún
        // (hay que `subscribe()` para crearlo). El listener se une DIRECTO
        // al canal pusher (el wrapper de Echo no expone `off`, y un unbind
        // a ciegas borraria el listener de OTROS componentes de la pagina
        // que comparten el mismo canal): se quita SOLO el nuestro.
        let channel = window.TaapPusher.channel('agent.' + agentId);
        if (!channel) {
            channel = window.TaapPusher.subscribe('agent.' + agentId);
        }
        if (listener) {
            channel.unbind('agent-event', listener);
        }
        listener = (payload) => {
            if (!stopped) handler(payload);
        };
        channel.bind('agent-event', listener);
        // Re-suscripcion forzada: pusher-js NO garantiza re-suscribir un
        // canal que ya esta en `subscribed === true` (canal zombi: TCP
        // half-open que el cliente no detecta; el servidor pierde la
        // suscripcion y el canal muere en silencio aunque el socket siga
        // vivo recibiendo pongs). Es barata e idempotente.
        channel.subscribed = false;
        channel.subscribe();
    };
    ensure();
    if (window.TaapPusher) {
        window.TaapPusher.connection.bind('taap-resub-' + agentId, ensure);
        window.TaapPusher.connection.bind('connected', () => {
            window.TaapPusher.connection.emit('taap-resub-' + agentId);
        });
    }
    timer = setInterval(ensure, 30_000);
    return {
        stop() {
            stopped = true;
            if (timer) clearInterval(timer);
        },
    };
}

// ── 5.3: Markdown sanitizado en burbujas ───────────────────────────────
// marked (GFM: listas, tablas, énfasis) + DOMPurify (sin script ni
// atributos de evento; enlaces con rel/target). El resultado se mantiene
// con white-space: pre-wrap (los .md conservan la semantica del texto
// plano de antes: saltos/sangrías respetados) y los bloques de código
// llevan su propio `pre`.
// El export nombrado `marked` de v18 YA es la instancia precargada
// (usar `marked.marked` da undefined en el build ESM y mataba el modulo
// en silencio: window.TaapAgents nunca existia).
const taapMarked = marked;
taapMarked.setOptions({ gfm: true, breaks: true });
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A') {
        node.setAttribute('rel', 'noopener noreferrer');
        node.setAttribute('target', '_blank');
    }
});
function renderMarkdownInto(el) {
    // Idempotente: el texto fuente viaja en data-raw (el morph repinta el
    // nodo con el texto del servidor y se re-renderiza una sola vez).
    const raw = el.hasAttribute('data-raw')
        ? el.getAttribute('data-raw')
        : (el.textContent || '');
    if (el.hasAttribute('data-raw') && el.classList.contains('md-ready')
        && el.getAttribute('data-raw') === raw) {
        return; // sin cambio desde el ultimo render
    }
    el.setAttribute('data-raw', raw);
    if (raw.trim() === '') {
        el.classList.add('md-ready');
        return;
    }
    const html = DOMPurify.sanitize(taapMarked.parse(raw), {
        FORBID_TAGS: ['script', 'style', 'iframe', 'object', 'embed'],
    });
    el.innerHTML = html;
    el.classList.add('md-ready');
}

window.TaapAgents = {
    subscribeAgent,
    // 4.1: aplica un status al badge usando el mapa server-renderizado
    // (data-status-map): icono + clase + etiqueta en vivo, sin round-trip.
    applyStatus(badge, status) {
        if (!badge || !badge.getAttribute) return;
        const map = JSON.parse(badge.getAttribute('data-status-map') || '{}');
        const info = map[status];
        if (!info) return;
        badge.className = 'badge status-' + status + ' live-status';
        const icon = badge.querySelector('[data-status-icon]');
        if (icon) icon.className = 'bi bi-' + info.icon + ' me-1';
        const label = badge.querySelector('[data-status-label]');
        if (label) {
            label.textContent = info.label;
            label.dataset.statusValue = status;
        }
        badge.dataset.statusValue = status;
    },
    markdown: {
        // Renderiza el nodo (histórico o streaming); el throttle del
        // streaming lo hace el dueño del buffer (los deltas llegan a
        // ráfaga y marked+purify no deben correr por cada token).
        render: renderMarkdownInto,
    },
};
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

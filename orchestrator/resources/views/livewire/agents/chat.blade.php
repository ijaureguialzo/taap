<div class="agent-chat" data-agent-id="{{ $agent->id }}">

    {{-- Historial + streaming --}}
    <div class="chat-scroll flex-grow-1 overflow-auto border rounded-2 p-3 bg-body mb-3" id="chat-box">
        @if (count($history) === 0 && $streaming === null)
            <div class="text-center text-muted py-5">{{ __('chat.empty') }}</div>
        @endif

        @foreach ($history as $msg)
            @if ($msg['author'] === 'user')
                <div class="d-flex justify-content-end mb-3">
                    <div class="bubble bubble-user">
                        <div class="small mb-1">{{ __('chat.you') }} · <time class="chat-time" data-time="{{ $msg['at'] }}">{{ $msg['at'] }}</time></div>
                        <div class="message-content">{{ $msg['content'] }}</div>
                    </div>
                </div>
            @else
                <div class="d-flex mb-3">
                    <div class="bubble bubble-agent">
                        <div class="small mb-1">{{ $agent->name }} · <time class="chat-time" data-time="{{ $msg['at'] }}">{{ $msg['at'] }}</time></div>
                        <div class="message-content">{{ $msg['content'] }}</div>
                    </div>
                </div>
            @endif
        @endforeach

        {{-- Burbuja en streaming (deltas) --}}
        @if ($streaming !== null)
            <div class="d-flex mb-3">
                <div class="bubble bubble-agent">
                    <div class="small text-muted mb-1">
                        {{ $agent->name }} ·
                        @if ($activity)
                            <span wire:key="activity" class="text-info">{{ $activity['kind'] === 'tool' ? __('chat.tool').': '.$activity['label'] : __('chat.thinking') }}</span>
                        @else
                            <span class="text-info">{{ __('chat.streaming') }}</span>
                        @endif
                    </div>
                    <div class="message-content" wire:text="streaming"></div>
                </div>
            </div>
        @endif

        {{-- Aprobacion pendiente --}}
        @if ($pendingApproval)
            <div class="card border-warning mb-3" wire:key="approval-{{ $pendingApproval }}">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <span class="fw-semibold">{{ __('chat.approval') }}</span>
                        <div class="d-flex gap-1 flex-wrap">
                            @if (!empty($pendingApprovalInfo['options']))
                                @foreach ($pendingApprovalInfo['options'] as $opt)
                                    @if ($opt['value'] === 'deny')
                                        <button class="btn btn-sm btn-outline-danger" wire:click="approve(false, '{{ $opt['value'] }}')"><x-icon name="x-lg" class="me-1" />{{ $opt['label'] }}</button>
                                    @else
                                        <button class="btn btn-sm {{ $opt['value'] === 'once' ? 'btn-success' : 'btn-outline-success' }}" wire:click="approve(true, '{{ $opt['value'] }}')"><x-icon name="check-lg" class="me-1" />{{ $opt['label'] }}</button>
                                    @endif
                                @endforeach
                            @else
                                <button class="btn btn-sm btn-success me-2" wire:click="approve(true)"><x-icon name="check-lg" class="me-1" />{{ __('chat.approve') }}</button>
                                <button class="btn btn-sm btn-outline-danger" wire:click="approve(false)"><x-icon name="x-lg" class="me-1" />{{ __('chat.deny') }}</button>
                            @endif
                        </div>
                    </div>
                    @if (!empty($pendingApprovalInfo['prompt']))
                        <div class="small text-muted mt-1" style="white-space: pre-line">{{ $pendingApprovalInfo['prompt'] }}</div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Indicador de actividad de herramientas --}}
    @if ($activity && $streaming !== null)
        <div class="small text-info mb-2" wire:key="activity-2">
            ⚙ {{ $activity['kind'] === 'tool' ? __('chat.tool').': '.$activity['label'] : __('chat.run') }}
            {{ ($activity['detail'] ?? null) ? '· '.$activity['detail'] : '' }}
        </div>
    @endif

    {{-- Entrada --}}
    {{-- El estado del boton y el envio (Enter/click) los gestiona el script
         inline por DELEGACION en `document`: Livewire v4 sustituye estos
         nodos al re-renderizar `disabled` y un listener unido al nodo
         original moriria en silencio (el boton dejaba de activarse al
         teclear). `wire:model` sigue sincronizando `content` para el
         historial y para el estado del botón renderizado. --}}
    <div class="input-group">
        <input type="text" wire:model.live.400ms="content" class="form-control" id="chat-input"
               placeholder="{{ __('chat.placeholder') }}"
               @disabled(! $agent->status->acceptsMessages())>
        <button class="btn btn-primary" type="button" id="chat-send"
                @disabled(! $agent->status->acceptsMessages() || trim($content) === '')>
            <x-icon name="send" class="me-1" />{{ __('chat.send') }}
        </button>
    </div>
    @if (! $agent->status->acceptsMessages())
        <div class="form-text mt-1">{{ __('chat.waiting_approval') }}</div>
    @elseif (! $agent->status->isRunning())
        <div class="form-text mt-1">{{ __('chat.will_start') }}</div>
    @endif

    <script>
        (() => {
            const agentId = {{ $agent->id }};
            // Flag a nivel de window (NO en el nodo): el morph de Livewire
            // re-sincroniza los atributos del nodo raiz con el HTML del
            // servidor y BORRA cualquier data-attr client-only; un flag en
            // el nodo se perdia en el primer update y el setup se re-
            // ejecutaba (o moria) sin control.
            const FLAG = '__taapChatSetup_' + agentId;
            const root = document.querySelector('.agent-chat[data-agent-id="' + agentId + '"]');
            if (!root) return;

            // Este <script> inline corre ANTES que los módulos (app.js y
            // livewire.js): si las dependencias aún no existen, reintenta
            // hasta que setup() se complete (el flag a nivel window
            // indica éxito, no solo "se intento").
            const ready = () =>
                window.TaapEcho && window.TaapPusher && window.Livewire;

            const currentRoot = () =>
                document.querySelector('.agent-chat[data-agent-id="' + agentId + '"]') || root;

            // Resuelve la INSTANCIA actual del componente en cada evento.
            // (En Livewire 4, cuando el componente anidado se re-hidrata
            // desde el morph del padre, `Livewire.find()` devuelve una
            // instancia NUEVA: capturar la instancia una sola vez en el
            // setup dejaba el listener apuntando a la instancia muerta y
            // los updates morian en silencio.)
            const currentWire = () => {
                const el = currentRoot();
                return el ? window.Livewire.find(el.getAttribute('wire:id')) : null;
            };

            // Horas locales del visor (web-chat-ux D2): el servidor envia el
            // instante en ISO 8601 (data-time) y el cliente lo renderiza en
            // la zona horaria del dispositivo. El fallback visible hasta que
            // corre el JS es el propio ISO (un instante, no una hora horneada).
            const fmtLocal = (iso) => {
                const d = new Date(iso);
                return isNaN(d) ? null : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            };
            const formatTimes = (scope) => {
                (scope || currentRoot()).querySelectorAll('time.chat-time[data-time]').forEach((el) => {
                    const t = fmtLocal(el.getAttribute('data-time'));
                    if (t !== null) el.textContent = t;
                });
            };

            const setup = () => {
                if (window[FLAG]) return; // idempotente (re-ejecuciones del script)
                // Se marca ANTES del trabajo: si algo lanza, el retry sigue.
                window[FLAG] = true;

                try {
                    // 1) Canal en vivo `agent.{id}` (público): el mismo que
                    //    difunde el orquestador y al que se suscribe la app
                    //    iOS. (Con Echo.private la web no resolvía el auth y
                    //    los eventos nunca llegaban: bug que congelaba la
                    //    burbuja en "escribiendo…").
                    if (window.TaapEcho) {
                        window.TaapEcho
                            .channel('agent.' + agentId)
                            .listen('agent-event', (payload) => {
                                const wire = currentWire();
                                if (wire) wire.agentEvent(payload);
                            });
                    }

                    // 2) Tras un envío: Livewire no sobreescribe el input
                    //    enfocado (protege el cursor); el componente avisa
                    //    con `message-sent`: vaciamos, reenfocamos y
                    //    re-aplicamos el estado del botón.
                    if (window.Livewire) {
                        window.Livewire.on('message-sent', () => {
                            if (!currentRoot().isConnected) return;
                            const input = document.querySelector('#chat-input');
                            if (!input) return;
                            input.value = '';
                            input.focus();
                            const box = document.querySelector('#chat-box');
                            if (box) box.scrollTop = box.scrollHeight;
                            refreshSend();
                        });
                    }

                    // 2b) Re-suscripcion robusta. pusher-js NO garantiza
                    //     re-suscribir un canal que ya esta en
                    //     `subscribed === true` (cambio de transporte o
                    //     reinicio de Reverb): el servidor pierde la
                    //     suscripcion y el canal muere en silencio (el
                    //     socket sigue vivo y recibiendo pongs, pero nunca
                    //     vuelve a recibir eventos).
                    //     - `connected` -> re-suscribimos en el nuevo socket.
                    //     - watchdog 30s -> redes para zombis de TCP
                    //       half-open que el cliente no detecta (re-suscribir
                    //       es barato e idempotente: el servidor re-registra).
                    if (window.TaapPusher) {
                        const resubscribe = () => {
                            if (!currentRoot().isConnected) return;
                            const channel = window.TaapPusher.channel('agent.' + agentId);
                            channel.subscribed = false;
                            channel.subscribe();
                        };
                        window.TaapPusher.connection.bind('connected', resubscribe);
                        setInterval(resubscribe, 30_000);
                    }

                    // 3) Autodesplazamiento al recibir contenido nuevo.
                    //    El observer se (re)ancla en el nodo actual: si el
                    //    morph del padre reemplaza el nodo, reanclamos.
                    let observedBox = null;
                    let autoScrollObserver = null;
                    const attachAutoScroll = () => {
                        const box = document.querySelector('#chat-box');
                        if (!box || box === observedBox) return;
                        if (autoScrollObserver) autoScrollObserver.disconnect();
                        autoScrollObserver = new MutationObserver(() => {
                            box.scrollTop = box.scrollHeight;
                        });
                        autoScrollObserver.observe(box, { childList: true, subtree: true, characterData: true });
                        observedBox = box;
                    };
                    attachAutoScroll();
                    setInterval(attachAutoScroll, 30_000);

                    // 5) Estado del botón y envío (Enter/click) por
                    //    DELEGACION en `document`: sobrevive a cualquier
                    //    morph. Livewire v4 sustituye el nodo del botón
                    //    cuando el atributo `disabled` re-renderizado cambia y el
                    //    listener unido al nodo original moria en silencio
                    //    (el botón no se activaba al teclear hasta recargar;
                    //    tampoco el envío era inmediato: dependia del
                    //    round-trip de wire:model con debounce 400ms).
                    let sending = false;
                    let sendTries = 0;
                    const currentInput = () => document.querySelector('#chat-input');
                    const currentBtn = () => document.querySelector('#chat-send');

                    // Re-aplica el estado del botón leyendo el DOM ACTUAL.
                    const refreshSend = () => {
                        const input = currentInput();
                        const btn = currentBtn();
                        if (!input || !btn) return;
                        const hasContent = input.value.trim().length > 0;
                        btn.disabled = !(!input.disabled && hasContent && !sending);
                    };

                    const attemptSend = (content) => {
                        const wire = currentWire();
                        if (!wire) {
                            // Pre-hidratación: reintenta (~2 s máx).
                            if (++sendTries > 40) { sendTries = 0; return; }
                            setTimeout(() => attemptSend(content), 50);
                            return;
                        }
                        sendTries = 0;
                        sending = true;
                        refreshSend();
                        try {
                            // v4: el proxy $wire invoca el método por nombre
                            // y devuelve una Action (con `.promise`).
                            const action = wire.send(content);
                            const p = action && action.promise ? action.promise : Promise.resolve();
                            p.catch((e) => console.warn('taap: send', e))
                                    .finally(() => { sending = false; refreshSend(); });
                        } catch (e) {
                            sending = false;
                            console.warn('taap: send', e);
                            refreshSend();
                        }
                    };

                    const doSend = () => {
                        if (sending) return;
                        const input = currentInput();
                        if (!input || input.disabled) return;
                        const content = input.value.trim();
                        if (!content) return;
                        attemptSend(content);
                    };

                    // Activación inmediata al teclear (0ms, sin esperar el
                    // round-trip) y envío por Enter / click.
                    document.addEventListener('input', (e) => {
                        if (e.target && e.target.id === 'chat-input') refreshSend();
                    });
                    document.addEventListener('keydown', (e) => {
                        if (e.key !== 'Enter' || e.isComposing) return;
                        const t = e.target;
                        if (!t || t.id !== 'chat-input') return;
                        if (t.closest && !t.closest('.agent-chat[data-agent-id="' + agentId + '"]')) return;
                        e.preventDefault();
                        doSend();
                    });
                    document.addEventListener('click', (e) => {
                        const t = e.target;
                        if (!t || t.id !== 'chat-send') return;
                        if (t.closest && !t.closest('.agent-chat[data-agent-id="' + agentId + '"]')) return;
                        doSend();
                    });

                    // Tras cada morph que toque el chat, re-aplica el estado
                    // (el estado del botón re-renderizado por el servidor puede ir
                    // desfasado respecto al texto en el input).
                    if (window.Livewire && typeof window.Livewire.hook === 'function') {
                        window.Livewire.hook('morph.updated', (arg) => {
                            const el = arg && arg.el ? arg.el : null;
                            if (!el || el.nodeType !== 1) return;
                            if (el.id === 'chat-input' || el.id === 'chat-send' ||
                                (el.closest && el.closest('.agent-chat[data-agent-id="' + agentId + '"]'))) {
                                refreshSend();
                                if (el.matches('time.chat-time') || el.querySelector('time.chat-time')) formatTimes(el);
                            }
                        });
                    }
                    document.addEventListener('livewire:initialized', () => refreshSend());
                    refreshSend();
                    formatTimes();
                } catch (e) {
                    window[FLAG] = false; // permitir retry
                    console.warn('taap: chat setup', e);
                }
            };

            if (ready()) {
                setup();
            } else {
                let tries = 0;
                const timer = setInterval(() => {
                    if (ready()) setup();
                    if (window[FLAG] || ++tries > 200) clearInterval(timer); // ~10s
                }, 50);
            }
        })();
    </script>
</div>

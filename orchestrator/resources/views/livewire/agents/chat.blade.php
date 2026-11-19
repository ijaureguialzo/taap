<div class="agent-chat" data-agent-id="{{ $agent->id }}">

    {{-- Historial + streaming --}}
    <div class="chat-scroll flex-grow-1 overflow-auto border rounded-2 p-3 bg-white mb-3" id="chat-box">
        @if (count($history) === 0 && $streaming === null)
            <div class="text-center text-muted py-5">{{ __('chat.empty') }}</div>
        @endif

        @foreach ($history as $msg)
            @if ($msg['author'] === 'user')
                <div class="d-flex justify-content-end mb-3">
                    <div class="bubble bubble-user">
                        <div class="small text-muted mb-1">{{ __('chat.you') }} · {{ $msg['at'] }}</div>
                        <div class="message-content">{{ $msg['content'] }}</div>
                    </div>
                </div>
            @else
                <div class="d-flex mb-3">
                    <div class="bubble bubble-agent">
                        <div class="small text-muted mb-1">{{ $agent->name }} · {{ $msg['at'] }}</div>
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
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">{{ __('chat.approval') }}</span>
                        <div>
                            <button class="btn btn-sm btn-success me-2" wire:click="approve(true)">{{ __('chat.approve') }}</button>
                            <button class="btn btn-sm btn-outline-danger" wire:click="approve(false)">{{ __('chat.deny') }}</button>
                        </div>
                    </div>
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
    <div class="input-group">
        <input type="text" wire:model.live.400ms="content" class="form-control" id="chat-input"
               placeholder="{{ __('chat.placeholder') }}"
               @disabled(! $agent->status->acceptsMessages())
               wire:keydown.enter="send">
        <button class="btn btn-primary" wire:click="send"
                @disabled(! $agent->status->acceptsMessages() || trim($content) === '')>
            {{ __('chat.send') }}
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
                    //    con `message-sent`: vaciamos y reenfocamos.
                    if (window.Livewire) {
                        window.Livewire.on('message-sent', () => {
                            if (!currentRoot().isConnected) return;
                            const input = document.querySelector('#chat-input');
                            if (!input) return;
                            input.value = '';
                            input.focus();
                            const box = document.querySelector('#chat-box');
                            if (box) box.scrollTop = box.scrollHeight;
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

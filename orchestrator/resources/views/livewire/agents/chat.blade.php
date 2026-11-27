@php
    // 4.2: el input/aviso gobiernan con el estado EN VIVO (canal); sin
    // eventos (mount/recarga) cae al estado persistido.
    $status = $this->effectiveStatus();
@endphp
<div class="agent-chat" data-agent-id="{{ $agent->id }}">

    {{-- Buscador de mensajes (chat-search-export) --}}
    <div class="d-flex gap-2 align-items-center mb-2">
        <div class="input-group">
            <span class="input-group-text"><x-icon name="search" /></span>
            <input type="text" wire:model.live.250ms="searchQuery"
                   placeholder="{{ __('chat.search_placeholder') }}"
                   class="form-control form-control-sm">
        </div>
        <a href="/api/v1/agents/{{ $agent->id }}/messages/export?format=md"
           download class="btn btn-outline-secondary btn-sm text-nowrap">{{ __('chat.export_md') }}</a>
        <a href="/api/v1/agents/{{ $agent->id }}/messages/export?format=json"
           download class="btn btn-outline-secondary btn-sm text-nowrap">{{ __('chat.export_json') }}</a>
    </div>
    @if (count($searchResults) > 0)
        <div class="search-results border rounded-2 p-2 mb-2 bg-body small">
            @foreach ($searchResults as $r)
                <a href="#msg-{{ $r['id'] }}" class="d-block border-bottom py-1 text-decoration-none text-body">
                    <span class="text-muted me-2">{{ $r['author'] ?? '' }}</span>
                    {{ \Illuminate\Support\Str::limit($r['content'] ?? '', 80) }}
                </a>
            @endforeach
        </div>
    @elseif ($searchQuery !== '')
        <div class="small text-muted mb-2">{{ __('chat.search_empty') }}</div>
    @endif

    {{-- Historial + streaming --}}
    <div class="chat-scroll overflow-auto border rounded-2 p-3 bg-body mb-3" id="chat-box"
         style="flex-grow: 1">
        @if (count($history) === 0 && $streaming === null)
            <div class="text-center text-muted py-5">{{ __('chat.empty') }}</div>
        @endif

        @foreach ($history as $msg)
            @if ($msg['author'] === 'user')
                <div class="d-flex justify-content-end mb-3" id="msg-{{ $msg['id'] }}">
                    <div class="bubble bubble-user">
                        <div class="small mb-1">{{ __('chat.you') }} · <time wire:ignore class="chat-time" data-time="{{ $msg['at'] }}">{{ $msg['at'] }}</time></div>
                        <div class="message-content md-target">{{ $msg['content'] }}</div>
                    </div>
                </div>
            @else
                @php
                    // 3.3: secciones colapsables del mensaje asientado:
                    // Razonamiento (meta.thinking, texto plano) + Actividad
                    // (tool/error persistidos del run; 1 consulta por lote).
                    $thinking = trim((string) ($msg['meta']['thinking'] ?? ''));
                    $runId = $msg['meta']['run_id'] ?? null;
                    $acts = $runId ? ($activities[$runId] ?? []) : [];
                @endphp
                <div class="d-flex mb-3" id="msg-{{ $msg['id'] }}">
                    <div class="bubble bubble-agent">
                        <div class="small mb-1">{{ $agent->name }} · <time wire:ignore class="chat-time" data-time="{{ $msg['at'] }}">{{ $msg['at'] }}</time></div>

                        @if ($thinking !== '')
                            <details class="msg-reasoning mb-2">
                                <summary class="msg-section-summary">
                                    <i class="bi bi-lightning-charge me-1" aria-hidden="true"></i>
                                    {{ __('chat.reasoning') }}
                                    <span class="text-muted ms-1">{{ number_format(mb_strlen($thinking) / 1024, 1) }} KB</span>
                                </summary>
                                <div class="reasoning-text">{{ $thinking }}</div>
                            </details>
                        @endif

                        <div class="message-content md-target">{{ $msg['content'] }}</div>

                        @if (count($acts) > 0)
                            <details class="msg-activity mt-2">
                                <summary class="msg-section-summary">
                                    <i class="bi bi-gear me-1" aria-hidden="true"></i>
                                    {{ __('chat.activity') }} ({{ count($acts) }})
                                </summary>
                                <div class="activity-lines">
                                    @foreach ($acts as $a)
                                        @php
                                            $icon = match ($a['type']) {
                                                'tool.start' => 'bi-gear',
                                                'tool.result' => $a['ok'] ? 'bi-check-lg' : 'bi-x-lg',
                                                default => 'bi-exclamation-octagon',
                                            };
                                            $label = $a['type'] === 'error'
                                                ? ($a['summary'] !== '' ? $a['summary'] : __('chat.error'))
                                                : (($a['tool'] ?? '') . ($a['summary'] !== '' ? ' · ' . $a['summary'] : ''));
                                        @endphp
                                        <div class="activity-line {{ $a['type'] === 'error' || ! $a['ok'] ? 'text-danger' : 'text-muted' }}">
                                            <i class="bi {{ $icon }} me-1" aria-hidden="true"></i>{{ $label }}
                                            <time wire:ignore class="chat-time ms-2" data-time="{{ $a['at'] }}">{{ $a['at'] }}</time>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                </div>
            @endif
        @endforeach

        {{--
            Burbuja en streaming (3.1): el servidor solo pinta el
            ESQUELETO (etiqueta de estado + contenedores wire:ignore).
            El JS aplica los deltas LOCALmente en esos contenedores (sin
            round-trip Livewire por token); los wire:ignore sobreviven a
            los morphs (actividad, estado…) hasta que el message.final
            sustituye la burbuja por el mensaje asientado.
        --}}
        @if ($streaming !== null)
            <div class="d-flex mb-3" wire:key="stream-bubble">
                <div class="bubble bubble-agent">
                    <div class="small text-muted mb-1">
                        {{ $agent->name }} ·
                        <span class="stream-state-label" data-stream-state="thinking" wire:ignore>{{ __('chat.thinking') }}</span>
                    </div>
                    <div class="stream-thinking" wire:ignore hidden></div>
                    <div class="message-content stream-content" wire:ignore hidden></div>
                    <div class="stream-activity" wire:ignore></div>
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
            ⚙ {{ $activity['kind'] === 'tool' ? __('chat.tool').': '.$activity['label'] : ($activity['kind'] === 'error' ? __('chat.error') : __('chat.run')) }}
            {{ (!empty($activity['detail']) || $activity['kind'] === 'error') ? '· '.$activity['label'] : '' }}
        </div>
    @endif

    {{-- Entrada --}}
    {{-- El estado del boton y el envio (Enter/click) los gestiona el script
         inline por DELEGACION en `document`: Livewire v4 sustituye estos
         nodos al re-renderizar `disabled` y un listener unido al nodo
         original moriria en silencio (el boton dejaba de activarse al
         teclear). `wire:model` sigue sincronizando `content` para el
         historial y para el estado del botón renderizado. El disabled
         gobierna el estado EN VIVO (4.2), no el de la memoria. --}}
    <div class="input-group">
        <input type="text" wire:model.live.400ms="content" class="form-control" id="chat-input"
               placeholder="{{ __('chat.placeholder') }}"
               @disabled(! $status->acceptsMessages())>
        <button class="btn btn-primary" type="button" id="chat-send"
                @disabled(! $status->acceptsMessages() || trim($content) === '')>
            <x-icon name="send" class="me-1" />{{ __('chat.send') }}
        </button>
    </div>
    @if ($status === \App\Enums\AgentStatus::WaitingApproval)
        <div class="form-text mt-1">{{ __('chat.waiting_approval') }}</div>
    @elseif (! $status->isRunning())
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
                window.TaapAgents && window.Livewire;

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

            // Horas locales del visor: el servidor envia el instante en ISO
            // 8601 (data-time) y el cliente lo renderiza en la zona
            // horaria del dispositivo. 5.2: el CSS oculta el ISO (html.js)
            // hasta que se añade `.is-ready` (sin parpadeo); sin JS, el ISO
            // es el fallback visible.
            const fmtLocal = (iso) => {
                const d = new Date(iso);
                return isNaN(d) ? null : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            };
            const formatTimes = (scope) => {
                const base = scope || currentRoot();
                if (!base || base.nodeType !== 1) return;
                // querySelectorAll solo coincide con descendientes: si el scope ES un
                // <time> (el hook morph.updated pasa el nodo directamente)
                // hay que añadirlo a los objetivos o se perdería el formato.
                const targets = new Set(base.querySelectorAll('time.chat-time[data-time]'));
                if (base.matches && base.matches('time.chat-time')) targets.add(base);
                targets.forEach((el) => {
                    const t = fmtLocal(el.getAttribute('data-time'));
                    // Idempotente: no reasignar un texto igual (reemplaza el
                    // nodo y re-dispararia observers/hooks).
                    if (t !== null && el.textContent !== t) el.textContent = t;
                    el.classList.add('is-ready');
                });
            };

            // ── 3.1 streaming local ────────────────────────────────────
            // El JS es el dueño del texto en streaming: acumula los deltas
            // (razonamiento y texto en flujos SEPARADOS) y los pinta en los
            // contenedores wire:ignore del esqueleto. NINGUN delta produce
            // un round-trip Livewire; el servidor solo crea el esqueleto
            // (run.start/primer delta) y lo cierra (message.final).
            const streamBuf = { thinking: '', content: '', summoned: false, dirty: false, mdTimer: null };
            const streamEls = () => {
                const r = currentRoot();
                if (!r) return null;
                return {
                    thinking: r.querySelector('.stream-thinking'),
                    content: r.querySelector('.stream-content'),
                    activity: r.querySelector('.stream-activity'),
                    label: r.querySelector('.stream-state-label'),
                };
            };
            const renderStream = () => {
                const els = streamEls();
                if (!els) return; // el esqueleto aún no existe: los deltas
                // quedan en el buffer y se vuelcan cuando aparezca.
                if (streamBuf.thinking !== '') {
                    els.thinking.textContent = streamBuf.thinking;
                    els.thinking.hidden = false;
                }
                if (streamBuf.content !== '') {
                    els.content.textContent = streamBuf.content;
                    els.content.hidden = false;
                    // 5.3: markdown con throttle (~100 ms) durante el
                    // streaming.
                    if (!streamBuf.mdTimer) {
                        streamBuf.mdTimer = setTimeout(() => {
                            streamBuf.mdTimer = null;
                            const c = streamEls() && streamEls().content;
                            if (c) window.TaapAgents.markdown.render(c);
                        }, 100);
                    }
                }
                // etiqueta de estado: pensó -> "pensando…"; escribió -> "escribiendo…"
                if (els.label) {
                    const state = streamBuf.content !== '' ? 'writing' : 'thinking';
                    if (els.label.dataset.streamState !== state) {
                        els.label.dataset.streamState = state;
                        els.label.textContent = state === 'writing'
                            ? {!! json_encode(__('chat.streaming')) !!}
                            : {!! json_encode(__('chat.thinking')) !!};
                    }
                }
            };
            const summonSkeleton = () => {
                // Se perdio el run.start (la pagina se abrio a mitad del
                // turno): un unico cambio de estado para que el servidor
                // pinte el esqueleto; el buffer se vuelca con renderStream.
                if (streamBuf.summoned) return;
                streamBuf.summoned = true;
                const wire = currentWire();
                if (wire) {
                    const action = wire.agentEvent({ type: 'message.delta', payload: { run_id: null, content: '' } });
                    if (action && action.promise) action.promise.catch((e) => console.warn('taap: summon', e));
                }
            };
            const appendStream = (kind, text) => {
                if (!text) return;
                if (kind === 'thinking') streamBuf.thinking += text;
                else streamBuf.content += text;
                const els = streamEls();
                if (els) renderStream();
                else summonSkeleton();
            };
            const resetStream = () => {
                if (streamBuf.mdTimer) { clearTimeout(streamBuf.mdTimer); streamBuf.mdTimer = null; }
                streamBuf.thinking = '';
                streamBuf.content = '';
                streamBuf.summoned = false;
                const els = streamEls();
                if (els) {
                    els.thinking.textContent = '';
                    els.content.textContent = '';
                    els.thinking.hidden = true;
                    els.content.hidden = true;
                    if (els.activity) els.activity.innerHTML = '';
                }
            };

            // ── 3.2 feed de actividad en vivo ──────────────────────────
            // tool.start/tool.result/error como lineas compactas (⚙/✓/✗ +
            // resumen truncado) dentro de la burbuja en streaming; en la
            // burbuja asientada la misma info sale de la BD (3.3).
            const activityTruncate = (s) => (s && s.length > 120 ? s.slice(0, 120) + '…' : (s || ''));
            const pushActivityLine = (kind, label, detail) => {
                const els = streamEls();
                if (!els || !els.activity) return;
                const icon = kind === 'error' ? 'bi-exclamation-octagon'
                    : kind === 'tool.result' ? 'bi-check-lg' : 'bi-gear';
                const line = document.createElement('div');
                line.className = 'activity-line' + (kind === 'error' ? ' text-danger' : ' text-muted');
                const i = document.createElement('i');
                i.className = 'bi ' + icon + ' me-1';
                i.setAttribute('aria-hidden', 'true');
                line.appendChild(i);
                line.appendChild(document.createTextNode((label || '') + (detail ? ' · ' + detail : '')));
                els.activity.appendChild(line);
                // tope de lineas (el historial lo guarda en la BD)
                while (els.activity.children.length > 30) els.activity.firstChild.remove();
                if (kind === 'tool.result' || kind === 'error') {
                    // la ultima linea manda el color de la actividad
                    const prev = els.activity.querySelector('.activity-line:last-child');
                    if (prev && kind === 'tool.result' && !prev.dataset.fail) {
                        // ok: ya sale muted por defecto
                    }
                }
            };

            // ── 5.1 scroll: pin si estaba cerca del final ──────────────
            // Antes de cada morph se registra nearBottom (<80 px); al
            // terminar (morphed/island.morphed) se re-ancha el observer
            // (el morph sustituye nodos) y se fija abajo SOLO si nearBottom.
            // El observer conserva la guarda: no arrastra a quien ha subido.
            let pinned = true;
            const chatBox = () => document.querySelector('#chat-box');
            const isNearBottom = () => {
                const box = chatBox();
                if (!box) return true;
                return (box.scrollHeight - box.scrollTop - box.clientHeight) < 80;
            };
            let autoScrollObserver = null;
            let observedBox = null;
            const attachAutoScroll = () => {
                const box = chatBox();
                if (!box || box === observedBox) return;
                if (autoScrollObserver) autoScrollObserver.disconnect();
                autoScrollObserver = new MutationObserver(() => {
                    // Guarda nearBottom: solo fija si el lector sigue al final.
                    if (isNearBottom()) box.scrollTop = box.scrollHeight;
                });
                autoScrollObserver.observe(box, { childList: true, subtree: true, characterData: true });
                observedBox = box;
            };

            const setup = () => {
                if (window[FLAG]) return; // idempotente (re-ejecuciones del script)
                // Se marca ANTES del trabajo: si algo lanza, el retry sigue.
                window[FLAG] = true;

                try {
                    // 1) Canal en vivo `agent.{id}` (público, 4.1): el helper
                    //    compartido (app.js) da la re-suscripción robusta
                    //    (30 s + connected). Los deltas se aplican LOCALES
                    //    (3.1); el resto van al componente.
                    if (window.TaapAgents) {
                        window.TaapAgents.subscribeAgent(agentId, (payload) => {
                            const type = payload.type;
                            const data = payload.payload || {};
                            if (type === 'message.delta' || type === 'thinking.delta') {
                                appendStream(type === 'thinking.delta' ? 'thinking' : 'content', data.content);
                                return; // sin round-trip por token
                            }
                            if (type === 'message.final') {
                                resetStream();
                            }
                            if (type === 'tool.start') {
                                pushActivityLine('tool.start', data.tool, activityTruncate(data.args && (data.args.label || data.args.command || data.args.filePath || data.args.pattern)));
                            } else if (type === 'tool.result') {
                                pushActivityLine('tool.result', data.tool, activityTruncate(data.summary));
                                const els = streamEls();
                                if (els && els.activity) {
                                    const last = els.activity.lastElementChild;
                                    if (last && !data.ok) last.classList.add('text-danger');
                                }
                            } else if (type === 'error') {
                                pushActivityLine('error', {!! json_encode(__('chat.error')) !!}, activityTruncate(data.message));
                            }
                            // Todo lo que muta estado del componente
                            // (incluido run.start que abre/cierra el
                            // esqueleto) sigue por Livewire.
                            const wire = currentWire();
                            if (wire) {
                                const action = wire.agentEvent(payload);
                                if (action && action.promise) action.promise.catch((e) => console.warn('taap: agentEvent', e));
                            }
                        });
                    }

                    // 2) Tras un envío: Livewire no sobreescribe el input
                    //    enfocado (protege el cursor); el componente avisa
                    //    con `message-sent`: vaciamos, reenfocamos y
                    //    re-aplicamos el estado del botón.
                    if (window.Livewire) {
                        window.Livewire.on('message-sent', () => {
                            if (!currentRoot().isConnected) return;
                            resetStream();
                            const input = document.querySelector('#chat-input');
                            if (!input) return;
                            input.value = '';
                            input.focus();
                            if (pinned) {
                                const box = chatBox();
                                if (box) box.scrollTop = box.scrollHeight;
                            }
                            refreshSend();
                        });
                    }

                    // 3) Autodesplazamiento: observer con guarda nearBottom
                    //    (5.1). Sin el re-ancrado por setInterval de 30 s:
                    //    el morphed/island.morphed re-ancla (abajo).
                    attachAutoScroll();

                    // 4) Estado del botón y envío (Enter/click) por
                    //    DELEGACION en `document`: sobrevive a cualquier
                    //    morph (el listener unido al nodo original moria al
                    //    re-renderizarse `disabled`).
                    let sending = false;
                    let sendTries = 0;
                    const currentInput = () => document.querySelector('#chat-input');
                    const currentBtn = () => document.querySelector('#chat-send');

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
                            // v4: el proxy invoca el método por nombre y
                            // devuelve una Action (con `.promise`).
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

                    // 5) Hooks de morph (5.1/5.2/5.3): nearBottom ANTES de
                    //    parchear; tras el morph, re-ancar el observer y
                    //    fijar solo si estaba al final; re-formatear horas;
                    //    re-renderizar markdown de lo nuevo.
                    if (window.Livewire && typeof window.Livewire.hook === 'function') {
                        const touchesOurChat = (el) => {
                            if (!el || el.nodeType !== 1) return false;
                            const sel = '.agent-chat[data-agent-id="' + agentId + '"]';
                            return el.matches(sel) || (el.querySelector ? !!el.querySelector(sel) : false);
                        };

                        window.Livewire.hook('morph', (arg) => {
                            // «morph» dispara ANTES de modificar el DOM:
                            // es el punto de registro de nearBottom.
                            if (touchesOurChat(arg && arg.el)) pinned = isNearBottom();
                        });

                        window.Livewire.hook('morph.updated', (arg) => {
                            const el = arg && arg.el ? arg.el : null;
                            if (!el || el.nodeType !== 1) return;
                            if (el.id === 'chat-input' || el.id === 'chat-send' ||
                                (el.closest && el.closest('.agent-chat[data-agent-id="' + agentId + '"]'))) {
                                refreshSend();
                            }
                        });

                        const afterMorph = (el) => {
                            if (!el || !touchesOurChat(el)) return;
                            attachAutoScroll();
                            if (pinned) {
                                const box = chatBox();
                                if (box) box.scrollTop = box.scrollHeight;
                            }
                            if (el.matches && el.matches('time.chat-time') || (el.querySelector && el.querySelector('time.chat-time'))) formatTimes(el);
                            // markdown de mensajes nuevos (5.3)
                            document.querySelectorAll('.md-target:not(.md-ready)').forEach((n) => {
                                if (n.closest('.agent-chat[data-agent-id="' + agentId + '"]')) window.TaapAgents.markdown.render(n);
                            });
                        };
                        window.Livewire.hook('morphed', (arg) => afterMorph(arg && arg.el));
                        window.Livewire.hook('island.morphed', (arg) => afterMorph(arg && arg.component && arg.component.el));
                        window.Livewire.hook('morph.added', (arg) => {
                            const el = arg && arg.el;
                            if (!el) return;
                            if (el.matches && (el.matches('time.chat-time') || (el.querySelector && el.querySelector('time.chat-time')))) formatTimes(el);
                            if (el.matches && el.matches('.md-target')) window.TaapAgents.markdown.render(el);
                        });
                    }
                    document.addEventListener('livewire:initialized', () => {
                        refreshSend();
                        pinned = isNearBottom();
                    });
                    refreshSend();
                    formatTimes();
                    pinned = isNearBottom();
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

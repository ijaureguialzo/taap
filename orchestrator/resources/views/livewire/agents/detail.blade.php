<div class="agent-detail" data-agent-id="{{ $agent->id }}">
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h1 class="h3 mb-0">{{ $agent->name }}</h1>
                <x-status-badge :status="$status" />
            </div>
            @if ($agent->description)
                <div class="text-muted">{{ $agent->description }}</div>
            @endif
        </div>
        @if ($canManage)
            <div class="d-flex gap-2">
                {{-- 4.2: los botones gobiernan con el estado EN VIVO
                     ($status, del canal); el modelo en memoria queda
                     desfasado hasta la próxima recarga. --}}
                @if ($status->isRunning())
                    <button class="btn btn-outline-warning btn-sm" wire:click="lifecycle('stop')"><x-icon name="stop-circle" class="me-1" />{{ __('agents.stop') }}</button>
                @else
                    <button class="btn btn-success btn-sm" wire:click="lifecycle('start')"><x-icon name="play-fill" class="me-1" />{{ __('agents.start') }}</button>
                @endif
                <button class="btn btn-outline-secondary btn-sm" wire:click="lifecycle('restart')"
                        @disabled(! $agent->container_name || $status === \App\Enums\AgentStatus::Starting)><x-icon name="arrow-repeat" class="me-1" />{{ __('agents.restart') }}</button>
            </div>
        @endif
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <livewire:agents.chat :agent="$agent" />
        </div>

        <div class="col-lg-4 d-flex flex-column gap-4">
            {{-- Ficha --}}
            <div class="card">
                <div class="card-body small">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-muted" style="width: 110px">{{ __('agents.runtime') }}</td>
                            <td>
                                {{ $agent->runtime->label() }}
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    @foreach (['format' => 'cap.format', 'session' => 'cap.session', 'apply' => 'cap.apply'] as $group => $labelKey)
                                        @if (!empty($capabilities[$group]))
                                            <span class="badge taap-chip" title="{{ __("agents.{$labelKey}") }}">
                                                {{ __("agents.{$labelKey}") }}:
                                                @if (is_array($capabilities[$group]))
                                                    {{ collect($capabilities[$group])->map(fn ($c) => __("agents.cap.{$c}"))->implode(' / ') }}
                                                @else
                                                    {{ __("agents.cap.{$capabilities[$group]}") }}
                                                @endif
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                        <tr><td class="text-muted">{{ __('agents.model') }}</td><td>{{ $agent->model?->name ?? '—' }}@if($agent->model?->provider) <span class="text-muted">({{ $agent->model->provider->name }})</span>@endif</td></tr>
                        <tr><td class="text-muted">{{ __('agents.container') }}</td><td class="text-truncate" style="max-width: 220px">{{ $agent->container_name ?? '—' }}</td></tr>
                        <tr><td class="text-muted">{{ __('agents.ip') }}</td><td>{{ $agent->ip ?? '—' }}</td></tr>
                        <tr><td class="text-muted">{{ __('agents.owner') }}</td><td>{{ $agent->owner?->name }}</td></tr>
                        <tr><td class="text-muted">{{ __('agents.internet') }}</td><td>{{ $agent->internet_enabled ? 'sí' : 'no' }}</td></tr>
                    </table>
                </div>
            </div>

            {{-- Visor de logs (agent-log-viewer) --}}
            <div class="card" id="logs-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><x-icon name="terminal" class="me-1" />{{ __('agents.logs_title') }}</span>
                    <button class="btn btn-outline-secondary btn-sm" wire:click="toggleLogs">
                        {{ $showLogs ? __('agents.logs_hide') : __('agents.logs_show') }}
                    </button>
                </div>
                @if ($showLogs)
                    <div class="card-body small">
                        <div class="d-flex gap-1 mb-2">
                            @foreach ([50, 200, 500] as $t)
                                <button class="btn btn-sm {{ $logsTail === $t ? 'btn-primary' : 'btn-outline-secondary' }}" wire:click="setLogsTail({{ $t }})">{{ $t }}</button>
                            @endforeach
                            <button class="btn btn-sm btn-outline-secondary ms-auto" wire:click="refreshLogs"><x-icon name="arrow-repeat" /></button>
                        </div>
                        @if ($logsStatus === 'no-container')
                            <p class="text-muted small mb-0">{{ __('agents.logs_no_container') }}</p>
                        @elseif ($logsStatus === 'unavailable')
                            <p class="text-muted small mb-0">{{ __('agents.logs_unavailable') }}</p>
                        @else
                            <pre class="logs-pre small mb-2" style="max-height: 320px; overflow: auto; white-space: pre-wrap; word-break: break-all;">{{ $logsOutput }}</pre>
                        @endif
                        <button class="btn btn-sm btn-outline-secondary" onclick="window.copyToClipboard(document.querySelector('#logs-card pre')?.textContent || '').then(() => alert('{{ __('agents.logs_copied') }}')).catch(() => {})">{{ __('agents.logs_copy') }}</button>
                    </div>
                @endif
            </div>

            {{-- Linea de tiempo (7.2, design D6) --}}
            <div class="card" id="timeline-card">
                <div class="card-header"><x-icon name="clock-history" class="me-1" />{{ __('timeline.title') }}</div>
                <div class="card-body small">
                    @forelse ($windows as $w)
                        <div class="border-bottom py-2 mb-2 {{ $w['open'] ? 'border-primary' : '' }}">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold {{ $w['open'] ? 'text-primary' : 'text-success' }}">
                                    {{ $w['open'] ? __('timeline.open') : __('timeline.done') }}
                                </span>
                                <span class="text-muted">
                                    <time class="tl-time" data-time="{{ $w['start']->toIso8601String() }}">{{ $w['start']->format('d/m H:i') }}</time>
                                    @if ($w['end']) – <time class="tl-time" data-time="{{ $w['end']->toIso8601String() }}">{{ $w['end']->format('H:i') }}</time> @endif
                                    · {{ app(App\Agents\Timeline::class)->humanDuration($w) }}
                                </span>
                            </div>
                            @if ($w['note'])
                                <div class="text-truncate" title="{{ $w['note'] }}">{{ $w['note'] }}</div>
                            @endif
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                @if ($w['messages'] > 0)
                                    <span class="badge taap-chip">{{ __('timeline.messages') }}: {{ $w['messages'] }}</span>
                                @endif
                                @if ($w['tool_calls'] > 0)
                                    <span class="badge taap-chip">{{ __('timeline.tools') }}: {{ $w['tool_calls'] }}</span>
                                    @foreach (array_slice($w['tools'], 0, 4) as $tool)
                                        <span class="badge text-bg-secondary">{{ $tool }}</span>
                                    @endforeach
                                @endif
                                @if ($w['approvals'] > 0)
                                    <span class="badge text-bg-warning">{{ __('timeline.approvals') }}: {{ $w['approvals'] }}</span>
                                @endif
                                @if ($w['errors'] > 0)
                                    <span class="badge text-bg-danger">{{ __('timeline.errors') }}: {{ $w['errors'] }}</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">{{ __('timeline.empty') }}</p>
                    @endforelse
                </div>
            </div>

            <script>
                // Ventanas en la zona horaria del visor (web-chat-ux D2): el
                // servidor envia instantes ISO (data-time) y el cliente
                // renderiza d/m H:i local. Fallback (sin JS): la hora del
                // servidor. Idempotente (FLAG) como el chat.
                (() => {
                    const FLAG = '__taapTimelineSetup_' + {{ $agent->id }};
                    if (window[FLAG]) return;
                    window[FLAG] = true;
                    const card = document.querySelector('#timeline-card');
                    if (!card) return;
                    const fmt = new Intl.DateTimeFormat(undefined, {
                        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
                    });
                    const apply = (scope) => {
                        (scope || card).querySelectorAll('time.tl-time[data-time]').forEach((el) => {
                            const d = new Date(el.getAttribute('data-time'));
                            if (isNaN(d)) return;
                            const out = fmt.format(d);
                            // IDEMPOTENTE: solo asignar si cambia. `el.textContent = x`
                            // reemplaza el nodo de texto incluso si x es identico y eso
                            // dispara el MutationObserver de abajo, que vuelve a llamar a
                            // apply()... bucle infinito (CPU al 100% y OOM de la pestana,
                            // el navegador la mataba al pulsar Guardar: el morph de cada
                            // update reescribe las horas en UTC del servidor y el
                            // reformateo local nunca convergia).
                            if (el.textContent !== out) el.textContent = out;
                        });
                    };
                    apply();
                    // Los nodos de la linea se re-renderizan por morph
                    // (nuevas ventanas, cierre de ventana abierta): re-aplicar.
                    new MutationObserver(() => apply()).observe(card, { childList: true, subtree: true, characterData: true });
                })();
            </script>

            {{-- Configuracion --}}
            @if ($canManage)
                <div class="card">
                    <div class="card-header"><x-icon name="gear" class="me-1" />{{ __('actions.edit') }}</div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label small">{{ __('agents.model') }}</label>
                            <select wire:model="model_id" class="form-select form-select-sm">
                                <option value="">—</option>
                                @foreach ($models as $m)
                                    <option value="{{ $m->id }}">{{ $m->name }} ({{ $m->provider?->name }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">{{ __('agents.system_prompt') }}</label>
                            <textarea wire:model="system_prompt" rows="3" class="form-control form-control-sm"></textarea>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" wire:model="internet_enabled" id="d-internet">
                            <label class="form-check-label small" for="d-internet">{{ __('agents.internet') }}</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" wire:model="auto_restart" id="d-restart">
                            <label class="form-check-label small" for="d-restart">{{ __('agents.auto_restart') }}</label>
                        </div>
                        <button class="btn btn-primary btn-sm" wire:click="saveConfig"><x-icon name="check-lg" class="me-1" />{{ __('actions.save') }}</button>
                    </div>
                </div>

                {{-- Carpetas del FS compartido (shared-filesystem 7.1) --}}
                <div class="card">
                    <div class="card-header"><x-icon name="folder2" class="me-1" />{{ __('agents.fs_folders') }}</div>
                    <div class="card-body">
                        @forelse ($fsMounts as $m)
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <code class="small flex-grow-1 break-all">/fs{{ $m['path'] }}</code>
                                @if ($m['external'])
                                    <span class="badge text-bg-secondary" title="{{ __('agents.fs_external') }}">{{ __('agents.fs_external') }}</span>
                                @endif
                                <select class="form-select form-select-sm w-auto"
                                        x-on:change="$wire.updateFsMode('{{ $m['id'] }}', $event.target.value)">
                                    @foreach (['disabled' => 'fs_mode.disabled', 'read_only' => 'fs_mode.read_only', 'read_write' => 'fs_mode.read_write'] as $value => $label)
                                        <option value="{{ $value }}" @selected($m['mode'] === $value)>{{ __('agents.'.$label) }}</option>
                                    @endforeach
                                </select>
                                <button class="btn btn-outline-danger btn-sm" wire:click="removeFsMount('{{ $m['id'] }}')"
                                        data-confirm="{{ __('agents.fs_removed') }}">
                                    <x-icon name="trash3" label="{{ __('agents.fs_remove') }}" class="me-1" />
                                </button>
                            </div>
                        @empty
                            <p class="text-muted small mb-2">{{ __('agents.fs_empty') }}</p>
                        @endforelse

                        <div class="border rounded p-2 mt-2">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <button class="btn btn-outline-secondary btn-sm" wire:click="fsBrowseTo('/')">/
                                </button>
                                <code class="small flex-grow-1 break-all">{{ $fsBrowse }}</code>
                                <button class="btn btn-outline-secondary btn-sm" wire:click="fsSelectDir">{{ __('agents.fs_select') }}</button>
                            </div>
                            <table class="table table-sm table-bordered align-middle mb-2">
                                <tbody>
                                    @forelse ($fsEntries as $e)
                                        <tr @class(['text-muted' => $e['type'] !== 'dir'])>
                                            <td>
                                                @if ($e['type'] === 'dir')
                                                    <button class="btn btn-link btn-sm p-0 text-start" wire:click="fsBrowseTo('{{ $fsBrowse === '/' ? '/' : rtrim($fsBrowse, '/') }}/{{ $e['name'] }}')">
                                                        <x-icon name="folder2" class="me-1" />{{ $e['name'] }}
                                                    </button>
                                                @else
                                                    <span class="text-muted"><x-icon name="file" class="me-1" />{{ $e['name'] }}</span>
                                                @endif
                                            </td>
                                            <td class="text-end small text-muted">{{ $e['type'] === 'dir' ? __('files.type.dir') : number_format($e['size']) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-muted small">{{ __('files.empty') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                            <div class="d-flex gap-2">
                                <input type="text" wire:model="newFsPath" class="form-control form-control-sm flex-grow-1"
                                       placeholder="{{ __('agents.fs_path_ph') }}" wire:keydown.enter="addFsMount">
                                <select wire:model="newFsMode" class="form-select form-select-sm w-auto">
                                    @foreach (['disabled' => 'fs_mode.disabled', 'read_only' => 'fs_mode.read_only', 'read_write' => 'fs_mode.read_write'] as $value => $label)
                                        <option value="{{ $value }}">{{ __('agents.'.$label) }}</option>
                                    @endforeach
                                </select>
                                <button class="btn btn-primary btn-sm" wire:click="addFsMount"><x-icon name="plus-lg" class="me-1" />{{ __('agents.fs_add') }}</button>
                            </div>
                            @if ($fsError)
                                <div class="text-danger small mt-1">{{ $fsError }}</div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="card border-danger">
                    <div class="card-body py-2">
                        <button class="btn btn-outline-danger btn-sm" wire:click="delete"
                                data-confirm="{{ __('agents.delete_confirm') }}">
                            <x-icon name="trash3" label="{{ __('agents.delete') }}" class="me-1" />{{ __('agents.delete') }}
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <script>
        // Estado en vivo del badge + confirm de borrado.
        (() => {
            const root = document.querySelector('.agent-detail');
            if (!root) return;
            // Flag a nivel de window (NO en el nodo): el morph de Livewire
            // re-sincroniza los atributos del nodo raiz con el HTML del
            // servidor y BORRA cualquier data-attr client-only; un flag en
            // el nodo se perdia en el primer update y el setup se re-
            // ejecutaba (o moria) sin control.
            const agentId = root.dataset.agentId;
            const FLAG = '__taapDetailSetup_' + agentId;
            // Este <script> inline corre ANTES que los modulos (app.js y
            // livewire.js): si las dependencias aun no existen, reintenta
            // (el flag a nivel window indica exito, no solo "se intento").
            const ready = () =>
                window.TaapAgents && window.Livewire;
            const currentRoot = () =>
                document.querySelector('.agent-detail[data-agent-id="' + agentId + '"]') || root;
            // Resuelve la INSTANCIA actual en cada evento. (En Livewire 4
            // `find()` espera el wire:id como string y devuelve el proxy
            // `$wire`; capturar la instancia una sola vez dejaba el
            // listener apuntando a la instancia muerta y los updates
            // morian en silencio. `root.id` (DOM) esta vacio y `find(root.id)`
            // devolvia siempre undefined: el canal nunca se suscribia.)
            const currentWire = () => {
                const el = currentRoot();
                return el ? window.Livewire.find(el.getAttribute('wire:id')) : null;
            };
            const setup = () => {
                if (window[FLAG]) return; // idempotente (re-ejecuciones del script)
                // Se marca ANTES del trabajo: si algo lanza, el retry sigue.
                window[FLAG] = true;
                try {
                    // 4.1: suscripción al canal público vía helper
                    // compartido (re-suscripción 30 s + connected): el
                    // status.update pinta el badge con el mapa completo
                    // (icono+clase+etiqueta) y el resto va al componente.
                    window.TaapAgents.subscribeAgent(agentId, (payload) => {
                        const wire = currentWire();
                        if (wire) wire.agentEvent(payload);
                        if (payload.type === 'status.change') {
                            const s = payload.payload?.status;
                            const badge = currentRoot().querySelector('.live-status[data-status-map]');
                            if (s && badge) window.TaapAgents.applyStatus(badge, s);
                        }
                    });
                } catch (e) {
                    window[FLAG] = false; // permitir retry
                    console.warn('taap: detail setup', e);
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
            // Confirm de borrado por DELEGACION en `document` (sobrevive a
            // cualquier morph; un listener unido al nodo moria si el botón
            // se reemplaza). Un solo listener para toda la vida de la pagina.
            if (!window['__taapDetailConfirm_' + agentId]) {
                window['__taapDetailConfirm_' + agentId] = true;
                document.addEventListener('click', (e) => {
                    const btn = e.target && e.target.closest ? e.target.closest('[data-confirm]') : null;
                    if (!btn) return;
                    if (!btn.closest('.agent-detail[data-agent-id="' + agentId + '"]')) return;
                    if (!window.confirm(btn.dataset.confirm)) e.preventDefault();
                });
            }
        })();
    </script>
</div>

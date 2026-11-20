<div class="agent-detail" data-agent-id="{{ $agent->id }}">
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h1 class="h3 mb-0">{{ $agent->name }}</h1>
                <span class="badge status-{{ $status->value }} live-status" data-status-value="{{ $status->value }}">
                    <span data-status-label data-status-value="{{ $status->value }}">{{ __('status.' . $status->value) }}</span>
                </span>
            </div>
            @if ($agent->description)
                <div class="text-muted">{{ $agent->description }}</div>
            @endif
        </div>
        @if ($canManage)
            <div class="d-flex gap-2">
                @if ($agent->status->isRunning())
                    <button class="btn btn-outline-warning btn-sm" wire:click="lifecycle('stop')">{{ __('agents.stop') }}</button>
                @else
                    <button class="btn btn-success btn-sm" wire:click="lifecycle('start')">{{ __('agents.start') }}</button>
                @endif
                <button class="btn btn-outline-secondary btn-sm" wire:click="lifecycle('restart')"
                        @disabled(! $agent->container_name)>{{ __('agents.restart') }}</button>
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
                                            <span class="badge text-bg-light border" title="{{ __("agents.{$labelKey}") }}">
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

            {{-- Linea de tiempo (7.2, design D6) --}}
            <div class="card">
                <div class="card-header">{{ __('timeline.title') }}</div>
                <div class="card-body small">
                    @forelse ($windows as $w)
                        <div class="border-bottom py-2 mb-2 {{ $w['open'] ? 'border-primary' : '' }}">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold {{ $w['open'] ? 'text-primary' : 'text-success' }}">
                                    {{ $w['open'] ? __('timeline.open') : __('timeline.done') }}
                                </span>
                                <span class="text-muted">
                                    {{ $w['start']->format('d/m H:i') }}
                                    @if ($w['end']) – {{ $w['end']->format('H:i') }} @endif
                                    · {{ app(App\Agents\Timeline::class)->humanDuration($w) }}
                                </span>
                            </div>
                            @if ($w['note'])
                                <div class="text-truncate" title="{{ $w['note'] }}">{{ $w['note'] }}</div>
                            @endif
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                @if ($w['messages'] > 0)
                                    <span class="badge text-bg-light border">{{ __('timeline.messages') }}: {{ $w['messages'] }}</span>
                                @endif
                                @if ($w['tool_calls'] > 0)
                                    <span class="badge text-bg-light border">{{ __('timeline.tools') }}: {{ $w['tool_calls'] }}</span>
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

            {{-- Configuracion --}}
            @if ($canManage)
                <div class="card">
                    <div class="card-header">{{ __('actions.edit') }}</div>
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
                        <button class="btn btn-primary btn-sm" wire:click="saveConfig">{{ __('actions.save') }}</button>
                    </div>
                </div>

                <div class="card border-danger">
                    <div class="card-body py-2">
                        <button class="btn btn-outline-danger btn-sm" wire:click="delete"
                                data-confirm="{{ __('agents.delete_confirm') }}">
                            {{ __('agents.delete') }}
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
            // El script se re-ejecuta en cada update: flag anti-duplicados.
            if (root.dataset.detailReady) return;
            root.dataset.detailReady = '1';
            const wire = Livewire.find(root.id);
            if (!wire || !window.TaapEcho || !window.TaapPusher) return;

            const id = root.dataset.agentId;
            const labels = {};
            root.querySelectorAll('[data-status-label]').forEach(n => {
                labels[n.dataset.statusValue] = n.textContent;
            });

            const channel = window.TaapEcho.channel(`agent.${id}`);
            channel.listen('agent-event', (payload) => {
                wire.agentEvent(payload);
                if (payload.type === 'status.change') {
                    const s = payload.payload?.status;
                    const badge = root.querySelector('[data-status-label]');
                    if (s && labels[s] && badge) badge.textContent = labels[s];
                }
            });

            root.querySelectorAll('[data-confirm]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    if (!window.confirm(btn.dataset.confirm)) e.preventDefault();
                });
            });
        })();
    </script>
</div>

<div wire:init="$set('liveStatus', [])" class="agents-index" data-agent-count="{{ $agents->count() }}">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ __('agents.title') }}</h1>
        <a href="{{ route('agents.create') }}" class="btn btn-primary">
            <x-icon name="plus-lg" class="me-1" />{{ __('agents.new') }}
        </a>
    </div>

    <div class="mb-3">
        <input type="search" wire:model.live.300ms="search"
               class="form-control" style="max-width: 320px"
               placeholder="{{ __('agents.title') }}…" />
    </div>

    @if ($agents->isEmpty())
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <p class="fs-5 mb-1">{{ __('agents.empty') }}</p>
                @if (! $canCreate)
                    <p class="small">{{ __('agents.quota') }}</p>
                @endif
            </div>
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ __('agents.name') }}</th>
                            <th>{{ __('agents.runtime') }}</th>
                            <th>{{ __('agents.model') }}</th>
                            <th>{{ __('agents.status') }}</th>
                            <th class="text-end">{{ __('agents.ip') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($agents as $agent)
                            @php
                                $live = $this->liveStatus[$agent->id] ?? null;
                                $status = $live ? \App\Enums\AgentStatus::fromString($live) : $agent->status;
                            @endphp
                            <tr wire:navigate>
                                <td>
                                    <a href="{{ route('agents.show', $agent) }}" class="fw-semibold text-decoration-none" wire:navigate>
                                        {{ $agent->name }}
                                    </a>
                                    @if ($agent->description)
                                        <div class="small text-muted">{{ \Illuminate\Support\Str::limit($agent->description, 60) }}</div>
                                    @endif
                                </td>
                                <td><span class="badge bg-secondary">{{ $agent->runtime->value }}</span></td>
                                <td class="small">{{ $agent->model?->name ?? '—' }}</td>
                                <td>
                                <x-status-badge :status="$status" :agent-id="$agent->id" />
                                </td>
                                <td class="text-end small text-muted">{{ $agent->ip ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">
            {{ $agents->links() }}
        </div>
    @endif

    <script>
        // Estado en vivo: un canal `agent.{id}` por agente visible. Al recibir
        // status.change, se actualiza la insignia sin refrescar la pagina.
        (() => {
            const root = document.querySelector('.agents-index');
            if (!root || !window.TaapEcho || !window.TaapPusher) return;
            const wire = Livewire.find(root.id);
            if (!wire) return;

            const labels = {};
            // la pagina inyecta las etiquetas localizadas
            root.querySelectorAll('[data-status-label]').forEach(n => {
                labels[n.dataset.statusValue] = n.textContent;
            });

            root.querySelectorAll('[data-agent-id]').forEach(el => {
                const id = el.dataset.agentId;
                const channel = window.TaapEcho.private(`agent.${id}`);
                channel.listen('agent-event', (payload) => {
                    wire.receiveAgentEvent(payload);
                    if (payload.type === 'status.change') {
                        const s = payload.payload?.status;
                        if (s && labels[s]) {
                            el.textContent = labels[s];
                        }
                    }
                });
            });
        })();
    </script>
</div>

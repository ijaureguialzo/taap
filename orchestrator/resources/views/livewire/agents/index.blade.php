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
        // Estado en vivo (4.1): un canal público `agent.{id}` por agente
        // visible, vía el helper compartido (re-suscripción 30 s +
        // connected). Antes usaba `Echo.private` (el canal difunde sin
        // auth: con private la web no resolvía el authentication endpoint
        // y los badges quedaban congelados) y sin re-suscripción.
        (() => {
            const root = document.querySelector('.agents-index');
            if (!root || !window.TaapAgents) return;
            const wire = Livewire.find(root.id);
            if (!wire) return;

            root.querySelectorAll('[data-agent-id]').forEach(el => {
                const id = el.dataset.agentId;
                window.TaapAgents.subscribeAgent(id, (payload) => {
                    if (payload.type !== 'status.change') return;
                    const s = payload.payload?.status;
                    if (!s) return;
                    wire.receiveAgentEvent(payload);
                    // El badge de la fila (el nodo puede haber sido
                    // sustituido por un morph: se resuelve de nuevo).
                    const badge = (el.closest('tr') || el).querySelector('.live-status[data-status-map]');
                    if (badge) window.TaapAgents.applyStatus(badge, s);
                });
            });
        })();
    </script>
</div>

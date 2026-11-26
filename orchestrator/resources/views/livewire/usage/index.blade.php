<div class="usage">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><x-icon name="graph-up" class="me-2" />{{ __('usage.title') }}</h1>
        <a href="{{ route('models.index') }}" wire:navigate class="btn btn-outline-secondary btn-sm">← {{ __('actions.back') }}</a>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small">{{ __('usage.filter_from') }}</label>
                    <input type="date" wire:model.live="fromDate" class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">{{ __('usage.filter_to') }}</label>
                    <input type="date" wire:model.live="toDate" class="form-control form-control-sm">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="resetFilters">{{ __('usage.reset') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">{{ __('usage.requests') }}</div><div class="h4 mb-0">{{ $totals->requests ?? 0 }}</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">{{ __('usage.tokens_in') }}</div><div class="h4 mb-0">{{ $totals->tokens_in ?? 0 }}</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">{{ __('usage.tokens_out') }}</div><div class="h4 mb-0">{{ $totals->tokens_out ?? 0 }}</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">{{ __('usage.errors') }}</div><div class="h4 mb-0">{{ $totals->errors ?? 0 }}</div></div></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6">{{ __('usage.top_agents') }}</h2>
                    <ul class="list-unstyled small mb-0">
                        @foreach ($perAgent as $row)
                            <li class="d-flex justify-content-between border-bottom py-1">
                                <span>{{ $agentNames[$row->agent_id] ?? $row->agent_id }}</span>
                                <span class="text-muted">{{ $row->requests }} req · {{ $row->tokens_in }}→{{ $row->tokens_out }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr>
                    <th>{{ __('usage.time') }}</th>
                    <th>{{ __('usage.agent') }}</th>
                    <th>{{ __('usage.tokens') }}</th>
                    <th>{{ __('usage.status') }}</th>
                    <th>{{ __('usage.latency') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td class="small text-nowrap">{{ $entry->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td class="small">{{ $entry->agent?->name ?? '—' }}</td>
                            <td class="small">{{ $entry->tokens_input }}→{{ $entry->tokens_output }}</td>
                            <td class="small">{{ $entry->status ?? '—' }}</td>
                            <td class="small">{{ $entry->latency_ms }} ms</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">{{ __('usage.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-body border-top">{{ $entries->links() }}</div>
    </div>
</div>

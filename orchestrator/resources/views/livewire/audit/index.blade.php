<div class="audit">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><x-icon name="clock-history" class="me-2" />{{ __('audit.title') }}</h1>
        <a href="{{ route('agents.index') }}" wire:navigate class="btn btn-outline-secondary btn-sm">← {{ __('actions.back') }}</a>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small">{{ __('audit.filter_actor') }}</label>
                    <select wire:model.live="actorId" class="form-select form-select-sm">
                        <option value="">{{ __('audit.all_actors') }}</option>
                        @foreach ($actors as $actor)
                            <option value="{{ $actor->id }}">{{ $actor->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">{{ __('audit.filter_action') }}</label>
                    <select wire:model.live="action" class="form-select form-select-sm">
                        <option value="">{{ __('audit.all_actions') }}</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action }}">{{ $action }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">{{ __('audit.filter_target_type') }}</label>
                    <select wire:model.live="targetType" class="form-select form-select-sm">
                        <option value="">{{ __('audit.all_targets') }}</option>
                        @foreach ($targetTypes as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">{{ __('audit.filter_from') }}</label>
                    <input type="date" wire:model.live="fromDate" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">{{ __('audit.filter_to') }}</label>
                    <input type="date" wire:model.live="toDate" class="form-control form-control-sm">
                </div>
            </div>
            <div class="mt-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="resetFilters">{{ __('audit.reset') }}</button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>{{ __('audit.time') }}</th>
                        <th>{{ __('audit.actor') }}</th>
                        <th>{{ __('audit.action') }}</th>
                        <th>{{ __('audit.target') }}</th>
                        <th>{{ __('audit.metadata') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td class="small text-nowrap">{{ $entry->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="small">{{ $entry->actor?->name ?? '—' }}</td>
                            <td class="small"><code>{{ $entry->action }}</code></td>
                            <td class="small">{{ $entry->target_type }}{{ $entry->target_id ? ' #'.$entry->target_id : '' }}</td>
                            <td class="small text-muted">@if ($entry->metadata)<pre class="mb-0">{{ json_encode($entry->metadata, JSON_UNESCAPED_UNICODE) }}</pre>@else —@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">{{ __('audit.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-body border-top">
            {{ $entries->links() }}
        </div>
    </div>
</div>

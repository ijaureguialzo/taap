<div class="models-index">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ __('models.title') }}</h1>
        @unless ($creating)
            <button class="btn btn-primary btn-sm" wire:click="startCreate">+ {{ __('models.new') }}</button>
        @endunless
    </div>

    @if ($creating)
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('models.provider') }} *</label>
                        <select wire:model="provider_id" class="form-select form-select-sm" required>
                            <option value="">—</option>
                            @foreach ($providers as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('models.model_id') }} *</label>
                        <input type="text" wire:model="model_id" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('models.name') }}</label>
                        <input type="text" wire:model="name" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small">{{ __('models.context') }}</label>
                        <input type="number" wire:model="context_window" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">{{ __('models.max_tokens') }}</label>
                        <input type="number" wire:model="max_tokens" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary btn-sm" wire:click="save">{{ __('actions.save') }}</button>
                    <button class="btn btn-outline-secondary btn-sm" wire:click="cancel">{{ __('actions.cancel') }}</button>
                </div>
            </div>
        </div>
    @endif

    @foreach ($providers as $provider)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <span class="fw-semibold">{{ $provider->name }} <span class="text-muted fw-normal small">{{ $provider->base_url }}</span></span>
                <span class="badge bg-secondary">{{ $provider->models_count }}</span>
            </div>
            <div class="card-body py-2">
                @if (($models->get($provider->id) ?? collect())->isEmpty())
                    <div class="text-muted small">{{ __('models.empty') }}</div>
                @else
                    <div class="d-flex flex-wrap gap-2">
                        @foreach ($models->get($provider->id) as $m)
                            <span class="badge bg-light text-dark border">
                                {{ $m->name }} <span class="text-muted">({{ $m->model_id }})</span>
                                <button class="btn btn-sm btn-link text-danger p-0 ms-1" style="line-height:1"
                                        wire:click="delete({{ $m->id }})" data-confirm="{{ __('models.delete') }}?">✕</button>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endforeach

    @if ($providers->isEmpty())
        <div class="card"><div class="card-body text-center text-muted py-5">{{ __('providers.empty') }}</div></div>
    @endif

    <script>
        document.querySelectorAll('.models-index [data-confirm]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                if (!window.confirm(btn.dataset.confirm)) e.preventDefault();
            });
        });
    </script>
</div>

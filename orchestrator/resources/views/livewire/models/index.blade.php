<div class="models-index">
    {{-- Aviso de las acciones (check/save/borrado): dentro del componente,
         porque en una actualizacion Livewire el layout no se re-renderiza. --}}
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ __('models.title') }}</h1>
        @unless ($creating)
            @if ($isAdmin)
                <button class="btn btn-primary btn-sm" wire:click="startCreate">+ {{ __('models.new') }}</button>
            @endif
        @endunless
    </div>

    @if ($creating)
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    @if ($edit_id)
                        {{-- D5: el proveedor es INMODIFICABLE — se envia oculto
                             con el valor original (etiqueta visible, sin editar). --}}
                        <div class="col-md-3">
                            <label class="form-label small">{{ __('models.provider') }} *</label>
                            <input type="hidden" wire:model="provider_id">
                            <input type="text" class="form-control form-control-sm" disabled
                                   value="{{ $providers->firstWhere('id', $provider_id)?->name ?? '' }}">
                            <div class="form-text">{{ __('models.provider_locked') }}</div>
                        </div>
                    @else
                        <div class="col-md-3">
                            <label class="form-label small">{{ __('models.provider') }} *</label>
                            <select wire:model="provider_id" class="form-select form-select-sm" required>
                                <option value="">—</option>
                                @foreach ($providers as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
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
                <div class="row g-3 mt-0">
                    <div class="col-md-12">
                        <span class="form-label small">{{ __('models.capabilities') }}</span>
                        @foreach (['tools' => __('models.cap_tools'), 'vision' => __('models.cap_vision'), 'streaming' => __('models.cap_streaming')] as $cap => $label)
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" wire:model="capabilities"
                                       id="cap{{ ucfirst($cap) }}" value="{{ $cap }}">
                                <label class="form-check-label small" for="cap{{ ucfirst($cap) }}">{{ $label }}</label>
                            </div>
                        @endforeach
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
                                @if ($isAdmin)
                                    <button class="btn btn-sm btn-link text-secondary p-0 ms-1" style="line-height:1"
                                            wire:click="startEdit({{ $m->id }})" title="{{ __('actions.edit') }}">✎</button>
                                    <button class="btn btn-sm btn-link text-danger p-0 ms-1" style="line-height:1"
                                            wire:click="delete({{ $m->id }})" data-confirm="{{ e($confirms[$m->id] ?? __('models.delete').'?') }}">✕</button>
                                @endif
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

<div class="providers-index">
    {{-- El layout renderiza el flash solo en la carga inicial de pagina: en una
         actualizacion Livewire NO se re-renderiza el layout, asi que el aviso de
         estas acciones (check/save) se muestra aqui, dentro del componente.
         (El flash se consume al terminar la actualizacion: aviso de un uso.) --}}
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
        <h1 class="h3 mb-0">{{ __('providers.title') }}</h1>
        @unless ($creating)
            @if ($isAdmin)
                <button class="btn btn-primary btn-sm" wire:click="startCreate">+ {{ __('providers.new') }}</button>
            @endif
        @endunless
    </div>

    {{-- Formulario (crear/editar) --}}
    @if ($creating)
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('providers.name') }} *</label>
                        <input type="text" wire:model="name" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('providers.type') }}</label>
                        <select wire:model="type" class="form-select form-select-sm">
                            <option value="openai">openai</option>
                            <option value="anthropic">anthropic</option>
                            <option value="other">other</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('providers.base_url') }} *</label>
                        <input type="url" wire:model="base_url" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('providers.api_key') }}</label>
                        <input type="password" wire:model="api_key" class="form-control form-control-sm"
                               placeholder="{{ $key_set ? __('providers.key_set') : '' }}"
                               autocomplete="new-password">
                        @if ($key_set)
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" wire:model="delete_key" id="deleteKey">
                                <label class="form-check-label small" for="deleteKey">{{ __('providers.delete_key') }}</label>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary btn-sm" wire:click="save">{{ __('actions.save') }}</button>
                    <button class="btn btn-outline-secondary btn-sm" wire:click="cancel">{{ __('actions.cancel') }}</button>
                </div>
            </div>
        </div>
    @endif

    @if ($providers->isEmpty())
        <div class="card"><div class="card-body text-center text-muted py-5">{{ __('providers.empty') }}</div></div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ __('providers.name') }}</th>
                            <th>{{ __('providers.type') }}</th>
                            <th>{{ __('providers.base_url') }}</th>
                            <th>{{ __('providers.health') }}</th>
                            <th>{{ __('models.title') }}</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($providers as $p)
                            <tr>
                                <td class="fw-semibold">{{ $p->name }}</td>
                                <td><span class="badge bg-secondary">{{ $p->type }}</span></td>
                                <td class="small text-truncate" style="max-width: 320px">{{ $p->base_url }}</td>
                                <td>
                                    @if ($p->isUnhealthy())
                                        <span class="badge bg-danger">{{ __('providers.status_error') }}</span>
                                    @elseif ($p->isHealthy())
                                        <span class="badge bg-success">{{ __('providers.status_ok') }}</span>
                                    @else
                                        <span class="badge bg-secondary">{{ __('providers.status_unchecked') }}</span>
                                    @endif
                                    @if ($p->last_checked_at)
                                        <div class="text-muted small">{{
                                            __('providers.last_checked', ['when' => $p->last_checked_at->format('Y-m-d H:i')])
                                        }}</div>
                                    @endif
                                </td>
                                <td class="text-center">{{ $p->models_count }}</td>
                                <td class="text-end">
                                    @if ($isAdmin)
                                        <button class="btn btn-outline-secondary btn-sm" wire:click="check({{ $p->id }})"
                                                title="{{ __('providers.check') }}">⇄</button>
                                        <button class="btn btn-outline-secondary btn-sm" wire:click="startEdit({{ $p->id }})">✎</button>
                                        <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $p->id }})"
                                                data-confirm="{{ e($confirms[$p->id] ?? __('providers.delete').'?') }}">🗑</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <script>
        document.querySelectorAll('.providers-index [data-confirm]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                if (!window.confirm(btn.dataset.confirm)) e.preventDefault();
            });
        });
    </script>
</div>

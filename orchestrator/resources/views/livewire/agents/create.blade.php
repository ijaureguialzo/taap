<div class="create-agent" style="max-width: 720px">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ __('agents.new') }}</h1>
        <a href="{{ route('agents.index') }}" wire:navigate class="btn btn-outline-secondary btn-sm">
            ← {{ __('actions.back') }}
        </a>
    </div>

    @unless ($canCreate)
        <div class="alert alert-info">{{ __('agents.quota') }}</div>
    @endunless

    <div class="card">
        <div class="card-body">
            <form wire:submit="submit" class="needs-validation">
                <div class="mb-3">
                    <label class="form-label">{{ __('agents.name') }} *</label>
                    <input type="text" wire:model="name" class="form-control @error('name') is-invalid @enderror"
                           placeholder="mi-agente" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ __('agents.description') }}</label>
                    <input type="text" wire:model="description" class="form-control"
                           placeholder="{{ __('agents.description') }}…">
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">{{ __('agents.runtime') }} *</label>
                        <select wire:model="runtime" class="form-select">
                            @foreach ($runtimes as $rt)
                                <option value="{{ $rt->value }}">{{ $rt->value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label">{{ __('agents.model') }} *</label>
                        <select wire:model="model_id" class="form-select @error('model_id') is-invalid @enderror">
                            <option value="">{{ __('agents.select_model') }}</option>
                            @foreach ($models as $model)
                                <option value="{{ $model->id }}">
                                    {{ $model->name }} ({{ $model->provider?->name }})
                                </option>
                            @endforeach
                        </select>
                        @error('model_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @if ($models->isEmpty())
                            <div class="form-text">{{ __('agents.no_models') }}</div>
                        @endif
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ __('agents.system_prompt') }}</label>
                    <textarea wire:model="system_prompt" rows="3" class="form-control"
                              placeholder="Eres un agente útil que…"></textarea>
                </div>

                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" wire:model="internet_enabled" id="internet">
                    <label class="form-check-label" for="internet">{{ __('agents.internet') }}</label>
                </div>
                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" wire:model="auto_restart" id="autorestart">
                    <label class="form-check-label" for="autorestart">{{ __('agents.auto_restart') }}</label>
                </div>

                <button type="submit" class="btn btn-primary" @disabled(! $canCreate)>
                    {{ __('agents.start') }}
                </button>
            </form>
        </div>
    </div>
</div>

<div class="create-agent" style="max-width: 720px">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ __('agents.new') }}</h1>
        <a href="{{ route('agents.index') }}" wire:navigate class="btn btn-outline-secondary btn-sm">
            <x-icon name="arrow-left" label="{{ __('actions.back') }}" />
        </a>
    </div>

    @unless ($canCreate)
        <div class="alert alert-info">{{ __('agents.quota') }}</div>
    @endunless

    <div class="card">
        <div class="card-body">
            <form wire:submit="submit" class="needs-validation">
                @if ($templates->isNotEmpty())
                    <div class="template-picker mb-3">
                        <label class="form-label">{{ __('agents.template_title') }}</label>
                        <div class="d-flex gap-2">
                            <select wire:model="template_id" class="form-select">
                                <option value="">{{ __('agents.template_none') }}</option>
                                @foreach ($templates as $template)
                                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                                @endforeach
                            </select>
                            <button type="button" class="btn btn-outline-primary" wire:click="applyTemplate" @disabled(! $template_id)>
                                {{ __('agents.template_apply') }}
                            </button>
                        </div>
                        <div class="form-text">{{ __('agents.template_hint') }}</div>
                    </div>
                @endif

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
                        <select wire:model.live="runtime" class="form-select">
                            @foreach ($runtimes as $rt)
                                <option value="{{ $rt }}">{{ \App\Enums\AgentRuntime::fromString($rt)->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-8 mb-3">
                        <label class="form-label">{{ __('agents.model') }} *</label>
                        <select wire:model.live="model_id" class="form-select @error('model_id') is-invalid @enderror">
                            <option value="">{{ __('agents.select_model') }}</option>
                            @foreach ($models as $model)
                                {{-- Marcado de no sanos (design D7): informativo, no
                                     bloqueante — la marca es un badge atenuado; el modelo
                                     sigue seleccionable. (El status llega con la relacion
                                     `provider` cargada completa en render().) --}}
                                @php
                                    $unhealthy = $model->provider?->isUnhealthy() ?? false;
                                @endphp
                                <option value="{{ $model->id }}" @class(['text-muted' => $unhealthy])>
                                    {{ $model->name }} ({{ $model->provider?->name }})
                                    @if ($unhealthy)
                                        <span class="badge bg-warning ms-1"><x-icon name="exclamation-triangle" class="me-1" />{{ __('agents.unhealthy') }}</span>
                                    @endif
                                </option>
                            @endforeach
                        </select>
                        @error('model_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @if ($discarded_model)
                            {{-- El modelo se descartO al cambiar de runtime: explicar el porquE
                                 (antes desaparecia en silencio y el submit fallaba con un error
                                 generico que no llevaba a ningun sitio). --}}
                            <div class="form-text text-warning">{{
                                __('agents.model_discarded', [
                                    'model' => $discarded_model->name,
                                    'provider' => $discarded_model->provider?->name,
                                    'runtime' => \App\Enums\AgentRuntime::fromString($runtime)->label(),
                                    'formats' => implode(' / ', collect(\App\Enums\AgentRuntime::fromString($runtime)->modelFormats())->map(fn ($f) => __("agents.cap.{$f}"))->all()),
                                ])
                            }}</div>
                        @endif
                        @if ($models->isEmpty())
                            {{-- Hint orientada: los modelos pueden EXISTIR y simplemente no
                                 ser compatibles con el runtime elegido. --}}
                            <div class="form-text">{{
                                __('agents.no_models', [
                                    'runtime' => \App\Enums\AgentRuntime::fromString($runtime)->label(),
                                    'formats' => implode(' / ', collect(\App\Enums\AgentRuntime::fromString($runtime)->modelFormats())->map(fn ($f) => __("agents.cap.{$f}"))->all()),
                                ])
                            }}</div>
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
                    <x-icon name="play-fill" class="me-1" />{{ __('agents.start') }}
                </button>
            </form>
        </div>
    </div>
</div>

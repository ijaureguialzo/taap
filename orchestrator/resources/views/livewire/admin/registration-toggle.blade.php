<div class="card">
    <div class="card-body">
        <h2 class="h6 mb-2">{{ __('settings.title') }}</h2>
        <p class="small text-muted mb-3">{{ __('settings.hint') }}</p>
        <div class="d-flex align-items-center gap-3">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" wire:model="enabled" id="settings-registration">
                <label class="form-check-label" for="settings-registration">{{ __('settings.registration') }}</label>
            </div>
            <button class="btn btn-primary btn-sm" wire:click="save"><x-icon name="check-lg" class="me-1" />{{ __('settings.save') }}</button>
        </div>
    </div>
</div>

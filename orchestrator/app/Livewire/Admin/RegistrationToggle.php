<?php

namespace App\Livewire\Admin;

use App\Services\AuditLogger;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class RegistrationToggle extends Component
{
    public bool $enabled = true;

    public function mount(): void
    {
        $this->enabled = app(Settings::class)->isRegistrationEnabled();
    }

    public function save(): void
    {
        DB::transaction(function () {
            app(Settings::class)->setRegistrationEnabled($this->enabled);
            app(AuditLogger::class)->log(auth()->user(), 'registration.toggled', 'settings', null, ['enabled' => $this->enabled]);
        });

        session()->flash('success', __('settings.registration_saved'));
    }

    public function render(): View
    {
        return view('livewire.admin.registration-toggle');
    }
}

<?php

namespace App\Livewire\Health;

use App\Services\HealthSummary;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public function render(): View
    {
        return view('livewire.health.index', [
            'summary' => app(HealthSummary::class)->__invoke(),
        ]);
    }
}

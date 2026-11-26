<?php

namespace App\Livewire\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public ?int $actorId = null;

    public string $action = '';

    public string $targetType = '';

    public string $fromDate = '';

    public string $toDate = '';

    public ?int $page = 1;

    public function mount(): void
    {
        $this->actorId = null;
        $this->action = '';
        $this->targetType = '';
        $this->fromDate = '';
        $this->toDate = '';
        $this->page = 1;
    }

    public function resetFilters(): void
    {
        $this->actorId = null;
        $this->action = '';
        $this->targetType = '';
        $this->fromDate = '';
        $this->toDate = '';
        $this->page = 1;
    }

    public function render(): View
    {
        $q = AuditLog::with('actor')->latest('created_at');

        if ($this->actorId) {
            $q->where('actor_id', $this->actorId);
        }

        if ($this->action !== '') {
            $q->where('action', $this->action);
        }

        if ($this->targetType !== '') {
            $q->where('target_type', $this->targetType);
        }

        if ($this->fromDate !== '') {
            $q->where('created_at', '>=', $this->fromDate.' 00:00:00');
        }

        if ($this->toDate !== '') {
            $q->where('created_at', '<=', $this->toDate.' 23:59:59');
        }

        $entries = $q->paginate(25)->withQueryString();

        $actors = User::orderBy('name')->get(['id', 'name']);
        $actions = AuditLog::distinct()->orderBy('action')->pluck('action')->filter()->values();
        $targetTypes = AuditLog::distinct()->orderBy('target_type')->pluck('target_type')->filter()->values();

        return view('livewire.audit.index', [
            'entries' => $entries,
            'actors' => $actors,
            'actions' => $actions,
            'targetTypes' => $targetTypes,
        ]);
    }
}

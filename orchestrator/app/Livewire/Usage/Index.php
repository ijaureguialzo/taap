<?php

namespace App\Livewire\Usage;

use App\Models\Agent;
use App\Models\ModelProvider;
use App\Models\ModelUsage;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public string $fromDate = '';

    public string $toDate = '';

    public ?int $page = 1;

    public function resetFilters(): void
    {
        $this->fromDate = '';
        $this->toDate = '';
        $this->page = 1;
    }

    public function render(): View
    {
        $q = ModelUsage::with('agent')->latest('created_at');

        if ($this->fromDate !== '') {
            $q->where('created_at', '>=', $this->fromDate.' 00:00:00');
        }
        if ($this->toDate !== '') {
            $q->where('created_at', '<=', $this->toDate.' 23:59:59');
        }

        $entries = $q->paginate(25)->withQueryString();

        $base = ModelUsage::query();
        if ($this->fromDate !== '') {
            $base->where('created_at', '>=', $this->fromDate.' 00:00:00');
        }
        if ($this->toDate !== '') {
            $base->where('created_at', '<=', $this->toDate.' 23:59:59');
        }

        $totals = (clone $base)->selectRaw('COUNT(*) as requests, SUM(tokens_input) as tokens_in, SUM(tokens_output) as tokens_out, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) as errors, AVG(latency_ms) as latency_avg')->first();

        $perAgent = (clone $base)
            ->selectRaw('agent_id, COUNT(*) as requests, SUM(tokens_input) as tokens_in, SUM(tokens_output) as tokens_out')
            ->groupBy('agent_id')->orderByDesc('requests')->limit(5)->get();
        $agentNames = Agent::whereIn('id', $perAgent->pluck('agent_id'))->pluck('name', 'id');

        return view('livewire.usage.index', [
            'entries' => $entries,
            'totals' => $totals,
            'perAgent' => $perAgent,
            'agentNames' => $agentNames,
        ]);
    }
}

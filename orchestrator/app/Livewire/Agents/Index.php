<?php

namespace App\Livewire\Agents;

use App\Models\Agent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    /**
     * Estado en vivo por agente (actualizado por el canal Reverb).
     *
     * @var array<int, string>
     */
    public array $liveStatus = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function receiveAgentEvent(array $payload): void
    {
        $id = $payload['agent_id'] ?? null;
        $type = $payload['type'] ?? null;

        if ($type === 'status.change' && $id !== null && ! empty($payload['payload']['status'])) {
            $this->liveStatus[(int) $id] = (string) $payload['payload']['status'];
        }
    }

    public function render(): View
    {
        $user = Auth::user();

        $agents = Agent::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('owner_id', $user->id))
            ->when($this->search !== '', function ($q) {
                $q->where(function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('description', 'like', '%'.$this->search.'%');
                });
            })
            ->with(['model', 'model.provider', 'owner'])
            ->withCount('messages')
            ->latest('id')
            ->paginate(15)
            ->appends(request()->except('page'));

        return view('livewire.agents.index', [
            'agents' => $agents,
            'canCreate' => $user->canCreateAgent(),
        ]);
    }
}

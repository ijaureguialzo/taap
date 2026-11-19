<?php

namespace App\Livewire\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    /** Estado en vivo por agente (actualizado por el canal Reverb). */
    public array $liveStatus = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function receiveAgentEvent(array $payload): void
    {
        $id = $payload['agent_id'] ?? null;
        $type = $payload['type'] ?? null;

        if ($type === 'status.change' && ! empty($payload['payload']['status'])) {
            $this->liveStatus[$id] = $payload['payload']['status'];
        }
    }

    public function render()
    {
        $user = Auth::user();

        $agents = Agent::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('owner_id', $user->id))
            ->when($this->search !== '', function ($q) {
                $q->where(function ($q) {
                    $q->where('name', 'like', '%' . $this->search . '%')
                        ->orWhere('description', 'like', '%' . $this->search . '%');
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

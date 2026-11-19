<?php

namespace App\Livewire\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public ?int $edit_id = null;
    public ?UserRole $role = null;
    public ?bool $active = null;
    public ?int $max_agents = null;
    public string $language = 'es';

    public function startEdit(int $id): void
    {
        $u = User::findOrFail($id);
        $this->edit_id = $id;
        $this->role = $u->role;
        $this->active = $u->active;
        $this->max_agents = $u->agent_quota;
        $this->language = (string) $u->locale;
    }

    public function cancel(): void
    {
        $this->edit_id = null;
    }

    public function save(): void
    {
        $this->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
            'max_agents' => ['nullable', 'integer', 'min:0', 'max:100'],
            'language' => ['required', Rule::in(['es', 'en', 'eu'])],
        ]);

        User::whereKey($this->edit_id)->update([
            'role' => $this->role,
            'active' => $this->active,
            'agent_quota' => $this->max_agents,
            'locale' => $this->language,
        ]);

        $this->cancel();
        session()->flash('success', __('actions.save'));
    }

    public function delete(int $id): void
    {
        if ($id === auth()->id()) {
            session()->flash('error', 'No puedes eliminarte a ti mismo.');
            return;
        }
        User::whereKey($id)->delete(); // soft delete
    }

    public function render()
    {
        $users = User::withCount('agents')
            ->latest('id')
            ->paginate(20)
            ->appends(request()->except('page'));

        return view('livewire.users.index', [
            'users' => $users,
        ]);
    }
}

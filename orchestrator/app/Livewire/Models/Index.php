<?php

namespace App\Livewire\Models;

use App\Models\Model;
use App\Models\ModelProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public bool $creating = false;

    public ?int $provider_id = null;

    public string $model_id = '';

    public string $name = '';

    public ?int $context_window = null;

    public ?int $max_tokens = null;

    public function startCreate(): void
    {
        $this->reset('provider_id', 'model_id', 'name', 'context_window', 'max_tokens');
        $this->creating = true;
    }

    public function cancel(): void
    {
        $this->creating = false;
    }

    public function save(): void
    {
        $this->validate([
            'provider_id' => ['required', 'exists:model_providers,id'],
            'model_id' => ['required', 'string', 'max:128', Rule::unique('models', 'model_id')->where('provider_id', $this->provider_id)],
            'name' => ['nullable', 'string', 'max:128'],
            'context_window' => ['nullable', 'integer', 'min:1'],
            'max_tokens' => ['nullable', 'integer', 'min:1'],
        ]);

        Model::create([
            'provider_id' => $this->provider_id,
            'model_id' => $this->model_id,
            'name' => $this->name ?: $this->model_id,
            'context_window' => $this->context_window,
            'max_tokens' => $this->max_tokens,
        ]);

        $this->cancel();
        session()->flash('success', __('actions.save'));
    }

    public function delete(int $id): void
    {
        Model::whereKey($id)->delete();
    }

    public function render(): View
    {
        $user = Auth::user();

        $providers = ModelProvider::withCount('models')
            ->when(! $user->isAdmin(), fn ($q) => $q->where('created_by', $user->id))
            ->latest('id')
            ->get();

        $models = Model::with('provider')
            ->when(! $user->isAdmin(), fn ($q) => $q->whereHas('provider', fn ($q2) => $q2->where('created_by', $user->id)))
            ->latest('id')
            ->get()
            ->groupBy('provider_id');

        return view('livewire.models.index', [
            'providers' => $providers,
            'models' => $models,
        ]);
    }
}

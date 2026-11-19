<?php

namespace App\Livewire\Providers;

use App\Models\ModelProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public bool $creating = false;

    public string $name = '';
    public string $type = 'openai';
    public string $base_url = '';
    public string $api_key = '';
    public ?int $edit_id = null;

    public function startCreate(): void
    {
        $this->reset('name', 'type', 'base_url', 'api_key', 'edit_id');
        $this->creating = true;
    }

    public function cancel(): void
    {
        $this->creating = false;
        $this->startEdit(null);
    }

    public function startEdit(?int $id = null): void
    {
        $this->creating = $id !== null;
        $this->edit_id = $id;
        if ($id) {
            $p = ModelProvider::findOrFail($id);
            $this->name = $p->name;
            $this->type = $p->type;
            $this->base_url = $p->base_url;
            $this->api_key = $p->api_key ?? '';
        } else {
            $this->reset('name', 'type', 'base_url', 'api_key');
        }
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('model_providers', 'name')->ignore($this->edit_id)],
            'type' => ['required', Rule::in(['openai', 'anthropic', 'other'])],
            'base_url' => ['required', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:512'],
        ]);

        $data = [
            'name' => $this->name,
            'type' => $this->type,
            'base_url' => $this->base_url,
            'api_key' => $this->api_key ?: null,
            'created_by' => Auth::user()->id,
        ];

        if ($this->edit_id) {
            ModelProvider::whereKey($this->edit_id)->where('created_by', Auth::user()->id)->update($data);
        } else {
            ModelProvider::create($data);
        }

        $this->creating = false;
        $this->edit_id = null;
        session()->flash('success', __('actions.save'));
    }

    public function check(?int $id): void
    {
        $p = ModelProvider::findOrFail($id);
        $headers = $p->api_key ? ['Authorization' => 'Bearer ' . $p->api_key] : [];

        try {
            $res = Http::timeout(15)
                ->withHeaders($headers)
                ->get(rtrim($p->base_url, '/') . '/models');

            if ($res->successful()) {
                session()->flash('success', __('providers.check_ok'));
            } else {
                session()->flash('error', __('providers.check_fail') . " ({$res->status()})");
            }
        } catch (\Throwable $e) {
            session()->flash('error', __('providers.check_fail'));
        }
    }

    public function delete(int $id): void
    {
        ModelProvider::whereKey($id)->where('created_by', Auth::user()->id)->delete();
    }

    public function render()
    {
        $providers = ModelProvider::withCount('models')
            ->where('created_by', Auth::user()->id)
            ->latest('id')
            ->get();

        return view('livewire.providers.index', [
            'providers' => $providers,
        ]);
    }
}

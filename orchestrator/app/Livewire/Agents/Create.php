<?php

namespace App\Livewire\Agents;

use App\Enums\AgentRuntime;
use App\Models\Agent;
use App\Models\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Create extends Component
{
    public string $name = '';
    public string $description = '';
    public string $runtime = 'pi';
    public ?int $model_id = null;
    public bool $internet_enabled = false;
    public bool $auto_restart = true;
    public string $system_prompt = '';

    public function submit(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:64', 'alpha_dash',
                \Illuminate\Validation\Rule::unique(Agent::class, 'name')->where(fn ($q) => $q->whereNull('deleted_at'))],
            'description' => ['nullable', 'string', 'max:500'],
            'runtime' => ['required', 'in:pi,hermes'],
            'model_id' => ['required', 'exists:models,id'],
            'system_prompt' => ['nullable', 'string', 'max:20000'],
        ]);

        $user = Auth::user();

        try {
            $agent = Agent::create([
                'name' => $this->name,
                'description' => $this->description ?: null,
                'runtime' => AgentRuntime::fromString($this->runtime),
                'model_id' => $this->model_id,
                'system_prompt' => $this->system_prompt ?: null,
                'internet_enabled' => $this->internet_enabled,
                'auto_restart' => $this->auto_restart,
                'owner_id' => $user->id,
                'status' => \App\Enums\AgentStatus::Offline,
                'image' => config('taap.runtimes.' . $this->runtime . '.image'),
            ]);

            $agent->save();

            app(\App\Agents\AgentManager::class)->provision($agent);
            app(\App\Agents\AgentManager::class)->start($agent);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', __('agents.title') . ': ' . $e->getMessage());

            return;
        }

        $this->redirectRoute('agents.show', $agent->refresh(), navigate: true);
    }

    public function render()
    {
        $models = Model::with('provider')->get()->keyBy('id');

        return view('livewire.agents.create', [
            'models' => $models,
            'runtimes' => AgentRuntime::cases(),
            'canCreate' => Auth::user()->canCreateAgent(),
        ]);
    }
}

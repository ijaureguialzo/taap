<?php

namespace App\Livewire\Agents;

use App\Agents\AgentManager;
use App\Enums\AgentRuntime;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Model;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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

    /**
     * Modelos compatibles con el runtime elegido: los de proveedores cuyo
     * formato sea el que el runtime consume (spec model-management: los
     * incompatibles se excluyen de la seleccion).
     *
     * @return list<string> tipos de provider compatibles
     */
    protected function compatibleProviderTypes(): array
    {
        // Spec model-management (generalizada v1.1): el runtime declara en
        // el registro los formatos que consume. OpenCode consume AMBOS ->
        // todos los proveedores; `other` se trata como OpenAI (design v1.0).
        $formats = AgentRuntime::fromString($this->runtime)->modelFormats();

        $types = [];
        if (in_array('anthropic', $formats, true)) {
            $types[] = 'anthropic';
        }
        if (in_array('openai', $formats, true) || $formats === []) {
            $types = array_merge(['openai', 'other'], $types);
        }

        return $types;
    }

    /**
     * Al cambiar de runtime, el modelo elegido puede quedar incompatible:
     * se limpia (la validacion lo exigiria de nuevo).
     */
    public function updatedRuntime(): void
    {
        $model = Model::find($this->model_id);
        if ($model && ! in_array($model->provider?->type, $this->compatibleProviderTypes(), true)) {
            $this->model_id = null;
        }
    }

    public function submit(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:64', 'alpha_dash',
                Rule::unique(Agent::class, 'name')->where(fn ($q) => $q->whereNull('deleted_at'))],
            'description' => ['nullable', 'string', 'max:500'],
            'runtime' => ['required', Rule::in(AgentRuntime::supported())],
            'model_id' => ['required', 'exists:models,id', function (string $attribute, mixed $value, \Closure $fail) {
                $model = Model::with('provider')->find($value);
                $types = $this->compatibleProviderTypes();
                if (! $model || ! in_array($model->provider?->type, $types, true)) {
                    $fail(__('agents.model_incompatible_format'));
                }
            }],
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
                'status' => AgentStatus::Offline,
                'image' => config('taap.runtimes.'.$this->runtime.'.image'),
            ]);

            $agent->save();

            app(AgentManager::class)->provision($agent);
            app(AgentManager::class)->start($agent);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', __('agents.title').': '.$e->getMessage());

            return;
        }

        $this->redirectRoute('agents.show', $agent->refresh(), navigate: true);
    }

    public function render(): View
    {
        $models = Model::with('provider')
            ->whereHas('provider', fn ($q) => $q->whereIn('type', $this->compatibleProviderTypes()))
            ->get()
            ->keyBy('id');

        return view('livewire.agents.create', [
            'models' => $models,
            'runtimes' => AgentRuntime::supported(),
            'canCreate' => Auth::user()->canCreateAgent(),
        ]);
    }
}

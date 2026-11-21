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

    /**
     * Modelo descartado al cambiar de runtime (id): el aviso lo cita con
     * su proveedor; si el runtime vuelve a ser compatible se restaura.
     */
    public ?int $discarded_model_id = null;

    public bool $internet_enabled = false;

    public bool $auto_restart = true;

    public string $system_prompt = '';

    /**
     * Tipos de proveedor compatibles con el runtime elegido (ios-
     * model-runtime-compatibility 1.2, design D1): delega en el enum, la
     * unica fuente de verdad, que tambien usan los filtros y validaciones
     * de la API v1.
     *
     * @return list<string>
     */
    protected function compatibleProviderTypes(): array
    {
        return AgentRuntime::fromString($this->runtime)->compatibleProviderTypes();
    }

    /**
     * Al cambiar de runtime, el modelo elegido puede quedar incompatible:
     * se descarta (model_id -> null) con un aviso visible (reportado: el
     * limpiado era en silencio y el submit fallaba con un error generico
     * que no llevaba a ningun sitio). Si hay un modelo descartado y el
     * runtime nuevo vuelve a ser compatible, se restaura sin obligar al
     * usuario a re-seleccionarlo.
     */
    public function updatedRuntime(): void
    {
        $runtime = AgentRuntime::fromString($this->runtime);

        if ($this->model_id !== null) {
            $model = Model::with('provider')->find($this->model_id);
            if ($model && $model->isCompatibleWith($runtime)) {
                // Sigue compatible: un descarte previo queda resuelto.
                $this->discarded_model_id = null;
            } else {
                $this->discarded_model_id = $this->model_id;
                $this->model_id = null;
            }
        }

        if ($this->discarded_model_id !== null) {
            $discarded = Model::with('provider')->find($this->discarded_model_id);
            if ($discarded && $discarded->isCompatibleWith($runtime)) {
                $this->model_id = $this->discarded_model_id;
                $this->discarded_model_id = null;
            }
        }
    }

    /**
     * Al elegir (de nuevo) un modelo, el aviso de descarte deja de aplicar.
     */
    public function updatedModelId(): void
    {
        if ($this->model_id !== null) {
            $this->discarded_model_id = null;
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
                if (! $model?->isCompatibleWith(AgentRuntime::fromString($this->runtime))) {
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
        $discarded = $this->discarded_model_id !== null
            ? Model::with('provider')->find($this->discarded_model_id)
            : null;

        return view('livewire.agents.create', [
            'models' => $models,
            'runtimes' => AgentRuntime::supported(),
            'canCreate' => Auth::user()->canCreateAgent(),
            'discarded_model' => $discarded,
        ]);
    }
}

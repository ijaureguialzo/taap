<?php

namespace App\Livewire\Agents;

use App\Agents\AgentManager;
use App\Agents\Timeline;
use App\Enums\AgentStatus;
use App\Jobs\BridgeApplyConfig;
use App\Models\Agent;
use App\Models\Model;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Detail extends Component
{
    public Agent $agent;

    public string $system_prompt = '';

    public bool $internet_enabled = false;

    public bool $auto_restart = false;

    public ?int $model_id = null;

    /** Estado en vivo (canal Reverb) para el badge del header. */
    public ?string $liveStatus = null;

    public function mount(Agent $agent): void
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $agent->owner_id !== $user->id) {
            abort(404);
        }

        $this->agent = $agent;
        $this->system_prompt = (string) $agent->system_prompt;
        $this->internet_enabled = $agent->internet_enabled;
        $this->auto_restart = $agent->auto_restart;
        $this->model_id = $agent->model_id;
        $this->liveStatus = $agent->status->value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function agentEvent(array $payload): void
    {
        if (($payload['agent_id'] ?? null) != $this->agent->id) {
            return;
        }
        if ($payload['type'] === 'status.change') {
            $this->liveStatus = $payload['payload']['status'] ?? $this->liveStatus;
        }
    }

    public function lifecycle(string $action): void
    {
        $manager = app(AgentManager::class);

        try {
            $this->agent = match ($action) {
                'start' => $manager->start($this->agent),
                'stop' => $manager->stop($this->agent),
                'restart' => $manager->restart($this->agent),
                default => $this->agent,
            };
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', $e->getMessage());
        }

        $this->agent->refresh();
        $this->liveStatus = $this->agent->status->value;
    }

    public function saveConfig(): void
    {
        $this->validate([
            'system_prompt' => ['nullable', 'string', 'max:20000'],
            'model_id' => ['nullable', 'exists:models,id'],
        ]);

        $configChanged = $this->agent->system_prompt !== ($this->system_prompt ?: null)
            || $this->agent->model_id !== ($this->model_id ?? null);

        $this->agent->system_prompt = $this->system_prompt ?: null;
        $this->agent->auto_restart = $this->auto_restart;
        if (($this->model_id ?? 0) > 0) {
            $this->agent->model_id = $this->model_id;
        }
        $this->agent->save();

        // Model/prompt changed: the agent applies it without losing the
        // session (Pi/Hermes restart + resume; Claude on the next turn).
        if ($configChanged) {
            BridgeApplyConfig::dispatch($this->agent->id);
        }

        // Si cambia el internet: hay que recrear el contenedor (red de egress).
        if ($this->internet_enabled !== $this->agent->internet_enabled) {
            $this->agent->internet_enabled = $this->internet_enabled;
            $this->agent->save();
            try {
                $this->agent = app(AgentManager::class)->provision($this->agent);
            } catch (\Throwable $e) {
                session()->flash('error', $e->getMessage());
            }
        }

        session()->flash('success', __('actions.save'));
    }

    public function delete(): void
    {
        $manager = app(AgentManager::class);

        try {
            $manager->terminate($this->agent);
            $this->agent->delete(); // soft delete (la fila)
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->redirectRoute('agents.index', navigate: true);
    }

    public function render(): View
    {
        $user = Auth::user();
        $canManage = $user->isAdmin() || $user->id === $this->agent->owner_id;

        $status = $this->agent->status;
        if ($this->liveStatus) {
            try {
                $status = AgentStatus::fromString($this->liveStatus);
            } catch (\Throwable) {
                // valor desconocido: mantener el de BD
            }
        }

        // Modelos compatibles con el runtime del agente (spec
        // model-management: los de formato incompatible quedan fuera).
        $types = [];
        $formats = $this->agent->runtime->modelFormats();
        if (in_array('anthropic', $formats, true)) {
            $types[] = 'anthropic';
        }
        if (in_array('openai', $formats, true) || $formats === []) {
            $types = array_merge(['openai', 'other'], $types);
        }
        $models = Model::with('provider')
            ->whereHas('provider', fn ($q) => $q->whereIn('type', $types))
            ->get()
            ->keyBy('id');
        // El modelo actual se mantiene en la lista aunque haya cambiado de
        // formato (que se vea lo que usa el agente).
        if ($this->agent->model && ! $models->has($this->agent->model->id)) {
            $this->agent->model->loadMissing('provider');
            $models[$this->agent->model->id] = $this->agent->model;
        }

        // 7.2: lineas de tiempo (ventanas de trabajo, design D6).
        $windows = app(Timeline::class)->windows($this->agent->id);

        // Capacidades declaradas en el registro, por grupo (design D12)
        $capabilities = ['format' => [], 'session' => null, 'apply' => null];
        foreach ($this->agent->runtime->capabilities() as $cap) {
            $group = match ($cap) {
                'openai', 'anthropic' => 'format',
                'native', 'turn' => 'session',
                'restart', 'next-turn' => 'apply',
                default => null,
            };
            if ($group === null) {
                continue;
            }
            if ($group === 'format') {
                $capabilities['format'][] = $cap; // runtimes duales declaran los dos
            } else {
                $capabilities[$group] = $cap;
            }
        }
        $capabilities['format'] = $capabilities['format'] === [] ? null : $capabilities['format'];

        return view('livewire.agents.detail', [
            'status' => $status,
            'canManage' => $canManage,
            'models' => $models,
            'windows' => $windows,
            'capabilities' => $capabilities,
        ]);
    }
}

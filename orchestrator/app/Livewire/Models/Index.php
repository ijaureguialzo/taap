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

    public ?int $edit_id = null;

    /**
     * Proveedor del modelo en edición (D5): inmodificable — se envia oculto
     * con el valor original; mover el modelo a otro proveedor = borrar y crear.
     */
    public ?int $provider_id = null;

    public string $model_id = '';

    public string $name = '';

    public ?int $context_window = null;

    public ?int $max_tokens = null;

    /**
     * Capacidades marcadas en la edicion (tools/vision/streaming).
     *
     * @var list<string>
     */
    public array $capabilities = [];

    public function startCreate(): void
    {
        $this->reset('provider_id', 'model_id', 'name', 'context_window', 'max_tokens', 'capabilities', 'edit_id');
        $this->creating = true;
    }

    public function cancel(): void
    {
        $this->startEdit(null);
    }

    public function startEdit(?int $id = null): void
    {
        $this->creating = $id !== null;
        $this->edit_id = $id;
        if ($id) {
            $m = Model::with('provider')->findOrFail($id);
            $this->provider_id = $m->provider_id;
            $this->model_id = $m->model_id;
            $this->name = $m->name;
            $this->context_window = $m->context_window;
            $this->max_tokens = $m->max_tokens;
            // Precarga desde el cast array (D5); el JSON del endpoint/BD es
            // opaco: solo se conservan entradas de string (phpstan + runtime).
            $caps = [];
            foreach ($m->capabilities ?? [] as $cap) {
                if (is_string($cap)) {
                    $caps[] = $cap;
                }
            }
            $this->capabilities = $caps;
        } else {
            $this->reset('provider_id', 'model_id', 'name', 'context_window', 'max_tokens', 'capabilities');
        }
    }

    public function save(): void
    {
        if (! $this->adminOnly()) {
            return;
        }

        $this->validate([
            'provider_id' => ['required', 'exists:model_providers,id'],
            // unique (provider_id, model_id); en edicion se excluye el propio
            // registro (D5: el provider es inamovible).
            'model_id' => ['required', 'string', 'max:128',
                Rule::unique('models', 'model_id')
                    ->where('provider_id', $this->provider_id)
                    ->ignore($this->edit_id)],
            'name' => ['nullable', 'string', 'max:128'],
            'context_window' => ['nullable', 'integer', 'min:1'],
            'max_tokens' => ['nullable', 'integer', 'min:1'],
            'capabilities' => ['array'],
        ]);

        $data = [
            'provider_id' => $this->provider_id,
            'model_id' => $this->model_id,
            'name' => $this->name ?: $this->model_id,
            'context_window' => $this->context_window,
            'max_tokens' => $this->max_tokens,
            'capabilities' => $this->capabilities,
        ];

        if ($this->edit_id) {
            Model::whereKey($this->edit_id)->update($data);
        } else {
            Model::create($data);
        }

        $this->cancel();
        session()->flash('success', __('actions.save'));
    }

    public function delete(int $id): void
    {
        if (! $this->adminOnly()) {
            return;
        }

        Model::whereKey($id)->delete();
    }

    /**
     * Barrera de acceso (D1): la UI oculta los botones a los no-admins,
     * pero el servidor es la barrera real — una escritura de un
     * no-administrador devuelve un error claro, sin mutacion parcial.
     */
    protected function adminOnly(): bool
    {
        if (Auth::user()?->isAdmin()) {
            return true;
        }

        session()->flash('error', __('models.admin_only'));

        return false;
    }

    public function render(): View
    {
        // Catálogo compartido (D1): todo usuario autenticado ve todos los
        // proveedores y modelos; el scope por created_by se retira.
        $providers = ModelProvider::withCount('models')
            ->latest('id')
            ->get();

        $models = Model::with('provider')->withCount('agents')
            ->latest('id')
            ->get();

        // Impacto del borrado (D6): n.º de agentes que usan cada modelo.
        $confirms = $models->mapWithKeys(
            fn (Model $m): array => [
                $m->id => $m->agents_count > 0
                    ? __('models.delete_impact', [
                        'name' => $m->name,
                        'agents' => $m->agents_count,
                    ])
                    : __('models.delete').'?',
            ],
        );

        return view('livewire.models.index', [
            'providers' => $providers,
            'models' => $models->groupBy('provider_id'),
            'confirms' => $confirms,
            'isAdmin' => Auth::user()->isAdmin(),
        ]);
    }
}

<?php

namespace App\Livewire\Providers;

use App\Models\Model;
use App\Models\ModelProvider;
use Illuminate\Contracts\View\View;
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

    /** El proveedor ya tiene clave guardada (el formulario la enmascara). */
    public bool $key_set = false;

    public bool $delete_key = false;

    public function startCreate(): void
    {
        $this->reset('name', 'type', 'base_url', 'api_key', 'edit_id');
        $this->key_set = false;
        $this->delete_key = false;
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
            // La clave NO se envia en claro al navegador: campo vacio =
            // conservar la existente; texto nuevo = sustituirla.
            $this->key_set = filled($p->api_key);
            $this->api_key = '';
        } else {
            $this->reset('name', 'type', 'base_url', 'api_key');
            $this->key_set = false;
        }
        $this->delete_key = false;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('model_providers', 'name')->ignore($this->edit_id)],
            'type' => ['required', Rule::in(['openai', 'anthropic', 'other'])],
            'base_url' => ['required', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:512'],
        ]);

        if ($this->delete_key && $this->key_set) {
            $key = null;
        } elseif ($this->api_key !== '') {
            $key = $this->api_key;
        } elseif ($this->key_set) {
            // Campo vacio con clave existente: se conserva (no se toca).
            $key = null;
        } else {
            $key = null;
        }

        $data = [
            'name' => $this->name,
            'type' => $this->type,
            'base_url' => $this->base_url,
            'created_by' => Auth::user()->id,
        ];

        if ($this->edit_id) {
            if ($key !== null || $this->delete_key) {
                $data['api_key'] = $key;
            }
            ModelProvider::whereKey($this->edit_id)->where('created_by', Auth::user()->id)->update($data);
            $id = $this->edit_id;
        } else {
            $data['api_key'] = $key;
            $id = ModelProvider::create($data)->id;
        }

        $provider = ModelProvider::query()->findOrFail($id);

        // Comprobacion al guardar (D3): el provider queda persistido en
        // cualquier caso; el resultado (salud) y, para openai/other, el
        // descubrimiento de modelos (D4) dependen de la respuesta.
        [$ok, $models, $code] = $this->probe($provider, $provider->api_key);
        $provider->forceFill([
            'status' => $ok ? ModelProvider::STATUS_OK : ModelProvider::STATUS_ERROR,
            'last_checked_at' => now(),
        ])->save();

        if ($ok) {
            if (in_array($provider->type, ['openai', 'other'], true)) {
                $created = $this->discoverModels($provider, $models ?? []);
                session()->flash('success', $created > 0
                    ? __('providers.discovered', ['count' => $created])
                    : __('providers.saved'));
            } else {
                session()->flash('success', __('providers.saved'));
            }
        } else {
            session()->flash('error', $code
                ? __('providers.saved_unhealthy').' ('.$code.')'
                : __('providers.saved_unhealthy'));
        }

        $this->creating = false;
        $this->edit_id = null;
    }

    public function check(?int $id): void
    {
        $p = ModelProvider::findOrFail($id);

        [$ok, , $code] = $this->probe($p, $p->api_key);

        $p->forceFill([
            'status' => $ok ? ModelProvider::STATUS_OK : ModelProvider::STATUS_ERROR,
            'last_checked_at' => now(),
        ])->save();

        if ($ok) {
            session()->flash('success', __('providers.check_ok'));
        } else {
            session()->flash('error', $code
                ? __('providers.check_fail').' ('.$code.')'
                : __('providers.check_fail'));
        }
    }

    public function delete(int $id): void
    {
        ModelProvider::whereKey($id)->where('created_by', Auth::user()->id)->delete();
    }

    /**
     * Comprobar el endpoint de modelos (design D3): el path depende del
     * formato — para openai/other `{base_url}/models` (el base_url ya
     * incluye su /v1) y para anthropic `{base_url}/v1/models` (su base_url
     * no lo lleva): la misma convencion que usa
     * ModelProxyController::upstreamPath.
     *
     * @return array{0: bool, 1: ?array<mixed>, 2: ?int}
     *         [exito, data[] del endpoint o null, codigo HTTP (null si
     *         fallo de conexion/timeout)]
     */
    protected function probe(ModelProvider $provider, ?string $apiKey): array
    {
        $headers = $apiKey ? ['Authorization' => 'Bearer '.$apiKey] : [];
        $url = rtrim($provider->base_url, '/').($provider->type === 'anthropic' ? '/v1/models' : '/models');

        try {
            $res = Http::timeout(15)->withHeaders($headers)->get($url);
        } catch (\Throwable) {
            return [false, null, null]; // timeout o error de conexion
        }

        if (! $res->successful()) {
            return [false, null, $res->status()];
        }

        $data = $res->json('data');

        return [true, is_array($data) ? $data : [], null];
    }

    /**
     * Descubrimiento (design D4): merge no destructivo — solo crea los
     * (provider_id, model_id) ausentes; las filas curadas a mano no se
     * tocan (el podado es accion manual en la pagina de modelos).
     *
     * @param  array<mixed>  $entries  `data[]` del endpoint
     * @return int numero de modelos creados
     */
    protected function discoverModels(ModelProvider $provider, array $entries): int
    {
        $known = Model::where('provider_id', $provider->id)->pluck('model_id')->all();
        $created = 0;

        foreach ($entries as $entry) {
            $modelId = is_array($entry) ? ($entry['id'] ?? null) : null;
            if (! is_string($modelId) || $modelId === '' || in_array($modelId, $known, true)) {
                continue;
            }
            $known[] = $modelId; // dedupe dentro de la propia respuesta
            Model::firstOrCreate(
                ['provider_id' => $provider->id, 'model_id' => $modelId],
                ['name' => $modelId],
            );
            $created++;
        }

        return $created;
    }

    public function render(): View
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

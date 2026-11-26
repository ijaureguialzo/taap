<?php

namespace App\Livewire\Providers;

use App\Models\Agent;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Services\AuditLogger;
use App\Support\UpstreamIdentity;
use Illuminate\Support\Facades\DB;
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
        if (! $this->adminOnly()) {
            return;
        }

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
        ];

        $id = DB::transaction(function () use ($data, $key) {
            $auditor = app(AuditLogger::class);
            $current = Auth::user();

            if ($this->edit_id) {
                if ($key !== null || $this->delete_key) {
                    $data['api_key'] = $key;
                }
                // Catalogo compartido (D1): un admin puede editar CUALQUIER
                // provider; la barrera de acceso es la guarda de rol, no el creador.
                // created_by NO se toca: es campo de auditoria (quien lo creo).
                ModelProvider::whereKey($this->edit_id)->update($data);
                $auditor->log($current, 'provider.updated', 'provider', (string) $this->edit_id, ['name' => $data['name']]);

                return $this->edit_id;
            }

            $data['api_key'] = $key;
            $data['created_by'] = Auth::user()->id;
            $created = ModelProvider::create($data);
            $auditor->log($current, 'provider.created', 'provider', (string) $created->id, ['name' => $data['name']]);

            return $created->id;
        });

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
        if (! $this->adminOnly()) {
            return;
        }

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
        if (! $this->adminOnly()) {
            return;
        }

        DB::transaction(function () use ($id) {
            // Barrera: guarda de rol (D1); created_by queda como auditoria.
            $provider = ModelProvider::whereKey($id)->first();
            ModelProvider::whereKey($id)->delete();

            if ($provider) {
                app(AuditLogger::class)->log(Auth::user(), 'provider.deleted', 'provider', (string) $id, ['name' => $provider->name]);
            }
        });
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

        session()->flash('error', __('providers.admin_only'));

        return false;
    }

    /**
     * Comprobar el endpoint de modelos (design D3): el path depende del
     * formato — para openai/other `{base_url}/models` (el base_url ya
     * incluye su /v1) y para anthropic `{base_url}/v1/models` (su base_url
     * no lo lleva): la misma convencion que usa
     * ModelProxyController::upstreamPath.
     *
     * @return array{0: bool, 1: ?array<mixed>, 2: ?int}
     *                                                   [exito, data[] del endpoint o null, codigo HTTP (null si
     *                                                   fallo de conexion/timeout)]
     */
    protected function probe(ModelProvider $provider, ?string $apiKey): array
    {
        $headers = $apiKey ? ['Authorization' => 'Bearer '.$apiKey] : [];
        // Misma identidad upstream que el proxy (agent-runtime-reliability
        // D1): los gateways que validan Host (llama.cpp, uvicorn...) solo
        // lo aceptan por 127.0.0.1; sin el mapeo el probe cae en 403 aunque
        // el gateway sirva el trafico de agentes.
        if ($host = UpstreamIdentity::hostFor($provider->base_url)) {
            $headers['Host'] = $host;
        }

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
        // Catalogo compartido (D1): todo usuario autenticado ve TODOS los
        // proveedores; el scope por created_by se retira.
        $providers = ModelProvider::withCount('models')
            ->latest('id')
            ->get();

        // Impacto del borrado (D6): n.º de modelos y de agentes que usan
        // los modelos de cada proveedor, para el texto de confirmacion.
        $agentCounts = $this->agentCountsByProvider(
            $providers->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );

        $confirms = $providers->mapWithKeys(
            fn (ModelProvider $p): array => [
                $p->id => $this->providerConfirmText($p, (int) ($agentCounts[(int) $p->id] ?? 0)),
            ],
        );

        return view('livewire.providers.index', [
            'providers' => $providers,
            'confirms' => $confirms,
            'isAdmin' => Auth::user()->isAdmin(),
        ]);
    }

    /**
     * Agentes que usan los modelos de cada proveedor (D6: el borrado de un
     * proveedor deja a esos agentes sin modelo: la cadena es models
     * (cascadeOnDelete) -> agents.model_id (nullOnDelete) y el proxy lo
     * hace visible con un 422).
     *
     * @param  array<int>  $providerIds
     * @return array<int, int> providerId => n.º de agentes que usan sus modelos
     */
    protected function agentCountsByProvider(array $providerIds): array
    {
        $counts = [];
        foreach ($providerIds as $pid) {
            $counts[$pid] = 0;
        }

        Agent::query()
            ->whereNotNull('model_id')
            ->whereHas('model', fn ($q) => $q->whereIn('provider_id', $providerIds))
            // select explicit: el shortcut `model:provider_id` deja model=null
            // en esta version de Laravel (la clave no se anade sola).
            ->with(['model' => fn ($q) => $q->select('id', 'provider_id')])
            ->get()
            ->each(function (Agent $a) use (&$counts): void {
                $pid = $a->model?->provider_id;
                if ($pid !== null) {
                    $counts[(int) $pid] = ($counts[(int) $pid] ?? 0) + 1;
                }
            });

        return $counts;
    }

    protected function providerConfirmText(ModelProvider $p, int $agentCount): string
    {
        if ($p->models_count === 0 && $agentCount === 0) {
            return __('providers.delete').'?';
        }

        return __('providers.delete_impact', [
            'name' => $p->name,
            'models' => $p->models_count,
            'agents' => $agentCount,
        ]);
    }
}

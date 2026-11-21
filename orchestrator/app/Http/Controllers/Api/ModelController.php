<?php

namespace App\Http\Controllers\Api;

use App\Enums\AgentRuntime;
use App\Http\Controllers\Controller;
use App\Models\Model;
use App\Models\ModelProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModelController extends Controller
{
    /* ---------- providers ---------- */

    public function providers(Request $request): JsonResponse
    {
        return response()->json([
            'providers' => ModelProvider::withCount('models')->orderBy('name')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'base_url' => $p->base_url,
                'has_key' => (bool) $p->api_key,
                'model_count' => $p->models_count,
            ]),
        ]);
    }

    public function storeProvider(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'unique:model_providers,name'],
            'type' => ['required', 'in:openai,anthropic,other'],
            'base_url' => ['required', 'url'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $provider = ModelProvider::create($data + ['created_by' => $request->user()->id]);

        return response()->json(['provider' => $this->providerPayload($provider)], 201);
    }

    public function updateProvider(Request $request, ModelProvider $provider): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'unique:model_providers,name,'.$provider->id],
            'type' => ['required', 'in:openai,anthropic,other'],
            'base_url' => ['required', 'url'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        // Semantica de la clave: omitida = no se toca; null = borrar;
        // string = sustituirla (el payload nunca la devuelve).
        if (! $request->has('api_key')) {
            unset($data['api_key']);
        }

        $provider->update($data);

        return response()->json(['provider' => $this->providerPayload($provider->refresh())]);
    }

    public function deleteProvider(Request $request, ModelProvider $provider): JsonResponse
    {
        $this->requireAdmin($request);
        $provider->delete();

        return response()->json(['ok' => true]);
    }

    /* ---------- modelos ---------- */

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Filtro opcional por runtime (ios-model-runtime-compatibility,
            // design D3): valor desconocido → 422 (protege typos del cliente,
            // no se ignora). Sin parametro → todos (retrocompatible: un
            // cliente viejo no lo envia).
            'runtime' => ['nullable', Rule::in(AgentRuntime::supported())],
        ]);

        $query = Model::with('provider:id,name,type,status')->orderBy('provider_id')->orderBy('name');

        if (! empty($data['runtime'])) {
            // Misma semantica que el filtro de la web: solo modelos de
            // proveedores cuyo formato lo consume el runtime.
            $types = AgentRuntime::fromString($data['runtime'])->compatibleProviderTypes();
            $query->whereHas('provider', fn ($q) => $q->whereIn('type', $types));
        }

        $models = $query->get();

        return response()->json([
            'models' => $models->map(fn (Model $m) => [
                'id' => $m->id,
                'provider_id' => $m->provider_id,
                'provider' => $m->provider?->name,
                // Aditivo (design D3): el tipo de formato y el estado de
                // salud del proveedor, para que los clientes filtren/marquen
                // sin logica local. `provider` sigue siendo el nombre en
                // string (el DTO de iOS exige string).
                'provider_type' => $m->provider?->type,
                'provider_status' => $m->provider?->status,
                'model_id' => $m->model_id,
                'name' => $m->name,
                'context_window' => $m->context_window,
                'max_tokens' => $m->max_tokens,
                'capabilities' => $m->capabilities,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'provider_id' => ['required', 'integer', 'exists:model_providers,id'],
            'model_id' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:128'],
            'context_window' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'capabilities' => ['array'],
            'capabilities.*' => ['string'],
        ]);

        Model::firstOrCreate(
            ['provider_id' => $data['provider_id'], 'model_id' => $data['model_id']],
            $data
        );

        return response()->json(['ok' => true], 201);
    }

    public function delete(Request $request, Model $model): JsonResponse
    {
        $this->requireAdmin($request);
        $model->delete();

        return response()->json(['ok' => true]);
    }

    protected function requireAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function providerPayload(ModelProvider $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'type' => $p->type,
            'base_url' => $p->base_url,
            'has_key' => (bool) $p->api_key,
        ];
    }
}

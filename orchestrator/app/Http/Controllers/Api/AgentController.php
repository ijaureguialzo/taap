<?php

namespace App\Http\Controllers\Api;

use App\Agents\AgentManager;
use App\Agents\QuotaExceededException;
use App\Agents\RuntimeAdapters;
use App\Enums\AgentRuntime;
use App\Jobs\BridgeApplyConfig;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AgentController extends Controller
{
    public function __construct(protected AgentManager $manager)
    {
    }

    public function index(Request $request)
    {
        $agents = Agent::query()
            ->visibleTo($request->user())
            ->with(['model:id,provider_id', 'model.provider:id,name', 'token:id,token_prefix'])
            ->latest('id')
            ->get();

        return response()->json([
            'agents' => $agents->map(fn (Agent $a) => $this->payload($a)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', \Illuminate\Validation\Rule::unique('agents', 'name')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
            'runtime' => ['required', 'in:' . implode(',', AgentRuntime::supported())],
            'model_id' => ['nullable', 'integer', 'exists:models,id'],
            'system_prompt' => ['nullable', 'string', 'max:20000'],
            'internet_enabled' => ['boolean'],
            'working_dir' => ['nullable', 'string', 'max:255'],
            'mcp_server_ids' => ['array'],
            'mcp_server_ids.*' => ['integer', 'exists:mcp_servers,id'],
        ]);

        try {
            $this->manager->assertQuota($request->user());
        } catch (QuotaExceededException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $agent = new Agent([
            'owner_id' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'runtime' => $data['runtime'],
            'model_id' => $data['model_id'] ?? null,
            'system_prompt' => $data['system_prompt'] ?? null,
            'internet_enabled' => (bool) ($data['internet_enabled'] ?? false),
            'working_dir' => $data['working_dir'] ?? null,
            'image' => config('taap.runtimes.' . $data['runtime'] . '.image'),
            'status' => \App\Enums\AgentStatus::Offline,
        ]);
        $agent->save();

        if (! empty($data['mcp_server_ids'])) {
            $agent->mcpServers()->sync(
                collect($data['mcp_server_ids'])->mapWithKeys(fn ($id) => [$id => []])->all()
            );
        }

        $agent = $this->manager->provision($agent);
        $this->manager->start($agent);

        $agent->load('model', 'model.provider', 'token');

        return response()->json(['agent' => $this->payload($agent)], 201);
    }

    public function show(Request $request, Agent $agent)
    {
        $this->authorize($request, $agent);
        $agent->load(['model', 'model.provider', 'token', 'mcpServers']);

        return response()->json([
            'agent' => $this->payload($agent, full: true),
        ]);
    }

    public function update(Request $request, Agent $agent)
    {
        $this->authorize($request, $agent);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'unique:agents,name,' . $agent->id],
            'description' => ['nullable', 'string', 'max:500'],
            'model_id' => ['nullable', 'integer', 'exists:models,id'],
            'system_prompt' => ['nullable', 'string', 'max:20000'],
            'internet_enabled' => ['boolean'],
            'auto_restart' => ['boolean'],
            'working_dir' => ['nullable', 'string', 'max:255'],
            'mcp_server_ids' => ['array'],
            'mcp_server_ids.*' => ['integer', 'exists:mcp_servers,id'],
        ]);

        $configChanged = false;

        if (array_key_exists('internet_enabled', $data) && $data['internet_enabled'] !== $agent->internet_enabled) {
            // La red de egress solo se cambia al recrear el contenedor
            $agent->internet_enabled = $data['internet_enabled'];
            $agent->auto_restart = $data['auto_restart'] ?? $agent->auto_restart;
            $agent = $this->manager->provision($agent);
        } else {
            $agent->fill([
                'name' => $data['name'] ?? $agent->name,
                'description' => $data['description'] ?? $agent->description,
                'model_id' => $data['model_id'] ?? $agent->model_id,
                'system_prompt' => $data['system_prompt'] ?? $agent->system_prompt,
                'auto_restart' => $data['auto_restart'] ?? $agent->auto_restart,
                'working_dir' => $data['working_dir'] ?? $agent->working_dir,
            ]);
            $agent->save();

            if (array_key_exists('mcp_server_ids', $data)) {
                $agent->mcpServers()->sync(
                    collect($data['mcp_server_ids'])->mapWithKeys(fn ($id) => [$id => []])->all()
                );
            }

            $configChanged = true;
        }

        // Re-renderiza la config nativa y la aplica sin perder la sesion
        app(\App\Agents\ConfigRenderer::class)->render($agent);
        app(\App\Agents\EgressSync::class)->sync();
        BridgeApplyConfig::dispatch('taap-bridge', $agent->id);

        $agent->load('model', 'model.provider', 'token');

        return response()->json(['agent' => $this->payload($agent)]);
    }

    public function destroy(Request $request, Agent $agent)
    {
        $this->authorize($request, $agent);

        $this->manager->terminate($agent);
        $agent->delete();

        return response()->json(['ok' => true]);
    }

    /* ------- acciones de ciclo de vida ------- */

    public function start(Request $request, Agent $agent)
    {
        $this->authorize($request, $agent);

        if (! $request->user()->isAdmin() && ! $request->user()->canCreateAgent()) {
            // La cuota cuenta agentes "en ejecucion": al arrancar uno parado
            // no se crea uno nuevo, pero lo dejamos si hay hueco
        }

        $this->manager->start($agent);

        return response()->json(['agent' => $this->payload($agent->refresh())]);
    }

    public function stop(Request $request, Agent $agent)
    {
        $this->authorize($request, $agent);
        $this->manager->stop($agent);

        return response()->json(['agent' => $this->payload($agent->refresh())]);
    }

    public function restart(Request $request, Agent $agent)
    {
        $this->authorize($request, $agent);
        $this->manager->restart($agent);

        return response()->json(['agent' => $this->payload($agent->refresh())]);
    }

    public function status(Request $request, Agent $agent)
    {
        $this->authorize($request, $agent);
        $agent->refresh();

        return response()->json([
            'agent' => $this->payload($agent),
        ]);
    }

    /* ------- helpers ------- */

    protected function authorize(Request $request, Agent $agent): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $agent->owner_id !== $user->id) {
            abort(404); // no existe y no es tuyo
        }
    }

    protected function payload(Agent $agent, bool $full = false): array
    {
        $data = [
            'id' => $agent->id,
            'name' => $agent->name,
            'description' => $agent->description,
            'runtime' => $agent->runtime->value,
            'status' => $agent->status->value,
            'model' => $agent->model ? [
                'id' => $agent->model->id,
                'name' => $agent->model->name,
                'provider' => $agent->model->provider?->name,
            ] : null,
            'internet_enabled' => $agent->internet_enabled,
            'auto_restart' => $agent->auto_restart,
            'system_prompt' => $full ? $agent->system_prompt : null,
            'working_dir' => $agent->working_dir,
            'token_prefix' => $agent->token?->token_prefix,
            'owner' => [
                'id' => $agent->owner_id,
                'name' => $agent->owner?->name,
            ],
            'last_active_at' => $agent->last_active_at?->toIso8601String(),
            'created_at' => $agent->created_at?->toIso8601String(),
            'mcp_servers' => $full
                ? $agent->mcpServers->filter()->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'type' => $s->type,
                ])->values()->all()
                : null,
        ];

        return $data;
    }
}

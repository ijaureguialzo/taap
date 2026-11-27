<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentTemplate;
use App\Models\Model;
use App\Models\McpServer;
use App\Support\TemplateValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function __construct(protected TemplateValidator $validator) {}

    /**
     * Listado: el owner solo ve las suyas; el admin con `?all=true` ve las
     * de cualquiera (oversight, no escrituras compartidas en esta fase).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = (bool) $request->query('all');

        $query = AgentTemplate::with('model:id,name,model_id')->orderBy('name');

        if (! ($all && $user->isAdmin())) {
            $query->where('owner_id', $user->id);
        }

        return response()->json([
            'templates' => $query->get()->map(fn (AgentTemplate $t) => $this->payload($t)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'runtime' => ['required', 'string'],
            'model_id' => ['nullable', 'integer', 'exists:models,id'],
            'mcp_server_ids' => ['nullable', 'array'],
            'mcp_server_ids.*' => ['integer'],
            'internet' => ['boolean'],
            'cpu' => ['nullable', 'numeric', 'min:0', 'max:32'],
            'memory' => ['nullable', 'string', 'max:32'],
        ]);

        $validated = $this->validator->validate(
            $data['name'],
            $data['runtime'],
            $data['model_id'] ?? null,
            $data['mcp_server_ids'] ?? null,
            $data['internet'] ?? true,
            isset($data['cpu']) ? (float) $data['cpu'] : null,
            $data['memory'] ?? null,
            $user->id,
        );

        $template = AgentTemplate::create(array_merge($validated, ['owner_id' => $user->id]));

        return response()->json(['template' => $this->payload($template->fresh('model'))], 201);
    }

    public function update(Request $request, AgentTemplate $template): JsonResponse
    {
        $user = $request->user();

        if ($template->owner_id !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'runtime' => ['required', 'string'],
            'model_id' => ['nullable', 'integer', 'exists:models,id'],
            'mcp_server_ids' => ['nullable', 'array'],
            'mcp_server_ids.*' => ['integer'],
            'internet' => ['boolean'],
            'cpu' => ['nullable', 'numeric', 'min:0', 'max:32'],
            'memory' => ['nullable', 'string', 'max:32'],
        ]);

        $validated = $this->validator->validate(
            $data['name'],
            $data['runtime'],
            $data['model_id'] ?? null,
            $data['mcp_server_ids'] ?? null,
            $data['internet'] ?? true,
            isset($data['cpu']) ? (float) $data['cpu'] : null,
            $data['memory'] ?? null,
            $user->id,
            $template->id,
        );

        $template->update($validated);

        return response()->json(['template' => $this->payload($template->fresh('model'))]);
    }

    public function destroy(Request $request, AgentTemplate $template): JsonResponse
    {
        $user = $request->user();

        if ($template->owner_id !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }

        $template->delete();

        return response()->json(['ok' => true]);
    }

    protected function payload(AgentTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'runtime' => $t->runtime,
            'model_id' => $t->model_id,
            'model' => $t->model ? ['id' => $t->model->id, 'name' => $t->model->name, 'model_id' => $t->model->model_id] : null,
            'mcp_server_ids' => $t->mcp_server_ids,
            'internet' => $t->internet,
            'cpu' => $t->cpu !== null ? (float) $t->cpu : null,
            'memory' => $t->memory,
            'owner_id' => $t->owner_id,
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }
}

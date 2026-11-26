<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\McpServer;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class McpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'servers' => McpServer::orderBy('name')->get()->map(fn ($s) => $this->payload($s)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $this->validated($request);
        $data['name'] = $request->validate(['name' => ['required', 'string', 'max:64', 'unique:mcp_servers,name']])['name'];
        $data['created_by'] = $request->user()->id;

        $current = $request->user();

        $server = DB::transaction(function () use ($data, $current) {
            $server = McpServer::create($data);
            app(AuditLogger::class)->log($current, 'mcp.created', 'mcp', (string) $server->id, ['name' => $server->name]);

            return $server;
        });

        return response()->json(['server' => $this->payload($server)], 201);
    }

    public function update(Request $request, McpServer $server): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $this->validated($request, $server);
        $data['name'] = $request->validate(['name' => ['required', 'string', 'max:64', 'unique:mcp_servers,name,'.$server->id]])['name'];

        $current = $request->user();

        DB::transaction(function () use ($server, $data, $current) {
            $server->update($data);
            app(AuditLogger::class)->log($current, 'mcp.updated', 'mcp', (string) $server->id, ['name' => $server->name]);
        });

        return response()->json(['server' => $this->payload($server->refresh())]);
    }

    public function destroy(Request $request, McpServer $server): JsonResponse
    {
        $current = $request->user();

        DB::transaction(function () use ($server, $current) {
            $name = $server->name;
            $server->delete();
            app(AuditLogger::class)->log($current, 'mcp.deleted', 'mcp', (string) $server->id, ['name' => $name]);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?McpServer $existing = null): array
    {
        $data = $request->validate([
            'type' => ['required', 'in:stdio,url'],
            'command' => ['nullable', 'string', 'max:255'],
            'args' => ['array'],
            'args.*' => ['string'],
            'env' => ['array'],
            'env.*' => ['string'],
            'url' => ['nullable', 'url'],
            'headers' => ['array'],
            'headers.*' => ['string'],
            'enabled' => ['boolean'],
        ]);

        if (($data['type'] ?? null) === 'stdio' && empty($data['command']) && ! $existing?->command) {
            $data['command'] = $existing?->command;
        }

        return $data;
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
    protected function payload(McpServer $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'type' => $s->type,
            'command' => $s->command,
            'args' => $s->args,
            'env_keys' => array_keys((array) $s->env),
            'url' => $s->url,
            'header_keys' => array_keys((array) $s->headers),
            'enabled' => $s->enabled,
        ];
    }
}

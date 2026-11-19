<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\McpServer;
use Illuminate\Http\Request;

class McpController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'servers' => McpServer::orderBy('name')->get()->map(fn ($s) => $this->payload($s)),
        ]);
    }

    public function store(Request $request)
    {
        $this->requireAdmin($request);

        $data = $this->validated($request);
        $data['name'] = $request->validate(['name' => ['required', 'string', 'max:64', 'unique:mcp_servers,name']])['name'];
        $data['created_by'] = $request->user()->id;

        $server = McpServer::create($data);

        return response()->json(['server' => $this->payload($server)], 201);
    }

    public function update(Request $request, McpServer $server)
    {
        $this->requireAdmin($request);

        $data = $this->validated($request, $server);
        $data['name'] = $request->validate(['name' => ['required', 'string', 'max:64', 'unique:mcp_servers,name,' . $server->id]])['name'];

        $server->update($data);

        return response()->json(['server' => $this->payload($server->refresh())]);
    }

    public function destroy(Request $request, McpServer $server)
    {
        $this->requireAdmin($request);
        $server->delete();

        return response()->json(['ok' => true]);
    }

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
            $data['command'] = $existing->command;
        }

        return $data;
    }

    protected function requireAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }

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

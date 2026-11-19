<?php

namespace App\Http\Controllers\Proxy;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentToken;
use App\Support\DockerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Puerta MCP (design.md D7): un unico endpoint por agente que reenvia las
 * peticiones JSON-RPC al servidor MCP de destino segun ?server=<nombre>:
 *
 *   - tipo url  : HTTP POST directo a su URL (con sus headers/secrets)
 *   - tipo stdio: se ejecuta DENTRO del contenedor del agente (docker exec)
 *                 -> la aislacion de la red interna tambien cubre sus tools
 *
 * v1: protocolo request/response (sin canal SSE de notificaciones).
 */
class McpBridgeController extends Controller
{
    public function __construct(protected DockerClient $docker)
    {
    }

    public function __invoke(Request $request, Agent $agent): JsonResponse
    {
        $token = AgentToken::where('agent_id', $agent->id)->first();
        $bearer = $request->header('Authorization', '');
        if (! $token || ! str_starts_with($bearer, 'Bearer ') || ! hash_equals($token->token_hash, hash('sha256', substr($bearer, 7)))) {
            return $this->rpcError(null, -32000, 'token de agente inválido');
        }

        $serverName = (string) $request->query('server', '');

        $server = $agent->mcpServers()
            ->get()
            ->first(fn ($s) => $s && $s->name === $serverName && $s->enabled);

        if (! $server) {
            return $this->rpcError($request->json('id'), -32000, "server MCP no asignado a este agente: {$serverName}");
        }

        $body = $request->all();

        try {
            $result = $server->type === 'stdio'
                ? $this->forwardStdio($agent, $server, $body)
                : $this->forwardUrl($server, $body);

            if ($result === null) {
                return $this->rpcError($body['id'] ?? null, -32000, 'sin respuesta del server MCP');
            }

            return new JsonResponse($result, 200);
        } catch (\Throwable $e) {
            Log::warning("MCP bridge {$agent->id}/{$serverName}: {$e->getMessage()}");

            return $this->rpcError($body['id'] ?? null, -32000, 'error en el server MCP: ' . $e->getMessage());
        }
    }

    protected function forwardUrl($server, array $body): ?array
    {
        if (! $server->url) {
            return null;
        }

        $headers = array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'], (array) $server->headers);

        $response = Http::withHeaders($headers)
            ->timeout(120)
            ->post($server->url, $body);

        if (! $response->successful() && $response->status() !== 202) {
            throw new \RuntimeException('HTTP ' . $response->status());
        }

        $text = trim($response->body());
        if ($text === '' || $text === 'null') {
            return null;
        }

        // Puede venir envuelto en SSE (data: {...})
        if (str_starts_with($text, 'data:')) {
            $text = substr($text, 5);
        }
        $text = trim(preg_replace('/^data:\s*/m', '', $text));

        return json_decode($text, true);
    }

    protected function forwardStdio(Agent $agent, $server, array $body): ?array
    {
        if (! $server->command) {
            return null;
        }

        $args = ['exec', '-i'];
        foreach ((array) $server->env as $key => $value) {
            $args[] = '--env';
            $args[] = "{$key}=" . $value;
        }

        $process = new Process(array_merge($args, [$agent->container_name, $server->command, ...((array) $server->args)]));
        $process->setTimeout(120);
        $process->setInput(json_encode($body) . "\n");
        $process->run();

        $output = trim($process->getOutput());
        if ($output === '') {
            $error = trim($process->getErrorOutput());
            if ($error !== '') {
                throw new \RuntimeException($error);
            }

            return null;
        }

        // El server stdio responde JSON por linea; tomamos la ultima valida
        foreach (array_reverse(preg_split('/\R/', $output)) as $line) {
            if ($decoded = json_decode(trim($line), true)) {
                return $decoded;
            }
        }

        return null;
    }

    protected function rpcError($id, int $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], 200);
    }
}

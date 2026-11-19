<?php

namespace App\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Support\DockerClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adaptador Hermes (superficie nativa: servidor API OpenAI-compatible del
 * gateway en :8642, dentro del contenedor).
 *
 * Sin conexion persistente: cada mensaje de usuario = una peticion
 * `POST /v1/chat/completions` (streaming SSE) que el bridge consume y
 * traduce a eventos normalizados. La sesion la mantiene Hermes en su
 * volumen (/opt/data) mediante X-Hermes-Session-Key.
 */
class HermesAdapter implements AgentRuntimeAdapter
{
    protected ?string $sessionKey = null;

    public function __construct(
        protected DockerClient $docker,
        protected EventSink $sink,
    ) {
    }

    public function attach(Agent $agent): void
    {
        // Sin estado; solo verificamos que el contenedor este vivo
        if ($this->docker->status($agent->container_name) !== 'running') {
            $agent->forceFill(['status' => AgentStatus::Offline])->save();
        }
    }

    public function detach(Agent $agent): void
    {
        // sin conexion persistente que soltar
    }

    public function isAttached(Agent $agent): bool
    {
        return $this->docker->status($agent->container_name) === 'running';
    }

    /** Hermes no emite eventos persistentes: su flujo llega por HTTP. */
    public function drain(Agent $agent): array
    {
        return [];
    }

    protected function baseUrl(Agent $agent): string
    {
        $port = $agent->registry()['api_port'] ?? 8642;
        $base = $agent->registry()['api_base'] ?? '/v1';

        return 'http://' . $agent->container_name . ":{$port}" . $base;
    }

    protected function authHeaders(Agent $agent): array
    {
        $headers = [];
        if ($token = $agent->token?->token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    protected function sessionHeader(Agent $agent): array
    {
        $config = $agent->config ?? [];
        if (empty($config['session_key'])) {
            $config['session_key'] = 'taap-' . $agent->id . '-' . \Illuminate\Support\Str::uuid();
            $agent->config = $config;
            $agent->saveQuietly();
        }

        return ['X-Hermes-Session-Key' => $config['session_key']];
    }

    public function sendUserMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        $this->sink->dispatch($agent, AgentEvent::statusChange('working'));

        try {
            $response = Http::withHeaders($this->authHeaders($agent) + $this->sessionHeader($agent))
                ->withOptions(['stream' => fn ($data) => $this->consumeChunk($agent, $data)])
                ->timeout(3600)
                ->post($this->baseUrl($agent) . '/chat/completions', [
                    'model' => $agent->model?->model_id ?? 'hermes-agent',
                    'messages' => [
                        ['role' => 'user', 'content' => $content],
                    ],
                    'stream' => true,
                ]);

            if ($response->failed()) {
                $this->sink->dispatch($agent, AgentEvent::error(
                    'HTTP ' . $response->status() . ' del gateway Hermes'
                ));
            }

            $this->sink->dispatch($agent, AgentEvent::runEnd((string) \Illuminate\Support\Str::uuid(), $response->successful()));
            $this->sink->dispatch($agent, AgentEvent::statusChange($response->successful() ? 'idle' : 'error'));
        } catch (ConnectionException $e) {
            $this->sink->dispatch($agent, AgentEvent::error('Gateway Hermes inaccesible: ' . $e->getMessage()));
            $this->sink->dispatch($agent, AgentEvent::statusChange('error'));
        }
    }

    /**
     * Callback del stream SSE: acumula deltas de contenido.
     *
     * @param  string  $chunk  fragmento de cuerpo (SSE: "data: {...}\n\n")
     */
    /** Sin adjuntar: en Hermes (HTTP) es equivalente a sendUserMessage. */
    public function writeMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        $this->sendUserMessage($agent, $content, $userId);
    }

    protected function consumeChunk(Agent $agent, string $chunk): void
    {
        foreach (preg_split('/\R\R/', $chunk) as $frame) {
            $frame = trim($frame);
            if (! str_starts_with($frame, 'data:')) {
                continue;
            }

            $data = trim(substr($frame, 5));
            if ($data === '[DONE]') {
                continue;
            }

            $json = json_decode($data, true);
            if (! is_array($json)) {
                continue;
            }

            $delta = $json['choices'][0]['delta']['content'] ?? null;
            if ($delta !== null && $delta !== '') {
                $this->sink->dispatch($agent, AgentEvent::messageDelta(null, (string) $delta));
            }
        }
    }

    public function resolveApproval(Agent $agent, string $approvalId, bool $approved, ?string $note = null): void
    {
        // v1: el gateway hermes no expone aprobaciones por la API de chat;
        // se resuelve enviando la decision como mensaje de seguimiento.
        $texto = $approved ? 'Continúa.' : 'Detente y pregunta algo diferente.';
        $texto = ($note ? '[decision ' . ($approved ? 'aprobada' : 'denegada') . ': ' . $note . '] ' : ($approved ? '[aprobado] ' : '[denegado] ')) . $texto;

        $this->sendUserMessage($agent, $texto);
        $this->sink->dispatch($agent, AgentEvent::approvalResolved(null, $approvalId, $approved, $note));
    }

    public function applyConfig(Agent $agent): void
    {
        // La config (config.yaml/.env en el volumen) se reescribe por el
        // renderer; el gateway la relee al reiniciar.
        app(AgentManager::class)->restart($agent);
    }

    public function status(Agent $agent): AgentStatus
    {
        $state = $this->docker->status($agent->container_name);
        if ($state !== 'running') {
            return AgentStatus::Offline;
        }

        // Sonda el API server: 401/200 = vivo
        try {
            $response = Http::withHeaders($this->authHeaders($agent))
                ->timeout(5)
                ->get($this->baseUrl($agent) . '/models');

            return $response->successful() || $response->status() === 401
                ? ($agent->status->isRunning() ? $agent->status : AgentStatus::Idle)
                : AgentStatus::Error;
        } catch (ConnectionException) {
            return AgentStatus::Error;
        }
    }

    public function destroy(Agent $agent): void
    {
        // sin estado que destruir
    }
}

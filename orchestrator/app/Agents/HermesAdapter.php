<?php

namespace App\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Support\DockerClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Adaptador Hermes (superficie nativa: API server OpenAI-compatible del
 * gateway, :8642, dentro del contenedor).
 *
 * Verificado contra la imagen oficial (nousresearch/hermes-agent):
 *   - `/v1/chat/completions` (OpenAI wire) con `Authorization: Bearer
 *     API_SERVER_KEY`; `GET /v1/models` devuelve `hermes-agent`.
 *   - Streaming SSE `data: {...}` con `choices[0].delta.content`.
 *   - La conversación se mantiene por `X-Hermes-Session-Key` (Hermes la
 *     persiste en su volumen /opt/data; sobrevive reinicios).
 *   - El modelo real lo define config.yaml (model.default + base_url del
 *     Model Proxy); el campo `model` de la petición es siempre
 *     `hermes-agent`.
 *
 * No hay conexion persistente: cada turno = una peticion HTTP que el
 * bridge consume en streaming y traduce a eventos normalizados.
 */
class HermesAdapter implements AgentRuntimeAdapter
{
    /** Contenido acumulado del turno en curso, por agente. */
    protected array $buffers = [];

    public function __construct(
        protected DockerClient $docker,
        protected EventSink $sink,
    ) {
    }

    public function attach(Agent $agent): void
    {
        // Sin estado persistente; solo verificamos que el contenedor este vivo
        if (! $agent->container_name) {
            return;
        }

        if ($this->docker->status($agent->container_name) !== 'running') {
            $agent->forceFill(['status' => AgentStatus::Offline])->save();
        }
    }

    public function detach(Agent $agent): void
    {
        unset($this->buffers[$agent->id]);
    }

    public function isAttached(Agent $agent): bool
    {
        if (! $agent->container_name) {
            return false;
        }

        return $this->docker->status($agent->container_name) === 'running';
    }

    /** Hermes no emite eventos por un stream persistente: su flujo llega por HTTP. */
    public function drain(Agent $agent): array
    {
        return [];
    }

    protected function baseUrl(Agent $agent): string
    {
        $port = $agent->runtime->registry()['api_port'] ?? 8642;
        $base = $agent->runtime->registry()['api_base'] ?? '/v1';

        return 'http://' . $agent->container_name . ":{$port}" . $base;
    }

    protected function authHeaders(Agent $agent): array
    {
        $headers = [];
        if ($token = $agent->token?->token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        // La conversacion la mantiene Hermes en su volumen; una clave de
        // sesion estable por agente (el orquestador solo ve un mensaje por
        // vez gracias a la cola FIFO).
        $config = $agent->config ?? [];
        if (empty($config['session_key'])) {
            $config['session_key'] = 'taap-agent-' . $agent->id . '-' . Str::ulid();
            $agent->config = $config;
            $agent->saveQuietly();
        }
        $headers['X-Hermes-Session-Key'] = $config['session_key'];

        return $headers;
    }

    public function sendUserMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        $this->buffers[$agent->id] = '';
        $runId = (string) Str::uuid();

        $this->sink->dispatch($agent, AgentEvent::runStart($runId));
        $this->sink->dispatch($agent, AgentEvent::statusChange('working'));

        try {
            $response = Http::withHeaders($this->authHeaders($agent))
                ->withOptions([
                    'stream' => true,
                    'connect_timeout' => 15,
                    'timeout' => 3600,
                ])
                ->post($this->baseUrl($agent) . '/chat/completions', [
                    'model' => 'hermes-agent',
                    'messages' => [
                        ['role' => 'user', 'content' => $content],
                    ],
                    'stream' => true,
                ]);

            // Guzzle 8: `stream` solo acepta bool; el cuerpo SSE se lee
            // a mano por trozos hasta el EOF.
            if ($response->successful()) {
                $body = $response->toPsrResponse()->getBody();
                while (! $body->eof()) {
                    $chunk = $body->read(8192);
                    if ($chunk === '' || $chunk === false) {
                        break;
                    }
                    $this->consumeChunk($agent, $runId, (string) $chunk);
                }
            }

            $final = trim((string) ($this->buffers[$agent->id] ?? ''));

            if ($response->failed()) {
                $this->sink->dispatch($agent, AgentEvent::error(
                    'Gateway Hermes devolvió HTTP ' . $response->status()
                ));
                $this->sink->dispatch($agent, AgentEvent::runEnd($runId, false));
                $this->sink->dispatch($agent, AgentEvent::statusChange('error'));

                return;
            }

            if ($final !== '') {
                $this->sink->dispatch($agent, AgentEvent::messageFinal($runId, $final));
            }

            $this->sink->dispatch($agent, AgentEvent::runEnd($runId, true));
            $this->sink->dispatch($agent, AgentEvent::statusChange('idle'));
        } catch (ConnectionException $e) {
            $this->sink->dispatch($agent, AgentEvent::error('Gateway Hermes inaccesible: ' . $e->getMessage()));
            $this->sink->dispatch($agent, AgentEvent::runEnd((string) Str::uuid(), false));
            $this->sink->dispatch($agent, AgentEvent::statusChange('error'));
        }
    }

    /** Sin stream persistente en Hermes: equivale a sendUserMessage. */
    public function writeMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        $this->sendUserMessage($agent, $content, $userId);
    }

    /**
     * Callback del stream SSE.
     *
     * Acumula los deltas de contenido; el final completo se emite como
     * message.final al cerrar la respuesta (el EventSink lo deduplica).
     *
     * @param  string  $chunk  fragmento de cuerpo (SSE: "data: {...}\n\n")
     */
    protected function consumeChunk(Agent $agent, string $runId, string $chunk): void
    {
        $frames = preg_split('/\R\R/', $chunk) ?: [];

        foreach ($frames as $frame) {
            $frame = trim($frame);
            if ($frame === '' || ! str_starts_with($frame, 'data:')) {
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

            $choice = $json['choices'][0] ?? null;
            if (! is_array($choice)) {
                continue;
            }

            $delta = $choice['delta']['content'] ?? null;
            if ($delta !== null && $delta !== '') {
                $this->buffers[$agent->id] = ($this->buffers[$agent->id] ?? '') . $delta;
                $this->sink->dispatch($agent, AgentEvent::messageDelta($runId, (string) $delta));
            }

            // Modelos de razonamiento: el gateway emite reasoning_content
            // aparte; lo acumulamos como ultima linea de defensa para que
            // un turno que solo piense (content vacio) deje rastro.
            $reasoning = $choice['delta']['reasoning_content'] ?? null;
            if ($reasoning !== null && $reasoning !== ''
                && ($this->buffers[$agent->id] ?? '') === '') {
                $this->buffers[$agent->id] .= $reasoning;
            }
        }
    }

    public function resolveApproval(Agent $agent, string $approvalId, bool $approved, ?string $note = null): void
    {
        // El API de chat no expone aprobaciones: se resuelve como turno de
        // seguimiento (la sesion la mantiene Hermes).
        $prefijo = $approved ? '[aprobado]' : '[denegado]';
        $texto = $note ? $prefijo . ' ' . $note : $prefijo . ' Continúa.';

        $this->sendUserMessage($agent, $texto);
        $this->sink->dispatch($agent, AgentEvent::approvalResolved(null, $approvalId, $approved, $note));
    }

    public function applyConfig(Agent $agent): void
    {
        // Hermes lee config.yaml/.env al arrancar: el cambio requiere reinicio
        // (la sesion sobrevive en el volumen /opt/data).
        app(AgentManager::class)->restart($agent);
    }

    public function status(Agent $agent): AgentStatus
    {
        $state = $this->docker->status($agent->container_name);
        if ($state !== 'running') {
            return AgentStatus::Offline;
        }

        // Sonda el API server: 200 = vivo, 401 = vivo sin clave.
        try {
            $response = Http::withHeaders($this->authHeaders($agent))
                ->timeout(5)
                ->get($this->baseUrl($agent) . '/models');

            if ($response->successful() || $response->status() === 401) {
                // Gateway vivo: si no esta en una corrida, esta inactivo
                return $agent->status->isRunning()
                    && in_array($agent->status, [AgentStatus::Working, AgentStatus::WaitingApproval])
                    ? $agent->status
                    : AgentStatus::Idle;
            }

            return AgentStatus::Error;
        } catch (ConnectionException) {
            return AgentStatus::Error;
        }
    }

    public function destroy(Agent $agent): void
    {
        unset($this->buffers[$agent->id]);
    }
}

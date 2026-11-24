<?php

namespace App\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Support\DockerClient;
use App\Support\ProcessStreams;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * OpenCode (add-opencode-runtime, design.md D1/D2): `opencode serve` como PID 1.
 *
 * Modos de la superficie (el único runtime que los combina):
 *   - COMANDOS por HTTP directo desde el orquestador (`prompt_async`,
 *     permisos, sesión, health): `write()` rutea por tipo de comando.
 *   - EVENTOS por el stream SSE persistente `GET /event`: un coproceso
 *     vigilante (`curl -N` en loop) lo cola en `$dir/out` y el drain de la
 *     base (offset+buffer) lo recoge. Cada línea `data: <json>` es un
 *     evento `{id: evt_*, type, properties}`.
 *
 * Hereda `StdioRpcAdapter` (el drenado por fichero, el watchdog, la cola y
 * el re-attach por `State.StartedAt` son idénticos) y sobreescribe lo que
 * no aplica: no hay writer FIFO (los comandos son HTTP) y el lector es el
 * bucle de `curl`, no `docker logs -f`.
 *
 * Sesión: 1 agente = 1 sesión `ses_*` (patrón `session_key` de Hermes); el
 * almacenamiento vive en `XDG_DATA_HOME=/data` (opencode.db) y sobrevive a
 * los reinicios — el adapter solo re-consulta la misma id. Ver
 * notes/spike-opencode.md (API 1.19.6: endpoints, catálogo de eventos,
 * permisos `once|always|reject`, modelo por sesión).
 */
class OpenCodeAdapter extends StdioRpcAdapter
{
    public function __construct(
        DockerClient $docker,
        EventSink $sink,
    ) {
        parent::__construct($docker, $sink);
    }

    protected function name(): string
    {
        return 'OpenCode';
    }

    /**
     * Sondeo de arranque: un health HTTP directo. El `ready` llega por el
     * stream (`server.connected` en normalize): el health solo confirma que
     * la API sirve.
     *
     * @return array<string, mixed>
     */
    public function probePayload(Agent $agent): array
    {
        return ['type' => 'health'];
    }

    /** Sondeo HTTP ligero cada 3s (el `ready` llega por el stream). */
    public function probeInterval(): int
    {
        return 3;
    }

    /**
     * @return array{reader: ?ProcessStreams, writer: null, dir: string, container_id: ?string, offset: int, buffer: string, lastOutChange: float, run_id: ?string, session_id: ?string, approvals: array, parts: array}
     */
    protected function newSessionState(Agent $agent, string $dir, int $offset): array
    {
        $state = parent::newSessionState($agent, $dir, $offset);

        $state['writer'] = null; // modo api-events: los comandos van por HTTP

        return $state + [
            'agent_id' => $agent->id,
            'agent' => $agent,
            // id de sesión opencode (ses_*) persistido en el config del agente
            'session_id' => $agent->config['session_id'] ?? null,
            // permisos pendientes: requestID => [at, request_id]
            'approvals' => [],
            // texto acumulado por parte (partID => texto), solo partes de
            // mensajes ASISTENT (los roles se aprenden en message.updated)
            'parts' => [],
            // razonamiento acumulado por parte (partID => texto), SEPARADO
            // del contenido (interactive-agent-chat 2.5: el binary 1.19.6
            // streamea las partes reasoning con field "text", igual que el
            // texto: el tipo se aprende en message.part.updated)
            'thinking_parts' => [],
            // tipo de cada parte vista (partID => type): para saber a qué
            // flujo va cada delta cuando el updated de arranque se perdió
            'part_types' => [],
            'msg_roles' => [],
            'tool_seen' => [],
            // ultimo status emitido (dedupe: el stream repite busy por paso)
            'last_status' => null,
            // flag de re-sincronización de permisos tras (re)attach
            'permissions_synced' => false,
        ];
    }

    /**
     * Un solo coproceso: el stream SSE colado en `out`. El loop `while :; do
     * curl -sN …; sleep 1; done` re-arranca solo tras cada EOF (colgaduras
     * del servidor); el watchdog de la base lo re-spawnea si muere.
     *
     * @param  array{reader: ?ProcessStreams, writer: null, dir: string, container_id: ?string, offset: int, buffer: string, lastOutChange: float, run_id: ?string, session_id: ?string, approvals: array, parts: array}  $session
     */
    protected function spawnCoprocesses(string $containerName, array &$session): void
    {
        $this->docker->killCoprocess($session['dir'], 'reader', $containerName);
        $this->docker->killCoprocess($session['dir'], 'writer', $containerName);

        $port = $this->port($session);
        // `sed` suelta el prefijo `data: ` del SSE: el drain de la base
        // espera lineas JSON puras (el stream de opencode 1.19.6 solo emite
        // `data: <json>` + lineas en blanco; verificado en el spike).
        $inner = sprintf(
            // `sed -u`: sin -u, sed bufferiza (block-buffer hacia el pipe) y
            // el `server.connected` nunca llega al fichero.
            "sh -c 'while :; do curl -sN http://127.0.0.1:%d/event | sed -u \"s/^data: //\"; sleep 1; done'",
            $port,
        );

        $session['reader'] = $this->docker->attachReader($containerName, $session['dir'], 'docker exec -i '.$containerName.' '.$inner);
        $session['writer'] = null;
    }

    /** Puerto del servidor opencode (registro). */
    protected function port(?array $session = null): int
    {
        $agent = $session['agent'] ?? null;
        $port = $agent?->runtime?->registry()['api_port'] ?? 4096;

        return (int) $port;
    }

    /**
     * `write()` = router de comandos HTTP (no hay FIFO en este modo): el
     * `in` del IPC no se usa; cada comando es una petición al servidor del
     * contenedor (red taap-internal, DNS por nombre).
     *
     * @param  array<string, mixed>  $command
     */
    protected function write(Agent $agent, array $command): void
    {
        try {
            match ($command['type'] ?? '') {
                'health' => $this->http($agent, 'GET', '/api/health'),
                'health' => $this->http($agent, 'GET', '/api/health', null, 5),
                'session.create' => $this->http($agent, 'POST', '/session', $command['body'] ?? []),
                'session.get' => $this->http($agent, 'GET', '/session/'.$command['session_id']),
                'session.model' => $this->http($agent, 'POST', '/api/session/'.$command['session_id'].'/model', $command['body'] ?? []),
                'message' => $this->http($agent, 'POST', '/session/'.$command['session_id'].'/prompt_async', $command['body'] ?? []),
                'permission.reply' => $this->http($agent, 'POST', '/session/'.$command['session_id'].'/permissions/'.$command['permission_id'], $command['body'] ?? []),
                'permission.list' => $this->http($agent, 'GET', '/permission'),
                default => Log::debug(static::class.': comando desconocido '.($command['type'] ?? '?')),
            };
        } catch (\Throwable $e) {
            // El servidor puede estar arrancando (health) o caído: no romper
            // la pipeline; el watchdog/re-attach y los eventos lo resuelven.
            Log::warning(static::class.': HTTP '.($command['type'] ?? '?').' hacia '.$agent->container_name.': '.$e->getMessage());
        }
    }

    /**
     * Petición HTTP al servidor del contenedor.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|null
     */
    protected function http(Agent $agent, string $method, string $path, ?array $body = null, int $timeout = 15): ?array
    {
        if ($agent->container_name !== null
            && config('taap.agents.test_api_publish', false)) {
            return $this->httpViaExec($agent, $method, $path, $body, $timeout);
        }

        $url = $this->baseUrl($agent).$path;
        $last = null;

        // Tras un restart, `opencode serve` tarda unos segundos en volver a
        // bindar el puerto: reintenta los fallos de conexion (CURL 7/28).
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            try {
                $response = match ($method) {
                    'GET' => Http::timeout($timeout)->get($url),
                    'POST' => Http::timeout($timeout)->post($url, $body ?? []),
                    default => throw new \InvalidArgumentException("Método HTTP {$method}"),
                };
                break;
            } catch (ConnectionException $e) {
                $last = $e;
                usleep(500_000);
            }
        }

        if (isset($last) && ! isset($response)) {
            throw $last;
        }

        return $this->decodeResponse($response->status(), (string) $response->body(), $method, $path);
    }

    /**
     * Transporte de pruebas: el adapter corre fuera de la red taap-internal
     * (phpunit en el host) y el forward de puerto publicado de Docker
     * Desktop se queda obsoleto tras un `docker restart` (el puerto
     * deja de aceptar conexiones aunque el contenedor siga vivo). Se
     * enruta por `docker exec <contenedor> curl <loopback>`: inmune al
     * forward de host y al nombre/IP del contenedor.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function httpViaExec(Agent $agent, string $method, string $path, ?array $body = null, int $timeout = 15): ?array
    {
        $port = $agent->runtime?->registry()['api_port'] ?? 4096;
        $url = 'http://127.0.0.1:'.$port.$path;

        $args = ['docker', 'exec', $agent->container_name, 'curl', '-s', '-o', '-', '-w', '%{http_code}', '--max-time', (string) max(1, $timeout - 2), '-H', 'content-type: application/json'];
        if ($method === 'POST') {
            $args = array_merge($args, ['-X', 'POST', '-d', json_encode($body ?? [])]);
        }
        $args[] = $url;

        $lastErr = null;
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $process = new Process($args, timeout: $timeout + 5);
            $process->run();
            $output = $process->getOutput();
            // -w '%{http_code}' anade el status al final (sin newline)
            $status = (int) substr($output, -3);
            $bodyText = substr($output, 0, -3);
            if ($process->isSuccessful() || $status > 0) {
                return $this->decodeResponse($status, $bodyText, $method, $path);
            }
            $lastErr = $process->getErrorOutput();
            usleep(500_000);
        }

        throw new \RuntimeException('httpViaExec '.$method.' '.$path.' fallo: '.($lastErr ?: 'sin respuesta'));
    }

    /**
     * 2xx/404 devuelven el JSON (o null); el resto lanza.
     */
    protected function decodeResponse(int $status, string $bodyText, string $method, string $path): ?array
    {
        if ($status >= 400 && $status !== 404) {
            throw new \RuntimeException($method.' '.$path.' -> HTTP '.$status.' '.substr($bodyText, 0, 200));
        }

        $decoded = json_decode($bodyText, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Base URL de la API del contenedor (red interna, puerto del registro).
     *
     * Se usa la IP fija del agente (no el nombre): la IP es resolvable desde
     * cualquier punto de la red `taap-internal` (el bridge en produccion) Y
     * desde el host en los tests de integracion (el nombre de contenedor no
     * lo resuelve el host, que esta fuera de la red de la VM de Docker).
     */
    public function baseUrl(Agent $agent): string
    {
        $port = $agent->runtime?->registry()['api_port'] ?? 4096;

        // Modo test: el proceso (phpunit en el host) no resuelve/rutea la
        // red taap-internal; se usa el puerto publicado en 127.0.0.1.
        if (config('taap.agents.test_api_publish', false)
            && ($agent->config['api_test_port'] ?? null) !== null) {
            return 'http://127.0.0.1:'.$agent->config['api_test_port'];
        }

        // Produccion: la IP fija del agente (resolvable desde el bridge, que
        // esta en la misma red; el nombre de contenedor tambien, pero la IP
        // es estable incluso si el nombre cambia).
        $host = $agent->ip ?: $agent->container_name;

        return 'http://'.$host.":{$port}";
    }

    /**
     * Garantiza la sesión del agente: si no hay id persistida (o el servidor
     * no la conoce tras un reinicio) se crea; si existe, se re-sincroniza el
     * modelo por API (`POST /api/session/{id}/model`, verificado: 204).
     */
    protected function ensureSession(Agent $agent): ?string
    {
        // POR REFERENCIA: las mutaciones (session_id) deben persistir.
        $session = &$this->sessions[$agent->id];
        if (! is_array($session)) {
            $session = [];
        }
        $config = $agent->config ?? [];
        $model = $agent->model ?? $agent->model()->first();

        $providerId = 'taap'; // el provider custom del plano (renderer)
        $modelId = $model?->model_id;

        if (($session['session_id'] ?? null) === null && ($config['session_id'] ?? null) !== null) {
            $session['session_id'] = $config['session_id'];
        }

        $sessionId = $session['session_id'] ?? null;

        if ($sessionId !== null) {
            // Verificar que el servidor la conoce (sobrevive reinicios: su
            // almacenamiento vive en /data).
            $existing = $this->http($agent, 'GET', '/session/'.$sessionId);
            if (($existing['id'] ?? null) === $sessionId) {
                $this->syncModel($agent, $sessionId, $providerId, $modelId);

                return $sessionId;
            }
        }

        // Crear (o re-crear si la anterior desapareció).
        $body = ['title' => $agent->name];
        if ($modelId !== null) {
            $body['model'] = ['id' => $modelId, 'providerID' => $providerId];
        }

        $created = $this->http($agent, 'POST', '/session', $body);
        $newId = $created['id'] ?? null;
        if ($newId === null) {
            Log::warning(static::class.': no se pudo crear la sesión en '.$agent->container_name);

            return null;
        }

        $session['session_id'] = $newId;
        $config['session_id'] = $newId;
        $agent->config = $config;
        $agent->saveQuietly();

        return $newId;
    }

    /** Re-sincroniza el modelo de la sesión por API (cambio en caliente). */
    protected function syncModel(Agent $agent, string $sessionId, string $providerId, ?string $modelId): void
    {
        $config = $agent->config ?? [];
        if ($modelId === null || ($config['model_key'] ?? null) === $providerId.'|'.$modelId) {
            return;
        }

        try {
            $this->http($agent, 'POST', '/api/session/'.$sessionId.'/model', [
                'model' => ['id' => $modelId, 'providerID' => $providerId],
            ]);
        } catch (\Throwable $e) {
            Log::warning(static::class.': re-sync de modelo: '.$e->getMessage());
        }

        $config['model_key'] = $providerId.'|'.$modelId;
        $agent->config = $config;
        $agent->saveQuietly();
    }

    public function sendUserMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        $this->writeMessage($agent, $content, $userId);
    }

    public function writeMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        // SIN attach (fix-bridge-cross-process-seams D5): el worker no
        // spawnea coprocesos ni trunca el stream (es el bridge quien
        // drena): un attach aqui mataba al lector del bridge via el .pid
        // compartido y re-adjuntaba en bucle. La viabilidad se comprueba
        // ligera: contenedor vivo + area IPC (las llamadas HTTP no
        // dependen del lector).
        if ($this->docker->status($agent->container_name) !== 'running') {
            throw new \RuntimeException('El agente no está disponible ('.$agent->container_name.')');
        }
        if (! is_dir($this->docker->rpcDir($agent->id))) {
            throw new \RuntimeException('No hay area RPC para el agente');
        }

        $runId = (string) Str::uuid();
        $this->sink->dispatch($agent, AgentEvent::runStart($runId));
        $this->sink->dispatch($agent, AgentEvent::statusChange('working'));

        $sessionId = $this->ensureSession($agent->refresh());
        if ($sessionId === null) {
            $this->sink->dispatch($agent, AgentEvent::error('No se pudo establecer la sesión del agente'));
            $this->sink->dispatch($agent, AgentEvent::runEnd($runId, ok: false));
            $this->sink->dispatch($agent, AgentEvent::statusChange('idle'));

            return;
        }

        // D4: el run_id viaja por el area IPC: el bridge (que drena el
        // stream en OTRO proceso) lo adopta para correlacionar el final.
        // (Tras asegurar la sesion: si fallara, el run muere aqui y el id
        // no debe contaminar la correlacion del siguiente turno.)
        $this->docker->turnsAppend($agent->id, json_encode(['kind' => 'run', 'run_id' => $runId]));

        $session = &$this->sessions[$agent->id];
        $session['run_id'] = $runId;

        $config = $agent->config ?? [];
        $model = $agent->model ?? $agent->model()->first();

        $body = ['parts' => [['type' => 'text', 'text' => $content]]];
        // Override por mensaje: el modelo debe ser resolvable por el provider
        // del plano (el renderer lo incluye en su mapa; ver spike Q4).
        if ($model?->model_id !== null) {
            $body['model'] = ['providerID' => 'taap', 'modelID' => $model->model_id];
        }
        if ($agent->system_prompt) {
            $body['system'] = $agent->system_prompt;
        }

        $this->write($agent, [
            'type' => 'message',
            'session_id' => $sessionId,
            'body' => $body,
        ]);
    }

    /**
     * Adopción del run_id desde la cola durable (fix-bridge-cross-process-
     * seams D4): el worker encoló `{kind:"run", run_id}` al aceptar el
     * mensaje; el bridge (otro proceso, run_id null en memoria) lo toma en
     * el primer evento del turno. Solo consume cuando el turno NO está en
     * vuelo: si ya hay run_id en memoria, el run en cola es el siguiente
     * (prompt encolado) y se deja para su propio busy.
     */
    protected function adoptRunId(int $agentId, array &$session): ?string
    {
        if (($session['run_id'] ?? null) !== null) {
            return $session['run_id'];
        }

        $line = $this->docker->turnsNext($agentId);
        if ($line === null) {
            return null;
        }
        $entry = json_decode($line, true);
        if (is_array($entry) && ($entry['kind'] ?? '') === 'run'
            && is_string($entry['run_id'] ?? null) && $entry['run_id'] !== '') {
            $session['run_id'] = $entry['run_id'];

            return $entry['run_id'];
        }

        Log::debug(static::class.': linea extraña en la cola run (consumida y descartada): '.substr($line, 0, 120));

        return null;
    }

    /** Run en vuelo del agente (introspeccion/tests de costura). */
    public function sessionRunId(int $agentId): ?string
    {
        return $this->sessions[$agentId]['run_id'] ?? null;
    }

    public function resolveApproval(Agent $agent, string $approvalId, bool $approved, ?string $note = null): void
    {
        $session = &$this->sessions[$agent->id];
        if (! is_array($session)) {
            return;
        }

        $pending = $session['approvals'][$approvalId] ?? null;
        $sessionId = $session['session_id'] ?? $agent->config['session_id'] ?? null;
        if ($sessionId === null) {
            return;
        }

        // La UI envía la opción en `note` (misma regla que Claude); la
        // respuesta nativa de opencode es `once|always|reject`.
        $option = in_array($note, ['once', 'always', 'deny'], true)
            ? $note
            : ($approved ? 'once' : 'deny');
        $response = $option === 'deny' ? 'reject' : $option;

        unset($session['approvals'][$approvalId]);

        $this->write($agent, [
            'type' => 'permission.reply',
            'session_id' => $sessionId,
            'permission_id' => $pending['request_id'] ?? ltrim($approvalId, 'oc-'),
            'body' => ['response' => $response],
        ]);

        $note2 = in_array($note, ['once', 'always', 'deny'], true) ? null : $note;
        $this->sink->dispatch($agent, AgentEvent::approvalResolved($session['run_id'] ?? null, $approvalId, $approved, $note2));
    }

    public function applyConfig(Agent $agent): void
    {
        // `opencode.json` se lee al arrancar el servidor: re-render +
        // reinicio. La sesión vive en /data y el modelo se re-sync por API
        // (ensureSession): el chat no se pierde.
        app(AgentManager::class)->restart($agent);
        $this->attach($agent->refresh());
    }

    public function status(Agent $agent): AgentStatus
    {
        $state = $this->docker->status($agent->container_name);

        if ($state !== 'running') {
            return match ($state) {
                'exited', 'dead' => $agent->auto_restart ? AgentStatus::Error : AgentStatus::Stopped,
                default => AgentStatus::Offline,
            };
        }

        // Permiso pendiente = el agente espera al usuario.
        $session = $this->sessions[$agent->id] ?? null;
        if ($session !== null && $session['approvals'] !== []) {
            return AgentStatus::WaitingApproval;
        }

        // Turno en vuelo según la API (solo aparecen sesiones ocupadas).
        if ($sessionId = $session['session_id'] ?? $agent->config['session_id'] ?? null) {
            try {
                $statuses = $this->http($agent, 'GET', '/session/status');
                $st = (string) ($statuses[$sessionId]['type'] ?? '');
                if (in_array($st, ['busy', 'retrying'], true)) {
                    return AgentStatus::Working;
                }
            } catch (\Throwable) {
                // API aún no lista: caer al estado persistido.
            }
        }

        return $agent->status === AgentStatus::Starting
            ? AgentStatus::Idle
            : ($agent->status->isRunning() ? $agent->status : AgentStatus::Idle);
    }

    /**
     * El stream SSE llega como `data: <json>`; el coproceso lo filtra con
     * `sed`, pero se limpia aqui tambien (defensa en profundidad: cualquier
     * variante del prefijo sigue siendo utilizable por el drain de la base).
     *
     * @return array<string, mixed>|null
     */
    protected function decodeLine(string $line): ?array
    {
        if (str_starts_with($line, 'data:')) {
            $line = trim(substr($line, 5));
        }

        return json_decode($line, true);
    }

    /**
     * Dialecto del stream `/event` -> eventos normalizados.
     *
     * Catálogo (spike 1.19.6): `server.connected` (arranque/lectores),
     * `session.status` (busy/idle), `message.updated` (mensajes),
     * `message.part.delta` (texto streaming), `message.part.updated`
     * (partes texto/tool), `permission.asked|replied`, `session.idle`,
     * `session.error`. El resto (diffs, actualizaciones de plugin, tui,
     * session.next.*...) es ruido para la pipeline v1: se descarta.
     *
     * @param  array<string, mixed>  $event  {id: evt_*, type, properties}
     * @return list<AgentEvent>
     */
    protected function normalize(array $event, int $agentId): array
    {
        $type = $event['type'] ?? null;
        $params = (array) ($event['properties'] ?? []);
        // POR REFERENCIA: normalize muta (partes, roles, permisos, run_id).
        $session = &$this->sessions[$agentId];
        if (! is_array($session)) {
            return [];
        }

        // 1 contenedor = 1 agente = 1 sesión: los eventos de otras sesiones
        // (si existieran) no nos competen. Si el worker recreó la sesión
        // (el config persiste la nueva id), la adoptamos de la config: el
        // id durable manda sobre el del attach.
        $sessionID = $params['sessionID'] ?? null;
        if ($sessionID !== null && $session['session_id'] !== null && $sessionID !== $session['session_id']) {
            $cfgId = $session['agent']?->config['session_id'] ?? null;
            if ($cfgId === $sessionID) {
                $session['session_id'] = $sessionID;
            } else {
                return [];
            }
        }

        $out = [];
        $runId = $session['run_id'] ?? null;

        // D4: el run_id nace en el worker (otro proceso); el bridge lo
        // adopta de la cola durable en el PRIMER evento del turno (busy o
        // primer delta/parte), para que el final y los deltas lleven el id
        // que el cliente recibió.
        if ($runId === null
            && (in_array((string) ($params['status']['type'] ?? ''), ['busy', 'retrying'], true) && $type === 'session.status'
                || $type === 'message.part.delta'
                || $type === 'message.part.updated')) {
            $runId = $this->adoptRunId($agentId, $session);
        }
        $runEnded = true; // si el turno ya no estaba en vuelo, no emitimos run.*

        switch ($type) {
            case 'server.connected':
                // Arranque (o nuevo lector) del servidor: el `ready` de la
                // pipeline. El bridge correlaciona con su sondeo.
                $out[] = AgentEvent::statusChange('ready');
                break;

            case 'session.status':
                // Tipos: busy | retrying (fallo de upstream, sigue vivo) |
                // idle (fin real del turno). SOLO `idle` cierra: `retrying`
                // debe mantener el turno en pie (el error final llega en
                // message.updated con `info.error`, verificado en el spike).
                $st = (string) ($params['status']['type'] ?? '');
                if (in_array($st, ['busy', 'retrying'], true)) {
                    $status = $session['approvals'] === [] ? 'working' : 'waiting-approval';
                    $ev = $this->emitStatus($session, $status);
                    if ($ev !== null) {
                        $out[] = $ev;
                    }
                } elseif ($st === 'idle') {
                    $out = array_merge($out, $this->finishTurn($session));
                }
                break;

            case 'message.updated':
                $info = (array) ($params['info'] ?? []);
                $messageID = (string) ($info['id'] ?? '');
                $role = $info['role'] ?? null;
                if ($messageID !== '' && $role !== null) {
                    $session['msg_roles'][$messageID] = $role;
                }
                if ($role === 'assistant' && ! empty($info['error'])) {
                    $err = (array) $info['error'];
                    $msg = (string) ($err['data']['message'] ?? $err['name'] ?? 'Error del modelo');
                    $out[] = AgentEvent::error($msg);
                    if ($runId !== null) {
                        $out[] = AgentEvent::runEnd($runId, ok: false);
                    }
                    $ev = $this->emitStatus($session, 'idle');
                    if ($ev !== null) {
                        $out[] = $ev;
                    }
                    $session['run_id'] = null;
                }
                // mensajes user / sin error: sin aportación (el run.start ya
                // se emitió en writeMessage)
                break;

            case 'message.part.delta':
                $delta = (string) ($params['delta'] ?? '');
                $messageID = (string) ($params['messageID'] ?? '');
                if ($delta !== '' && ($params['field'] ?? 'text') === 'text'
                    && ($session['msg_roles'][$messageID] ?? 'assistant') === 'assistant') {
                    $partId = (string) ($params['partID'] ?? '');
                    // Razonamiento (interactive-agent-chat 2.5): el binary
                    // streamea las partes reasoning con field "text" (ver
                    // binario 1.19.6: reasoning-delta -> updatePartDelta
                    // field:text). El tipo se aprende del
                    // message.part.updated; sin tipo conocido (se perdió el
                    // updated de arranque) se trata como texto y el updated
                    // de fin de parte lo mueve si resulta ser reasoning.
                    if (($session['part_types'][$partId] ?? null) === 'reasoning') {
                        $session['thinking_parts'][$partId] = ($session['thinking_parts'][$partId] ?? '').$delta;
                        $out[] = AgentEvent::thinkingDelta($runId, $delta);
                    } else {
                        $session['parts'][$partId] = ($session['parts'][$partId] ?? '').$delta;
                        $out[] = AgentEvent::messageDelta($runId, $delta);
                    }
                }
                break;

            case 'message.part.updated':
                $part = (array) ($params['part'] ?? []);
                $partType = $part['type'] ?? null;
                $messageID = (string) ($part['messageID'] ?? '');
                $partId = (string) ($part['id'] ?? $messageID);
                if (is_string($partType) && $partId !== '') {
                    $session['part_types'][$partId] = $partType;
                }
                if ($partType === 'text') {
                    // La actualización lleva el texto COMPLETO de la parte:
                    // reemplaza (los deltas ya emitieron los incrementos).
                    // Solo partes de asistente: el texto final del turno no
                    // puede incluir el mensaje del usuario.
                    if (($session['msg_roles'][$messageID] ?? 'assistant') === 'assistant' && isset($part['text'])) {
                        $session['parts'][$partId] = (string) $part['text'];
                        // Si su stream (tipo aún desconocido) se había
                        // acumulado en razonamiento, se descarta esa copia.
                        unset($session['thinking_parts'][$partId]);
                    }
                } elseif ($partType === 'reasoning') {
                    // Parte de razonamiento COMPLETA: reemplaza los deltas
                    // acumulados y se mueve al flujo thinking (corrige la
                    // fusión con el contenido del final).
                    if (($session['msg_roles'][$messageID] ?? 'assistant') === 'assistant' && isset($part['text'])) {
                        $session['thinking_parts'][$partId] = (string) $part['text'];
                        unset($session['parts'][$partId]);
                    }
                } elseif ($partType === 'tool') {
                    $state = (array) ($part['state'] ?? []);
                    $status = $state['status'] ?? null;
                    $callId = (string) ($part['callID'] ?? $part['id'] ?? '');
                    $tool = (string) ($part['tool'] ?? 'tool');
                    $key = $agentId.':'.($part['id'] ?? $callId);
                    $seen = $session['tool_seen'][$key] ?? null;

                    if (in_array($status, ['pending', 'running'], true) && $seen === null) {
                        $session['tool_seen'][$key] = $status;
                        $out[] = AgentEvent::toolStart($runId, $tool, (array) ($state['input'] ?? []));
                    } elseif (($status === 'completed' || $status === 'error') && $seen !== $status) {
                        $session['tool_seen'][$key] = $status;
                        $title = (string) ($state['title'] ?? $state['raw'] ?? '');
                        $out[] = AgentEvent::toolResult($runId, $tool, $status === 'completed', $title);
                    }
                }
                break;

            case 'permission.asked':
                $requestId = (string) ($params['id'] ?? '');
                if ($requestId === '' || isset($session['approvals'][$requestId])) {
                    break;
                }

                $category = (string) ($params['permission'] ?? 'action');
                $detail = (string) ($params['metadata']['command'] ?? implode(', ', (array) ($params['patterns'] ?? [])));
                $prompt = $detail !== '' ? $category.': '.$detail : $category;

                $session['approvals'][$requestId] = [
                    'at' => microtime(true),
                    'request_id' => $requestId,
                ];
                $out[] = AgentEvent::approvalRequest($runId, $requestId, $prompt, [
                    ['value' => 'once', 'label' => 'Aprobar'],
                    ['value' => 'always', 'label' => 'Permitir siempre'],
                    ['value' => 'deny', 'label' => 'Denegar'],
                ]);
                $ev = $this->emitStatus($session, 'waiting-approval');
                if ($ev !== null) {
                    $out[] = $ev;
                }
                break;

            case 'permission.replied':
                $requestId = (string) ($params['requestID'] ?? '');
                if (isset($session['approvals'][$requestId])) {
                    unset($session['approvals'][$requestId]);
                    $reply = (string) ($params['reply'] ?? 'once');
                    $out[] = AgentEvent::approvalResolved($runId, $requestId, $reply !== 'reject', $reply);
                    if ($session['approvals'] === []) {
                        $ev = $this->emitStatus($session, 'working');
                        if ($ev !== null) {
                            $out[] = $ev;
                        }
                    }
                }
                break;

            case 'session.idle':
                $out = array_merge($out, $this->finishTurn($session));
                break;

            case 'session.error':
                $err = (array) ($params['error'] ?? []);
                $msg = (string) ($err['data']['message'] ?? $err['name'] ?? 'Error del agente');
                $out[] = AgentEvent::error($msg);
                if ($runId !== null) {
                    $out[] = AgentEvent::runEnd($runId, ok: false);
                }
                $ev = $this->emitStatus($session, 'idle');
                if ($ev !== null) {
                    $out[] = $ev;
                }
                $session['run_id'] = null;
                break;

            default:
                // server.heartbeat, plugin.added, catalog.updated,
                // session.diff, session.updated, integration.*, ... : ruido.
                break;
        }

        return $out;
    }

    /** Emite `statusChange` solo si el status cambio (dedupe del stream). */
    protected function emitStatus(array &$session, string $status): ?AgentEvent
    {
        if (($session['last_status'] ?? null) !== $status) {
            $session['last_status'] = $status;

            return AgentEvent::statusChange($status);
        }

        return null; // el caller filtra nulls
    }

    /**
     * Fin de turno: emite el texto final acumulado (si lo hay) + `run.end`
     * + `idle`. Solo si el turno estaba en vuelo (evita dobles en la
     * reconexión del stream).
     *
     * @return list<AgentEvent>
     */
    protected function finishTurn(array &$session): array
    {
        $runId = $session['run_id'] ?? null;
        $final = implode('', array_filter($session['parts'] ?? []));
        $thinking = trim(implode("\n", array_filter($session['thinking_parts'] ?? [])));
        $hasContent = $final !== '' || $thinking !== '' || ($session['tool_seen'] ?? []) !== [];

        // Los buffers se limpian SIEMPRE (fix-bridge-cross-process-seams
        // D4): un turno terminado no deja restos que contaminen al
        // siguiente.
        $session['run_id'] = null;
        $session['parts'] = [];
        $session['thinking_parts'] = [];
        $session['part_types'] = [];
        $session['msg_roles'] = [];
        $session['tool_seen'] = [];

        $out = [];
        // Sin run_id en memoria el turno no estaba (o ya no estaba) en
        // vuelo. Un `idle` así SIN restos de contenido (el inicial de la
        // sesion o uno re-emitido) no cierra nada.
        if ($runId === null) {
            // El busy puede haberse perdido (stream truncado, lector
            // re-adjuntado): el turno esta en la cola durable. Adoptarlo
            // para no perderlo.
            $runId = $this->adoptRunId($session['agent_id'] ?? 0, $session);

            if ($runId === null && ! $hasContent) {
                $ev = $this->emitStatus($session, 'idle');
                if ($ev !== null) {
                    $out[] = $ev;
                }

                return $out;
            }

            // Con restos y sin id adoptable: se genera uno y se cierra el
            // turno (el cliente recibira run.end y no queda un run
            // colgando en "working").
            $runId = (string) Str::uuid();
            if (($session['agent'] ?? null) !== null) {
                $this->sink->dispatch($session['agent'], AgentEvent::runStart($runId));
            }
        }

        if ($final !== '') {
            $out[] = AgentEvent::messageFinal($runId, $final, $thinking);
        } elseif ($thinking !== '') {
            // El modelo solo pensó (sin texto visible): el razonamiento se
            // deja como contenido (ultima linea de defensa, igual que el
            // Model Proxy con reasoning_content): no se pierde el turno.
            $out[] = AgentEvent::messageFinal($runId, $thinking, '');
        }
        $out[] = AgentEvent::runEnd($runId, ok: true);
        $ev = $this->emitStatus($session, 'idle');
        if ($ev !== null) {
            $out[] = $ev;
        }

        return $out;
    }

    public function drain(Agent $agent): array
    {
        $events = parent::drain($agent);

        // Timeout de permiso (el runtime NO lo tiene propio: la sesión queda
        // `busy` indefinidamente): caduca -> `reject` (el tool reporta el
        // error al modelo y el turno continúa).
        $timeout = (int) (($agent->config['approval_timeout_seconds'] ?? 0));
        if ($timeout > 0) {
            $session = &$this->sessions[$agent->id];
            if (is_array($session)) {
                $now = microtime(true);
                foreach ($session['approvals'] as $approvalId => $pending) {
                    if (($now - ($pending['at'] ?? 0)) < $timeout) {
                        continue;
                    }
                    $this->resolveApproval($agent, (string) $approvalId, false, 'timeout');
                }
            }
        }

        // Tras un re-attach, re-sincronizar permisos pendientes de la
        // ventana perdida (el stream no es transaccional): el API los lista.
        $session = &$this->sessions[$agent->id];
        if (is_array($session) && ($session['session_id'] ?? null) !== null && $this->needsPermissionSync($session)) {
            try {
                $pending = $this->http($agent, 'GET', '/permission');
                if (is_array($pending)) {
                    foreach ($pending as $item) {
                        if (($item['sessionID'] ?? null) !== $session['session_id']) {
                            continue;
                        }
                        $requestId = (string) ($item['id'] ?? '');
                        if ($requestId === '' || isset($session['approvals'][$requestId])) {
                            continue;
                        }
                        $category = (string) ($item['permission'] ?? 'action');
                        $detail = (string) ($item['metadata']['command'] ?? implode(', ', (array) ($item['patterns'] ?? [])));
                        $session['approvals'][$requestId] = [
                            'at' => microtime(true),
                            'request_id' => $requestId,
                        ];
                        $events[] = AgentEvent::approvalRequest($session['run_id'] ?? null, $requestId, $detail !== '' ? $category.': '.$detail : $category, [
                            ['value' => 'once', 'label' => 'Aprobar'],
                            ['value' => 'always', 'label' => 'Permitir siempre'],
                            ['value' => 'deny', 'label' => 'Denegar'],
                        ]);
                        $events[] = AgentEvent::statusChange('waiting-approval');
                    }
                }
            } catch (\Throwable) {
                // la API no está lista todavía
            }
            $session['permissions_synced'] = true;
        }

        return $events;
    }

    /** Tras cada (re)attach, la ventana de stream se re-sincroniza una vez. */
    protected function needsPermissionSync(array $session): bool
    {
        return empty($session['permissions_synced']);
    }
}

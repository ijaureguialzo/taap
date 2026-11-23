<?php

namespace App\Agents;

use App\Models\Agent;
use App\Support\DockerClient;
use Illuminate\Support\Facades\Log;

/**
 * Adaptador Codex (superficie nativa: `codex app-server`, JSON-RPC 2.0 por
 * stdio; design.md D1, protocolo mapeado en `notes/spike-codex.md` del
 * cambio add-codex-runtime, binary 0.171.0).
 *
 * La maquinaria de la superficie (coprocesos, drain, watchdog, cola FIFO)
 * vive en {@see StdioRpcAdapter} (segundo heredero tras Pi). Este aporta el
 * dialecto JSON-RPC del app-server:
 *
 *   c->s  initialize (sondeo; "Already initialized" = ya esta listo)
 *   c->s  initialized (notificacion, SIN id)
 *   c->s  thread/start {cwd} | thread/resume {threadId}   (continuidad; D2)
 *   c->s  thread/settings/update {threadId, model}        (sync de modelo)
 *   c->s  turn/start {threadId, input:[{type:text}]}     (mensaje; se cola)
 *   s->c  turn/started|completed, item/*, thread/status/changed, ...
 *   s->c  item/reasoning/summaryTextDelta -> thinking.delta (spike 2.1);
 *          el razonamiento acumulado viaja como `thinking` en el final
 *   s->c  item/commandExecution/requestApproval (REQUEST, id N) -> decision
 *
 * `normalize()` diferencia por la forma JSON-RPC: `method` + `id===null` =
 * notificacion; `method` + `id` = REQUEST del servidor (aprobaciones);
 * sin `method` = respuesta de nuestros comandos (solo importa el sondeo y
 * el ciclo de thread; los ACKs se descartan).
 */
class CodexAdapter extends StdioRpcAdapter
{
    public function __construct(
        DockerClient $docker,
        EventSink $sink,
    ) {
        parent::__construct($docker, $sink);
    }

    protected function name(): string
    {
        return 'Codex';
    }

    /**
     * Sondeo: `initialize` con `experimentalApi` (habilita
     * thread/settings/update, que el adapter usa para el sync de modelo).
     * El resultado marca "ready"; el error "Already initialized" (el
     * bridge repite el sondeo cada 5s sobre un app-server vivo) marca
     * "ready" tambien: el sondeo es idempotente por construccioin.
     */
    public function probePayload(Agent $agent): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 'taap-ready-'.$agent->id.'-'.time(),
            'method' => 'initialize',
            'params' => [
                'clientInfo' => ['name' => 'taap-bridge', 'version' => '1.1'],
                'capabilities' => ['experimentalApi' => true],
            ],
        ];
    }

    /**
     * Estado de sesion: el de la base + los que necesita el dialecto Codex
     * (hilo actual, aprobaciones pendientes, agente cacheado para la
     * persistencia del thread id).
     */
    protected function newSessionState(Agent $agent, string $dir, int $offset): array
    {
        $state = parent::newSessionState($agent, $dir, $offset);

        return $state + [
            'thread_id' => $agent->config['thread_id'] ?? null,
            'thread_established' => false,
            'approvals' => [],
            'pending_turns' => [],
            // Razonamiento acumulado del turno: itemId -> texto (los deltas
            // van sumando; el item/completed lo reemplaza por el texto
            // completo oficial, sin doble conteo).
            'thinking' => [],
            'agent' => $agent,
        ];
    }

    /**
     * Arranque: la base escribe el `initialize`; se encadena (en orden, la
     * cola FIFO lo garantiza) la notificacion `initialized` y el ciclo del
     * hilo (resume si hay id persistido, start si no) + sync de modelo.
     *
     * Si el contenedor no esta running la base ya marco offline y no hay
     * sesion: nada que encadenar.
     */
    public function attach(Agent $agent): void
    {
        parent::attach($agent);

        if (! isset($this->sessions[$agent->id])) {
            return;
        }

        $session = &$this->sessions[$agent->id];
        $this->writeStartupSequence($agent, $session);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function writeStartupSequence(Agent $agent, array &$session): void
    {
        // Notificacion SIN id (con id el app-server la trata como request
        // y responde error `unknown variant "initialized"`).
        $this->write($agent, [
            'jsonrpc' => '2.0',
            'method' => 'initialized',
            'params' => new \stdClass,
        ]);

        $this->write($agent, $this->threadPayload($agent, $session));

        // Un hilo REANUDADO arrastra el modelo del rollout anterior (el hilo
        // fija el modelo; el override de turn/start no surte efecto — spike).
        // Un hilo nuevo no necesita sync: el `-c model=` del arranque ya lo
        // lleva. Solo se sincroniza si hay hilo persistido.
        if (($session['thread_id'] ?? null) !== null && ($model = $agent->model?->model_id) !== null) {
            $this->write($agent, [
                'jsonrpc' => '2.0',
                'id' => 'taap-sync-'.$agent->id,
                'method' => 'thread/settings/update',
                'params' => ['threadId' => $session['thread_id'], 'model' => $model],
            ]);
        }

        $session['thread_established'] = true;
    }

    /** `thread/resume` si hay hilo conocido; `thread/start` si no. */
    protected function threadPayload(Agent $agent, array $session): array
    {
        $threadId = $session['thread_id'] ?? null;

        if ($threadId !== null) {
            return [
                'jsonrpc' => '2.0',
                'id' => 'taap-thread-'.$agent->id,
                'method' => 'thread/resume',
                'params' => ['threadId' => $threadId],
            ];
        }

        return [
            'jsonrpc' => '2.0',
            'id' => 'taap-thread-'.$agent->id,
            'method' => 'thread/start',
            'params' => ['cwd' => '/workspace'],
        ];
    }

    /**
     * Mensaje de usuario -> `turn/start`. Un turno en vuelo se cola
     * nativamente en el app-server (spike: dos turn/start simultaneos ->
     * dos turn/completed), sin campos extras.
     */
    public function sendUserMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        if (! $this->ensureAttached($agent)) {
            throw new \RuntimeException('No hay sesion RPC viva para el agente');
        }

        $this->writeTurn($agent, $content);
    }

    /**
     * Variante sin adjuntar (worker de cola): el bridge es el unico que
     * spawnea coprocesos; aqui solo se asegura el area IPC.
     */
    public function writeMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        if (! isset($this->sessions[$agent->id])) {
            $dir = $this->docker->rpcDir($agent->id);
            if (! is_dir($dir)) {
                throw new \RuntimeException('No hay area RPC para el agente');
            }
        }

        $this->writeTurn($agent, $content);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function writeTurn(Agent $agent, string $content, ?array $session = null): void
    {
        $session = $session ?? $this->sessions[$agent->id];

        if (($session['thread_id'] ?? null) === null) {
            // Primer mensaje antes de que el app-server confirme el hilo: el
            // turn/start REQUIERE un threadId string (null es un error RPC,
            // verificado en la integracion). El turno queda encolado en la
            // sesion y se emite cuando llegue el result del hilo.
            // Solo encolar: el thread/start de la secuencia de attach ya lo
            // crea. (Un segundo start por aqui corria el riego de quedar el
            // vacio como hilo del config y perder el historial en el resume.)
            $this->sessions[$agent->id]['pending_turns'][] = $content;

            return;
        }

        $this->write($agent, $this->turnPayload($agent, (string) $session['thread_id'], $content));
    }

    /** Params de un `turn/start` con hilo conocido. */
    protected function turnPayload(Agent $agent, string $threadId, string $content): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 'taap-turn-'.$agent->id.'-'.time().'-'.random_int(1000, 9999),
            'method' => 'turn/start',
            'params' => [
                'threadId' => $threadId,
                'input' => [['type' => 'text', 'text' => $content]],
            ],
        ];
    }

    /**
     * Aprobaciones: el app-server pide por REQUEST (`item/commandExecution/
     * requestApproval` con id JSON-RPC) y se responde con el `decision`
     * tal cual lo ofrece en `availableDecisions` (spike):
     *   "accept"  (una vez)  |  "cancel"  (denegado)
     *   {"acceptWithExecpolicyAmendment": {"execpolicy_amendment": [...]}}
     *   ("permitir siempre"; el prefijo queda persistido en CODEX_HOME)
     *
     * El `approvalId` de la pipeline es "codex-<id-jsonrpc>"; el amendment
     * se guardo de la peticion al registrarla.
     */
    public function resolveApproval(Agent $agent, string $approvalId, bool $approved, ?string $note = null): void
    {
        // La UI envia la opcion elegida en `note` (`once`/`always`/`deny`);
        // un texto libre se deduce del booleano (misma regla que Claude).
        $option = in_array($note, ['once', 'always', 'deny'], true)
            ? $note
            : ($approved ? 'once' : 'deny');
        $note = in_array($note, ['once', 'always', 'deny'], true) ? null : $note;

        $session = &$this->sessions[$agent->id];
        $pending = $session['approvals'][$approvalId] ?? null;

        $decision = match ($option) {
            'deny' => 'cancel',
            'always' => is_array($pending['amendment'] ?? null)
                ? ['acceptWithExecpolicyAmendment' => ['execpolicy_amendment' => $pending['amendment']]]
                : 'accept',
            default => 'accept',
        };

        // El id del REQUEST original (int en el app-server): se recupera de
        // la peticion registrada; sin pendiente se reconstruye del
        // approvalId ("codex-7" -> 7, el tipo original).
        $raw = ltrim($approvalId, 'codex-');
        $rpcId = $pending['rpc_id'] ?? (is_numeric($raw) ? (int) $raw : $raw);

        $this->write($agent, [
            'jsonrpc' => '2.0',
            'id' => $rpcId,
            'result' => ['decision' => $decision],
        ]);

        unset($session['approvals'][$approvalId]);

        $this->sink->dispatch($agent, AgentEvent::approvalResolved(null, $approvalId, $approved, $note));
    }

    public function applyConfig(Agent $agent): void
    {
        // La config nativa (codex.env + codex-args.env: los `-c` del
        // app-server) se reescribe por el renderer; un restart del
        // contenedor la aplica y el hilo se reanuda (la secuencia de
        // arranque re-sync el modelo). El chat no se pierde.
        app(AgentManager::class)->restart($agent);
        $this->attach($agent);
    }

    public function drain(Agent $agent): array
    {
        $events = parent::drain($agent);

        // Timeout de aprobacion (config `approval_timeout_seconds`): el
        // app-server NO tiene timeout propio — una aprobacion sin responder
        // deja el turno colgado para siempre. El bridge drena en cada tick,
        // asi que aqui se auto-resuelven las que caducaron (-> cancel: el
        // tool reporta el error al modelo y el turno continua).
        $timeout = (int) ($agent->config['approval_timeout_seconds'] ?? 0);
        if ($timeout > 0) {
            $session = $this->sessions[$agent->id] ?? null;
            if (is_array($session)) {
                $now = microtime(true);
                foreach ($session['approvals'] as $approvalId => $pending) {
                    if (($now - ($pending['at'] ?? 0)) < $timeout) {
                        continue;
                    }
                    // -> cancel: el tool reporta el error al modelo y el
                    // turno continua; el app-server limpia el flag de
                    // aprobacion (thread/status/changed -> working/idle).
                    $this->resolveApproval($agent, (string) $approvalId, false, 'timeout');
                }
            }
        }

        return $events;
    }

    /**
     * Dialecto JSON-RPC -> AgentEvent.
     *
     * @param  array<string, mixed>  $event  un mensaje JSON-RPC decodificado
     * @return list<AgentEvent>
     */
    protected function normalize(array $event, int $agentId): array
    {
        $out = [];
        $id = $event['id'] ?? null;
        $method = $event['method'] ?? null;

        // ── Respuesta de NUESTROS requests: {"id", "result"|"error"} ──
        if ($method === null) {
            if (isset($event['error'])) {
                return $this->normalizeError($agentId, $id, (array) $event['error']);
            }

            if ($this->isProbeId($id)) {
                $out[] = AgentEvent::statusChange('ready');
            }

            $thread = is_array($event['result'] ?? null) ? ($event['result']['thread'] ?? null) : null;
            if (is_array($thread) && ! empty($thread['id'])) {
                $agent = $this->sessions[$agentId]['agent'] ?? null;
                if ($agent instanceof Agent) {
                    $this->rememberThread($agent, $agentId, (string) $thread['id']);
                } else {
                    $this->sessions[$agentId]['thread_id'] = (string) $thread['id'];
                }
            }

            return $out; // demas result: sin aportacion de estado
        }

        // ── REQUEST del servidor: aprobaciones ──
        if ($id !== null && str_contains((string) $method, 'requestApproval')) {
            return $this->normalizeApprovalRequest($agentId, $id, (array) ($event['params'] ?? []));
        }

        // ── Notificaciones (id null) ──
        $params = (array) ($event['params'] ?? []);
        $turnId = $params['turnId'] ?? null;
        $runId = $turnId !== null ? (string) $turnId : $this->sessions[$agentId]['run_id'] ?? null;

        switch ($method) {
            case 'remoteControl/status/changed':
                // Cada app-server NUEVO (arranque o restart del contenedor)
                // emite esto al arrancar. Si la sesion ya estaba establecida,
                // el proceso actual no conoce el hilo: se re-inicializa
                // (initialize + initialized + thread/resume + sync) sin que el
                // bridge lo sepa — el bridge solo re-attachea si muere el
                // coproceso, y un `docker restart` no lo mata. El FIFO ordena:
                // la re-init se encolara detras de lo ya escrito, y el
                // app-server la procesa antes de cualquier turn nuevo.
                if (($this->sessions[$agentId]['thread_established'] ?? false)
                    || ($this->sessions[$agentId]['thread_id'] ?? null) !== null) {
                    $agent = $this->sessions[$agentId]['agent'] ?? null;
                    if ($agent instanceof Agent) {
                        // attach() completo: re-arranca los coprocesos (el
                        // lector viejo puede estar colgado tras el restart)
                        // y re-escribe initialize + secuencia (resume + sync).
                        $this->attach($agent);
                    }
                }

                return [];

            case 'turn/started':
                $runId = $params['turn']['id'] ?? $runId;
                $this->sessions[$agentId]['run_id'] = $runId;
                $this->sessions[$agentId]['thinking'] = [];
                $out[] = AgentEvent::runStart((string) ($runId ?? 'run'));
                $out[] = AgentEvent::statusChange('working');
                break;

            case 'turn/completed':
                $turn = (array) ($params['turn'] ?? []);
                $runId = $turn['id'] ?? $runId;
                $error = $turn['error'] ?? null;
                // Razonamiento residual tras el ULTIMO mensaje del turno
                // (el que no se adjunto a ningun final): se emite como final
                // sin contenido para que no se pierda en la historia.
                if (($thinking = $this->flushThinking($agentId)) !== '') {
                    $out[] = AgentEvent::messageFinal($runId, '', $thinking);
                }
                $out[] = AgentEvent::runEnd((string) ($runId ?? 'run'), $error === null);
                if ($error !== null) {
                    $out[] = AgentEvent::error(is_string($error) ? $error : json_encode($error));
                }
                $out[] = AgentEvent::statusChange('idle');
                $this->sessions[$agentId]['run_id'] = null;
                break;

            case 'thread/status/changed':
                $status = (array) ($params['status'] ?? []);
                $flags = (array) ($status['activeFlags'] ?? []);
                if (in_array('waitingOnApproval', $flags, true)) {
                    $out[] = AgentEvent::statusChange('working');
                    $out[] = AgentEvent::statusChange('waiting-approval');
                } elseif (($this->sessions[$agentId]['run_id'] ?? null) !== null) {
                    // Flag limpiado (aprobacion resuelta/timeout): el turno
                    // sigue en vuelo.
                    $out[] = AgentEvent::statusChange('working');
                } elseif (($status['type'] ?? '') === 'idle') {
                    $out[] = AgentEvent::statusChange('idle');
                }
                break;

            case 'item/agentMessage/delta':
                $delta = (string) ($params['delta'] ?? '');
                if ($delta !== '') {
                    $out[] = AgentEvent::messageDelta($runId, $delta);
                }
                break;

            case 'item/reasoning/summaryTextDelta':
                // Flujo de razonamiento (spike 2.1): delta SEPARADO del
                // texto. Se acumula por itemId para el final.
                $delta = (string) ($params['delta'] ?? '');
                if ($delta !== '') {
                    $out[] = AgentEvent::thinkingDelta($runId, $delta);
                    $itemId = (string) ($params['itemId'] ?? '');
                    if ($itemId !== '') {
                        $session = &$this->sessions[$agentId];
                        $session['thinking'][$itemId] = ($session['thinking'][$itemId] ?? '').$delta;
                    }
                }
                break;

            case 'item/completed':
                $item = (array) ($params['item'] ?? []);
                $type = (string) ($item['type'] ?? '');
                if ($type === 'agentMessage') {
                    $text = trim((string) ($item['text'] ?? ''));
                    if ($text !== '') {
                        // El razonamiento acumulado ANTES de este mensaje
                        // viaja como su `thinking` (separado del contenido);
                        // los bloques del item NO se funden en el texto.
                        $out[] = AgentEvent::messageFinal($runId, $text, $this->flushThinking($agentId));
                    }
                } elseif ($type === 'reasoning') {
                    // El item completado lleva el texto OFICIAL (supra de los
                    // deltas): se reemplaza, no se suma (idempotente ante un
                    // re-attach que vio los deltas pero no el completed).
                    $itemId = (string) ($item['id'] ?? '');
                    $full = $this->reasoningText($item);
                    if ($itemId !== '' && $full !== '') {
                        $session = &$this->sessions[$agentId];
                        $session['thinking'][$itemId] = $full;
                    }
                } elseif ($type === 'commandExecution') {
                    $ok = (($item['exitCode'] ?? null) ?? 0) === 0;
                    $summary = trim((string) ($item['aggregatedOutput'] ?? ''));
                    if (mb_strlen($summary) > 500) {
                        $summary = mb_substr($summary, 0, 500).'…';
                    }
                    $out[] = AgentEvent::toolResult($runId, 'shell', $ok, $summary);
                }
                break;

            case 'item/started':
                $item = (array) ($params['item'] ?? []);
                if (($item['type'] ?? '') === 'commandExecution') {
                    $cmd = $item['commandActions'][0]['command'] ?? $item['command'] ?? '';
                    $out[] = AgentEvent::toolStart($runId, 'shell', [
                        'command' => (string) $cmd,
                        'cwd' => $item['cwd'] ?? null,
                    ]);
                }
                break;

            case 'thread/tokenUsage/updated':
                // El tracking de tokens es v1.2 (todavia no hay evento
                // `usage` en la pipeline): se registra en el log.
                Log::debug(static::class.': usage '.json_encode($params['tokenUsage']['total'] ?? null));
                break;

            default:
                // thread/started (el id se toma del result), warning,
                // account/rateLimits/updated, remoteControl/status/changed:
                // ruido para el chat.
                break;
        }

        return $out;
    }

    /**
     * Texto completo de razonamiento de un item `reasoning`: los partes de
     * `summary` (el resumen legible que expone el app-server; arrays de
     * strings, spike 2.1) y, si no hay summary, los de `content`.
     *
     * @param  array<string, mixed>  $item
     */
    protected function reasoningText(array $item): string
    {
        foreach (['summary', 'content'] as $field) {
            $parts = [];
            foreach ((array) ($item[$field] ?? []) as $part) {
                if (is_string($part) && $part !== '') {
                    $parts[] = $part;
                } elseif (is_array($part) && isset($part['text']) && (string) $part['text'] !== '') {
                    $parts[] = (string) $part['text'];
                }
            }
            if ($parts !== []) {
                return implode('', $parts);
            }
        }

        return '';
    }

    /**
     * Razonamiento acumulado del turno (por parte, en orden de llegada):
     * se concatena y se limpia el buffer. Cada agentMessage lleva el que le
     * precede; el cola final del turn/completed el que queda.
     */
    protected function flushThinking(int $agentId): string
    {
        $session = &$this->sessions[$agentId];
        $thinking = trim(implode("\n", array_values($session['thinking'] ?? [])));
        $session['thinking'] = [];

        return $thinking;
    }

    /**
     * `item/commandExecution/requestApproval` (REQUEST con id JSON-RPC) ->
     * approval.request. Se guarda el id original (para la respuesta) y el
     * amendment propuesto (para construir "permitir siempre").
     *
     * @param  array<string, mixed>  $params
     * @return list<AgentEvent>
     */
    protected function normalizeApprovalRequest(int $agentId, mixed $rpcId, array $params): array
    {
        $approvalId = 'codex-'.$rpcId;
        $runId = $params['turnId'] !== null ? (string) $params['turnId'] : $this->sessions[$agentId]['run_id'] ?? null;

        $wrapped = (string) ($params['command'] ?? ''); // /bin/zsh -lc '...'
        $action = (string) ($params['commandActions'][0]['command'] ?? '');
        $prompt = $action !== '' && $action !== $wrapped ? $action : $wrapped;

        $this->sessions[$agentId]['approvals'][$approvalId] = [
            'rpc_id' => $rpcId,
            'amendment' => $params['proposedExecpolicyAmendment'] ?? null,
            'at' => microtime(true),
        ];

        return [
            AgentEvent::approvalRequest(
                $runId,
                $approvalId,
                'Comando: '.$prompt.($params['cwd'] !== null ? " ({$params['cwd']})" : ''),
                [
                    ['value' => 'once', 'label' => 'Permitir'],
                    ['value' => 'always', 'label' => 'Permitir siempre'],
                    ['value' => 'deny', 'label' => 'Denegar'],
                ]
            ),
        ];
    }

    /**
     * Errores JSON-RPC: los del sondeo ("Already initialized" y afines)
     * marcan "ready" (app-server vivo); los demas se traducen a error.
     *
     * @param  array<string, mixed>  $error  {"code": int, "message": string}
     * @return list<AgentEvent>
     */
    protected function normalizeError(int $agentId, mixed $id, array $error): array
    {
        if ($this->isProbeId($id)) {
            return [AgentEvent::statusChange('ready')];
        }

        return [AgentEvent::error((string) ($error['message'] ?? 'error RPC'))];
    }

    /** Id del sondeo: generable ("taap-ready-..."), correlacionado por prefijo. */
    protected function isProbeId(mixed $id): bool
    {
        return is_string($id) && str_starts_with($id, 'taap-ready-');
    }

    /**
     * Guarda el id del hilo en el estado de sesion, lo persiste en el config
     * y emite los turnos encolados a la espera de este id (primer mensaje).
     */
    protected function rememberThread(Agent $agent, int $agentId, string $threadId): void
    {
        $session = &$this->sessions[$agentId];
        if (($session['thread_id'] ?? null) === $threadId) {
            return;
        }

        $session['thread_id'] = $threadId;

        // Turnos que llegaron antes de que el hilo existiera: se emiten ya.
        foreach ($session['pending_turns'] as $content) {
            $this->write($agent, $this->turnPayload($agent, $threadId, $content));
        }
        $session['pending_turns'] = [];

        $model = $session['agent'] ?? null;
        if (! $model instanceof Agent) {
            return;
        }

        $config = $model->config ?? [];
        if (($config['thread_id'] ?? null) !== $threadId) {
            $config['thread_id'] = $threadId;
            $model->config = $config;
            $model->saveQuietly();
        }
    }
}

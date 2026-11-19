<?php

namespace App\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Support\DockerClient;
use App\Support\ProcessStreams;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Adaptador Pi (superficie nativa: `pi --mode rpc`, JSONL por stdio).
 *
 * El contenedor Pi corre `pi --mode rpc` como PID 1 (entrypoint de la
 * imagen). El RPC JSONL viaja por IPC de archivos: un coproceso
 * `docker attach --no-stdin` volca el stdout a $dir/out y un coproceso
 * watcher entrega cada linea de $dir/in al stdin de PID 1
 * (`/proc/1/fd/0` via docker exec -i). Razon: el build local de PHP 8.5
 * tiene rotos los pipes de proc_open (EBADF/fd cerrado inmediato).
 *
 * Mapa de eventos (ver docs/rpc.md y json.md de pi-coding-agent):
 *   agent_start            -> run.start
 *   agent_settled{aborted}-> run.end
 *   message_update (delta) -> message.delta
 *   message_end (assistant)-> message.final
 *   tool_execution_start   -> tool.start
 *   tool_execution_end     -> tool.result
 *   extension_ui_request   -> approval.request (diálogos)
 */
class PiAdapter implements AgentRuntimeAdapter
{
    /**
     * @var array<int, array{reader: ?ProcessStreams, writer: ?ProcessStreams, dir: string, container_id: ?string, offset: int, buffer: string, lastOutChange: float, run_id: ?string}>
     */
    protected array $sessions = [];

    public function __construct(
        protected DockerClient $docker,
        protected EventSink $sink,
    ) {}

    public function attach(Agent $agent): void
    {
        if ($this->isAttached($agent)) {
            return;
        }

        $this->detach($agent);

        if ($this->docker->status($agent->container_name) !== 'running') {
            $agent->forceFill(['status' => AgentStatus::Offline])->save();

            return;
        }

        $dir = $this->docker->rpcDir($agent->id);
        $this->sessions[$agent->id] = $this->newSessionState($agent, $dir, 0);

        // Determinismo: hay un SOLO lector y un SOLO escritor por agente.
        // Se matan los previos (posibles huérfanos de un bridge anterior)
        // y se spawnean de cero.
        $session = &$this->sessions[$agent->id];
        $this->spawnCoprocesses($agent->container_name, $session);

        // Probar el RPC: get_state -> el sink marca al agente "ready"
        $this->write($agent, ['type' => 'get_state', 'id' => 'taap-ready-'.$agent->id]);

        Log::info("PiAdapter: IPC establecido con {$agent->container_name}");
    }

    public function detach(Agent $agent): void
    {
        $session = $this->sessions[$agent->id] ?? null;
        if ($session) {
            // Lo que lanzamos nosotros en este proceso...
            $session['reader']?->stop(3);
            $session['writer']?->stop(3);
            // ...y los que puedan quedar registrados en .pid (huérfanos)
            $this->docker->killCoprocess($session['dir'], 'reader');
            $this->docker->killCoprocess($session['dir'], 'writer');
            unset($this->sessions[$agent->id]);
        }
    }

    /** Vivo si los coprocesos del agente (reader+writer) siguen en pie. */
    public function isAttached(Agent $agent): bool
    {
        $session = $this->sessions[$agent->id] ?? null;
        if ($session === null) {
            return false;
        }
        $dir = $session['dir'];

        // El lector sigue el contenedor que existia al attach; si el
        // agente tiene otro (re-provisionado), hay que re-attachar.
        if (($session['container_id'] ?? null) !== ($agent->container_id ?? null)) {
            return false;
        }

        return $this->docker->coprocessAlive($dir, 'reader', $dir)
            && $this->docker->coprocessAlive($dir, 'writer', $dir);
    }

    /**
     * @return array{reader: ?ProcessStreams, writer: ?ProcessStreams, dir: string, container_id: ?string, offset: int, buffer: string, lastOutChange: float, run_id: ?string}
     */
    protected function newSessionState(Agent $agent, string $dir, int $offset): array
    {
        return [
            'reader' => null,
            'writer' => null,
            'dir' => $dir,
            // `docker logs -f` resuelve el nombre al spawnearse: si el
            // contenedor se re-crea (nuevo ID, mismo nombre) el lector
            // antiguo sigue al cadaver. Guardamos el ID y lo validamos en
            // isAttached para forzar el re-attach.
            'container_id' => $agent->container_id,
            'offset' => $offset,
            'buffer' => '',
            'lastOutChange' => microtime(true),
            'run_id' => null,
        ];
    }

    /**
     * @param  array{reader: ?ProcessStreams, writer: ?ProcessStreams, dir: string, container_id: ?string, offset: int, buffer: string, lastOutChange: float, run_id: ?string}  $session
     */
    protected function spawnCoprocesses(string $containerName, array &$session): void
    {
        $this->docker->killCoprocess($session['dir'], 'reader');
        $this->docker->killCoprocess($session['dir'], 'writer');
        $session['reader'] = $this->docker->attachReader($containerName, $session['dir']);
        $session['writer'] = $this->docker->startWriter($containerName, $session['dir']);
    }

    protected function detachForDir(string $dir): void
    {
        $this->docker->killCoprocess($dir, 'reader');
        $this->docker->killCoprocess($dir, 'writer');
    }

    /**
     * El lector (`docker logs -f`) puede quedarse mudo sin morir: el daemon
     * suelta el stream y el proceso sigue vivo. Se considera stale si hay
     * trabajo pendiente (input sin entregar o agente activo) y `out` no
     * crece en 60s.
     *
     * @param  array{reader: ?ProcessStreams, writer: ?ProcessStreams, dir: string, container_id: ?string, offset: int, buffer: string, lastOutChange: float, run_id: ?string}  $session
     */
    protected function readerStale(array $session, Agent $agent): bool
    {
        if (microtime(true) - ($session['lastOutChange'] ?? microtime(true)) < 60) {
            return false;
        }

        $inSize = (int) (@filesize($session['dir'].'/in') ?: 0);
        $inOff = (int) (@filesize($session['dir'].'/in.off') ?: 0);

        return $inSize > $inOff
            || $agent->status === AgentStatus::Working
            || $agent->status === AgentStatus::Starting
            || $agent->status === AgentStatus::WaitingApproval;
    }

    /** Sonda el RPC (get_state); el bridge lo llama hasta que el agente esta "ready". */
    public function probe(Agent $agent): void
    {
        if ($this->ensureAttached($agent)) {
            $this->write($agent, ['type' => 'get_state', 'id' => 'taap-ready-'.$agent->id.'-'.time()]);
        }
    }

    /**
     * Sanea el IPC (re-attach si murio) y deja la sesion lista para escribir.
     */
    protected function ensureAttached(Agent $agent): bool
    {
        if (! $this->isAttached($agent)) {
            $this->attach($agent);
        }

        return isset($this->sessions[$agent->id]) && $this->isAttached($agent);
    }

    /**
     * Recoge los eventos nuevos del stdout del agente (sondeo del archivo).
     *
     * @return list<AgentEvent>
     */
    public function drain(Agent $agent): array
    {
        $idx = $agent->id;
        if (! isset($this->sessions[$idx])) {
            return [];
        }
        $session = &$this->sessions[$idx]; // por referencia: el offset se conserva

        // Watchdog: `docker logs -f` puede quedarse mudo (el daemon suelta el
        // stream) sin que el proceso lector muera. Si hay trabajo pendiente y
        // el archivo `out` no crece en un minuto, se re-adjunta el IPC.
        if ($this->readerStale($session, $agent)) {
            Log::warning("PiAdapter: lector stale en {$agent->container_name}; re-adjuntando");
            $agentId = $agent->id;
            $containerName = $agent->container_name;
            $dir = $session['dir'];
            unset($this->sessions[$idx]);
            $this->detachForDir($dir);
            if ($this->docker->status($containerName) === 'running') {
                $fresh = $this->newSessionState($agent, $dir, (int) (@filesize("$dir/out") ?: 0));
                $this->sessions[$agentId] = $fresh;
                $session = &$this->sessions[$agentId];
                $this->spawnCoprocesses($containerName, $session);
            }

            return [];
        }

        $out = $session['dir'].'/out';
        $size = @filesize($out);
        if ($size === false || $size < $session['offset']) {
            // truncado (nuevo attach): reiniciar
            $session['offset'] = 0;
            $session['buffer'] = '';
            $size = @filesize($out);
            $size = $size === false ? 0 : $size;
        }
        if ($size <= $session['offset']) {
            return [];
        }

        $handle = @fopen($out, 'rb');
        if ($handle === false) {
            return [];
        }
        fseek($handle, $session['offset']);
        $chunk = (string) fread($handle, max(1, $size - $session['offset']));
        fclose($handle);
        $session['offset'] = $size;
        $session['lastOutChange'] = microtime(true);

        $session['buffer'] .= $chunk;
        $events = [];
        while (($pos = strpos($session['buffer'], "\n")) !== false) {
            $line = trim(substr($session['buffer'], 0, $pos));
            $session['buffer'] = substr($session['buffer'], $pos + 1);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events = array_merge($events, $this->normalize($decoded, $agent->id));
            } else {
                Log::debug('Pi: linea no-JSON de '.$agent->id.': '.substr($line, 0, 200));
            }
        }

        return $events;
    }

    public function sendUserMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        if (! $this->ensureAttached($agent)) {
            throw new \RuntimeException('No hay sesion RPC viva para el agente');
        }

        $command = [
            'id' => Str::uuid()->toString(),
            'type' => 'prompt',
            'message' => $content,
        ];
        // Si el agente está en plena corrida, cola el mensaje (FIFO)
        if ($agent->status === AgentStatus::Working) {
            $command['streamingBehavior'] = 'followUp';
        }

        $this->write($agent, $command);
    }

    public function resolveApproval(Agent $agent, string $approvalId, bool $approved, ?string $note = null): void
    {
        // El shape cubre los tres dialogos del modo RPC: confirm lee
        // `confirmed`, select/input leen `value`. Sin `confirmed`, un
        // confirm aprobado se resolveria como denegado.
        $command = [
            'id' => $approvalId,
            'type' => 'extension_ui_response',
            'confirmed' => $approved,
            'value' => $note ?? ($approved ? 'ok' : 'denied'),
            'cancelled' => false,
        ];
        $this->write($agent, $command);

        $this->sink->dispatch($agent, AgentEvent::approvalResolved(null, $approvalId, $approved, $note));
    }

    public function applyConfig(Agent $agent): void
    {
        // La config nativa (models.json, mcp.json, agent.env) se reescribe
        // por el renderer; un restart del contenedor la aplica y la sesion
        // se reanuda con el mismo --session-id.
        app(AgentManager::class)->restart($agent);
        $this->attach($agent);
    }

    public function status(Agent $agent): AgentStatus
    {
        $state = $this->docker->status($agent->container_name);

        return match ($state) {
            'running' => $agent->status === AgentStatus::Starting ? AgentStatus::Idle : ($agent->status->isRunning() ? $agent->status : AgentStatus::Idle),
            'exited', 'dead' => $agent->auto_restart ? AgentStatus::Error : AgentStatus::Stopped,
            default => AgentStatus::Offline,
        };
    }

    public function destroy(Agent $agent): void
    {
        $this->detach($agent);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<AgentEvent>
     */
    protected function normalize(array $event, int $agentId): array
    {
        $type = $event['type'] ?? '';
        // Pi no lleva run_id en sus eventos RPC: se correlaciona localmente.
        // agent_start abre una corrida (id nuevo) y agent_settled la cierra;
        // todo lo intermedio hereda el id.
        if ($type === 'agent_start') {
            $runId = Str::uuid();
            $this->sessions[$agentId]['run_id'] = $runId;
        } else {
            $runId = $this->sessions[$agentId]['run_id'] ?? $event['run_id'] ?? null;
        }
        $out = [];

        switch ($type) {
            case 'response':
                if (($event['command'] ?? '') === 'get_state' && ($event['success'] ?? false)) {
                    $out[] = AgentEvent::statusChange('ready');
                } elseif (($event['success'] ?? false) === false) {
                    $out[] = AgentEvent::error((string) ($event['error'] ?? 'comando RPC falló'));
                }
                break;

            case 'agent_start':
                $out[] = AgentEvent::runStart((string) ($runId ?? Str::uuid()));
                $out[] = AgentEvent::statusChange('working');
                break;

            case 'agent_settled':
                $out[] = AgentEvent::runEnd((string) ($runId ?? 'run'), ! ($event['aborted'] ?? false));
                $out[] = AgentEvent::statusChange('idle');
                $this->sessions[$agentId]['run_id'] = null;
                break;

            case 'message_update':
                $inner = $event['assistantMessageEvent'] ?? [];
                if (($inner['type'] ?? '') === 'text_delta') {
                    $out[] = AgentEvent::messageDelta($runId, (string) ($inner['delta'] ?? ''));
                } elseif (isset($inner['delta'])) {
                    $out[] = AgentEvent::messageDelta($runId, (string) $inner['delta']);
                }
                break;

            case 'message_end':
                $message = $event['message'] ?? [];
                if (($message['role'] ?? '') === 'assistant') {
                    $content = $this->assistantText($message);
                    if ($content !== '') {
                        $out[] = AgentEvent::messageFinal($runId, $content);
                    }
                }
                break;

            case 'tool_execution_start':
                $out[] = AgentEvent::toolStart(
                    $runId,
                    (string) ($event['toolName'] ?? 'tool'),
                    (array) ($event['args'] ?? [])
                );
                break;

            case 'tool_execution_update':
                $partial = $event['partialResult']['content'][0]['text'] ?? null;
                if ($partial !== null) {
                    $out[] = AgentEvent::toolResult($runId, (string) ($event['toolName'] ?? 'tool'), true, (string) $partial);
                }
                break;

            case 'tool_execution_end':
                $text = $event['result']['content'][0]['text'] ?? '';
                $out[] = AgentEvent::toolResult(
                    $runId,
                    (string) ($event['toolName'] ?? 'tool'),
                    ! ($event['isError'] ?? false),
                    (string) $text
                );
                break;

            case 'extension_ui_request':
                // confirm: {title, message}; select/input: {title, options|placeholder}
                $title = (string) ($event['title'] ?? '');
                $body = trim((string) ($event['message'] ?? $event['prompt'] ?? ''));
                $label = $title !== '' && $body !== '' ? $title.': '.$body
                    : ($title !== '' ? $title : ($body !== '' ? $body : 'Se requiere una decision'));
                $out[] = AgentEvent::approvalRequest(
                    $runId,
                    (string) ($event['id'] ?? Str::uuid()),
                    $label,
                    (array) ($event['options'] ?? [])
                );
                break;

            case 'response':
                // Respuesta a un comando; solo interesa para errores
                if (! ($event['success'] ?? true)) {
                    $out[] = AgentEvent::error((string) ($event['error'] ?? 'comando fallido'));
                }
                break;

            default:
                // compaction, turn_*, session, etc.: se registran crudos
                if (in_array($type, ['compaction_start', 'compaction_end', 'auto_retry_start', 'auto_retry_end', 'extension_error'], true)) {
                    $out[] = AgentEvent::error("{$type}");
                }
        }

        return $out;
    }

    /** @param array<string, mixed> $message */
    protected function assistantText(array $message): string
    {
        $content = $message['content'] ?? '';
        if (is_string($content)) {
            return $content;
        }

        $parts = [];
        foreach ((array) $content as $block) {
            if (is_array($block) && isset($block['text'])) {
                $parts[] = $block['text'];
            }
        }

        return implode('', $parts);
    }

    /**
     * @param  array<string, mixed>  $command
     */
    protected function write(Agent $agent, array $command): void
    {
        $dir = $this->sessions[$agent->id]['dir'] ?? $this->docker->rpcDir($agent->id);
        if (@file_put_contents($dir.'/in', json_encode($command)."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir en el IPC del agente');
        }
    }

    /**
     * Envio de mensaje sin adjuntar: escribe el prompt en el IPC (el bucle
     * writer del bridge lo entrega al FIFO). El worker de cola no spawnea
     // coprocesos: evita writer duplicados. Si no hay sesion, se adjunta.
     */
    public function writeMessage(Agent $agent, string $content, ?int $userId = null): void
    {
        // Sin attach aqui: el bridge es el unico que spawnea coprocesos
        // (evita writer duplicados por carrera). El prompt queda en el
        // fichero y el bucle writer del bridge lo entrega al FIFO.
        if (! isset($this->sessions[$agent->id])) {
            $dir = $this->docker->rpcDir($agent->id);
            if (! is_dir($dir)) {
                throw new \RuntimeException('No hay area RPC para el agente');
            }
        }

        $command = [
            'id' => Str::uuid()->toString(),
            'type' => 'prompt',
            'message' => $content,
        ];

        // Si el agente esta en plena corrida, cola el mensaje (FIFO)
        if ($agent->status === AgentStatus::Working) {
            $command['streamingBehavior'] = 'followUp';
        }

        $this->write($agent, $command);
    }
}

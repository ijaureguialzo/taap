<?php

namespace App\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Support\DockerClient;
use Illuminate\Support\Facades\Log;
use App\Support\ProcessStreams;

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
     * @var array<int, array{reader: ?ProcessStreams, writer: ?ProcessStreams, dir: string, offset: int, buffer: string}>
     */
    protected array $sessions = [];

    public function __construct(
        protected DockerClient $docker,
        protected EventSink $sink,
    ) {
    }

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
        $this->sessions[$agent->id] = [
            'reader' => null,
            'writer' => null,
            'dir' => $dir,
            'offset' => 0,
            'buffer' => '',
        ];

        // Determinismo: hay un SOLO lector y un SOLO escritor por agente.
        // Se matan los previos (posibles huérfanos de un bridge anterior)
        // y se spawnean de cero.
        $this->docker->killCoprocess($dir, 'reader');
        $this->docker->killCoprocess($dir, 'writer');
        $this->sessions[$agent->id]['reader'] = $this->docker->attachReader($agent->container_name, $dir);
        $this->sessions[$agent->id]['writer'] = $this->docker->startWriter($agent->container_name, $dir);

        // Probar el RPC: get_state -> el sink marca al agente "ready"
        $this->write($agent, ['type' => 'get_state', 'id' => 'taap-ready-' . $agent->id]);

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

        return $this->docker->coprocessAlive($dir, 'reader', $dir)
            && $this->docker->coprocessAlive($dir, 'writer', $dir);
    }

    /** Sonda el RPC (get_state); el bridge lo llama hasta que el agente esta "ready". */
    public function probe(Agent $agent): void
    {
        if ($this->ensureAttached($agent)) {
            $this->write($agent, ['type' => 'get_state', 'id' => 'taap-ready-' . $agent->id . '-' . time()]);
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

        return $this->sessions[$agent->id] !== null && $this->isAttached($agent);
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

        $out = $session['dir'] . '/out';
        $size = @filesize($out);
        if ($size === false || $size < $session['offset']) {
            // truncado (nuevo attach): reiniciar
            $session['offset'] = 0;
            $session['buffer'] = '';
            $size = @filesize($out) ?? 0;
        }
        if ($size <= $session['offset']) {
            return [];
        }

        $handle = @fopen($out, 'rb');
        if ($handle === false) {
            return [];
        }
        fseek($handle, $session['offset']);
        $chunk = (string) fread($handle, (int) ($size - $session['offset']));
        fclose($handle);
        $session['offset'] = $size;

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
                $events = array_merge($events, $this->normalize($decoded));
            } else {
                Log::debug('Pi: linea no-JSON de ' . $agent->id . ': ' . substr($line, 0, 200));
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
            'id' => \Illuminate\Support\Str::uuid()->toString(),
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
        $command = [
            'id' => $approvalId,
            'type' => 'extension_ui_response',
            'value' => $approved ? ($note ?: 'ok') : ($note ?: 'denied'),
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

    /** @param array<string, mixed> $event */
    protected function normalize(array $event): array
    {
        $type = $event['type'] ?? '';
        $runId = $event['run_id'] ?? null;
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
                $out[] = AgentEvent::runStart((string) ($runId ?? \Illuminate\Support\Str::uuid()));
                $out[] = AgentEvent::statusChange('working');
                break;

            case 'agent_settled':
                $out[] = AgentEvent::runEnd((string) ($runId ?? 'run'), ! ($event['aborted'] ?? false));
                $out[] = AgentEvent::statusChange('idle');
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
                $out[] = AgentEvent::approvalRequest(
                    $runId,
                    (string) ($event['id'] ?? \Illuminate\Support\Str::uuid()),
                    (string) ($event['message'] ?? $event['prompt'] ?? 'Se requiere una decisión'),
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

    protected function write(Agent $agent, array $command): void
    {
        $dir = $this->sessions[$agent->id]['dir'] ?? $this->docker->rpcDir($agent->id);
        if (@file_put_contents($dir . '/in', json_encode($command) . "\n", FILE_APPEND | LOCK_EX) === false) {
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
            'id' => \Illuminate\Support\Str::uuid()->toString(),
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

<?php

namespace App\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Support\DockerClient;
use App\Support\ProcessStreams;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Base de los runtimes con SUPERFICIE NATIVA DE STREAM PERSISTENTE: un
 * proceso residente dentro del contenedor (PID 1) habla JSONL por stdio y
 * el orquestador le llega por IPC de archivos (design.md D2/D12).
 *
 *   - writer coproceso:  linea de $dir/in  ->  stdin de PID 1 (FIFO)
 *   - reader coproceso:  stdout de PID 1   ->  $dir/out (docker logs -f)
 *
 * La base encapsula toda la maquinaria transversal de esa superficie
 * (estado de sesion, coprocesos, drain con offset+buffer, watchdog de
 * lector stale, cola FIFO de escritura). Los adaptadores concretos
 * aportan solo el dialecto: `probePayload()` (sondeo), `normalize()`
 * (mensajes nativos -> AgentEvent) y los comandos de dominio
 * (mensaje/aprobacion/config).
 *
 * Razon de la IPC por ficheros (no pipes): el build local de PHP 8.5
 * tiene rotos los pipes de proc_open (EBADF/fd cerrado inmediato).
 */
abstract class StdioRpcAdapter implements AgentRuntimeAdapter
{
    /**
     * Estado por agente (una sola sesion RPC viva por agente en este
     * proceso: hay un SOLO lector y un SOLO escritor).
     *
     * @var array<int, array{reader: ?ProcessStreams, writer: ?ProcessStreams, dir: string, container_id: ?string, offset: int, buffer: string, lastOutChange: float, run_id: ?string}>
     */
    protected array $sessions = [];

    public function __construct(
        protected DockerClient $docker,
        protected EventSink $sink,
    ) {}

    /** Nombre corto para logs (Pi, Claude, ...). */
    abstract protected function name(): string;

    /**
     * Comando de sondeo del RPC (el bridge lo envia mientras el agente
     * esta en `starting`; la respuesta marca "ready").
     *
     * @return array<string, mixed>
     */
    abstract public function probePayload(Agent $agent): array;

    /**
     * Dialecto nativo -> eventos normalizados.
     *
     * @param  array<string, mixed>  $event
     * @return list<AgentEvent>
     */
    abstract protected function normalize(array $event, int $agentId): array;

    /** Segundos entre sondeos (el bridge lo aplica como throttle). */
    public function probeInterval(): int
    {
        return 5;
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
        $this->sessions[$agent->id] = $this->newSessionState($agent, $dir, 0);

        // El lector es `docker logs -f --tail 0`: tras el (re)attach el `out`
        // solo debe contener lineas nuevas. Se trunca para que un re-attach
        // no re-playee todo el historial; el CLI de docker reemite la ultima
        // linea al re-conectarse y asi esa duplicacion queda acotada.
        @file_put_contents($dir.'/out', '');

        // Determinismo: se matan los coprocesos previos (posibles huérfanos
        // de un bridge anterior) y se spawnean de cero.
        $session = &$this->sessions[$agent->id];
        $this->spawnCoprocesses($agent->container_name, $session);

        // Probar el RPC; el sink/bridge lo correlacionan con el sondeo.
        $this->write($agent, $this->probePayload($agent));

        Log::info(static::class.': IPC establecido con '.$agent->container_name);
    }
    public function detach(Agent $agent): void
    {
        $session = $this->sessions[$agent->id] ?? null;
        if ($session) {
            // Lo que lanzamos nosotros en este proceso...
            $session['reader']?->stop(3);
            $session['writer']?->stop(3);
            // ...y los que puedan quedar registrados en .pid (huérfanos);
            // el nombre del contenedor encaja también a los hijos
            // re-adoptados (fix-bridge-stale-attach-loop).
            $this->docker->killCoprocess($session['dir'], 'reader', $agent->container_name);
            $this->docker->killCoprocess($session['dir'], 'writer', $agent->container_name);
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

        // El proceso interno renacio (docker restart): `State.StartedAt`
        // cambio. `docker logs -f --tail 0` sigue el inode del log anterior
        // tras un restart, asi que el lector NO ve la salida nueva (ni la
        // notificacion de arranque del CLI): lector y secuencia de arranque
        // hay que re-arrancarlos. Un manual restart NO toca `RestartCount`
        // (verificado), por eso se compara la fecha.
        $container = $agent->container_name ?? null;
        if ($container !== null) {
            $now = $this->docker->startedAt($container);
            if ($now !== null && ($session['started_at'] ?? null) !== null && $now !== $session['started_at']) {
                return false;
            }
        }

        // El writer NO existe en el modo `api-events` (OpenCode: los
        // comandos van por HTTP, sin FIFO): solo se exige vivo si hay uno.
        return $this->docker->coprocessAlive($dir, 'reader', $dir)
            && ($session['writer'] === null || $this->docker->coprocessAlive($dir, 'writer', $dir));
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
            // `State.StartedAt` al attach: si cambia, el proceso interno
            // renacio y isAttached fuerza el re-attach (ver isAttached).
            'started_at' => $agent->container_name !== null
                ? $this->docker->startedAt($agent->container_name)
                : null,
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
        $this->docker->killCoprocess($session['dir'], 'reader', $containerName);
        $this->docker->killCoprocess($session['dir'], 'writer', $containerName);
        $session['reader'] = $this->docker->attachReader($containerName, $session['dir']);
        $session['writer'] = $this->docker->startWriter($containerName, $session['dir']);
    }

    protected function detachForDir(string $dir, ?string $containerName = null): void
    {
        $this->docker->killCoprocess($dir, 'reader', $containerName);
        $this->docker->killCoprocess($dir, 'writer', $containerName);
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
        // El valor del offset, no el tamaño del fichero: `in.off` guarda el
        // número en texto ("52\n" = 3 bytes) y filesize() lo leía como 3,
        // por lo que `in > inOff` era siempre cierto y el stale disparaba
        // cada 60 s por agente (bucle de re-adjuntado; fix-bridge-stale-attach-loop).
        $inOff = (int) (@file_get_contents($session['dir'].'/in.off') ?: 0);

        // [fix-bridge-stale-attach-loop 1.1/1.2] Diagnostico del disparo
        // (a debug: no debe producir churn en producción).
        Log::debug(static::class.': stale-check', [
            'agent' => $agent->id,
            'age_s' => round(microtime(true) - ($session['lastOutChange'] ?? microtime(true))),
            'in' => $inSize,
            'in_off' => $inOff,
            'in_off_exists' => file_exists($session['dir'].'/in.off'),
            'mem_status' => $agent->status->value,
            'db_status' => $agent->fresh()->status->value,
            'writer_alive' => $session['writer'] === null || $this->docker->coprocessAlive($session['dir'], 'writer', $session['dir']),
        ]);

        return $inSize > $inOff
            || $agent->status === AgentStatus::Working
            || $agent->status === AgentStatus::Starting
            || $agent->status === AgentStatus::WaitingApproval;
    }

    /** Sonda el RPC (get_state); el bridge lo llama hasta que el agente esta "ready". */
    public function probe(Agent $agent): void
    {
        if ($this->ensureAttached($agent)) {
            $this->write($agent, $this->probePayload($agent));
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
            Log::warning(static::class.': lector stale en '.$agent->container_name.'; re-adjuntando');
            $agentId = $agent->id;
            $containerName = $agent->container_name;
            $dir = $session['dir'];
            unset($this->sessions[$idx]);
            $this->detachForDir($dir, $containerName);
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
            $decoded = $this->decodeLine($line);
            if (is_array($decoded)) {
                $events = array_merge($events, $this->normalize($decoded, $agent->id));
            } else {
                Log::debug(static::class.': linea no-JSON de '.$agent->id.': '.substr($line, 0, 200));
            }
        }

        return $events;
    }

    /**
     * Decodifica una linea del `out`. Por defecto es JSON plano; el modo
     * `api-events` (OpenCode) lo sobreescribe para quitar el prefijo SSE.
     *
     * @return array<string, mixed>|null
     */
    protected function decodeLine(string $line): ?array
    {
        return json_decode($line, true);
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
     * Escribe un comando en el IPC (append + LOCK al fichero `in`; el bucle
     * writer del bridge lo entrega al FIFO / stdin de PID 1).
     *
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
     * Escribe un prompt en el IPC. Compartido por sendUserMessage /
     * writeMessage; los adaptadores añaden sus campos de cola (por ejemplo
     * el `streamingBehavior` de Pi para el follow-up en vuelo).
     *
     * @param  array<string, mixed>  $extra
     */
    protected function writePrompt(Agent $agent, string $content, array $extra = []): void
    {
        $this->write($agent, [
            'id' => Str::uuid()->toString(),
            'type' => 'prompt',
            'message' => $content,
            ...$extra,
        ]);
    }
}

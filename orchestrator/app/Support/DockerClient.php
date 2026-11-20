<?php

namespace App\Support;

use App\Models\Agent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Delgada capa de abstraccion sobre el Docker Engine (CLI `docker`).
 *
 * Se usa el CLI en vez de la SDK porque necesitamos flujos bidireccionales
 * (`docker attach`) y de streaming (`docker logs -f`) con gestiones de EOF y
 * multiplexado ya resueltas. Todas las operaciones devuelven arrays/strings
 * simples; los errores lanzan DockerException.
 */
class DockerClient
{
    public function __construct(
        protected string $bin = 'docker',
    ) {}

    /* ---------------- contenedores ---------------- */

    /**
     * Crea (no arranca) un contenedor.
     *
     * @param  array{agent_id: int, image: string, name: string, networks: list<array{name: string, ip?: ?string}>,
     *               volumes?: list<string>, env?: array<string, string>,
     *               restart?: ?string, labels?: array<string, string>,
     *               cmd?: list<string>, openStdin?: bool, tty?: bool,
     *               readonly?: bool, tmpfs?: list<string>}  $spec
     */
    public function createContainer(array $spec): string
    {
        $args = ['create',
            '--name', $spec['name'],
            '--label', 'taap.managed=1',
            '--label', 'taap.agent='.$spec['agent_id'],
        ];

        foreach ($spec['networks'] as $net) {
            $args[] = '--network';
            $args[] = $net['name'];
            if (! empty($net['ip'])) {
                $args[] = '--ip';
                $args[] = $net['ip'];
            }
        }

        foreach ($spec['volumes'] ?? [] as $volume) {
            $args[] = '--volume';
            $args[] = $volume;
        }

        foreach ($spec['env'] ?? [] as $key => $value) {
            $args[] = '--env';
            $args[] = "{$key}={$value}";
        }

        if (! empty($spec['restart'])) {
            $args[] = '--restart';
            $args[] = $spec['restart'];
        }

        foreach ($spec['ports'] ?? [] as $mapping) {
            $args[] = '--publish';
            $args[] = $mapping;
        }

        // Endurecimiento: rootfs solo lectura (los areas escribibles son
        // binds de datos o tmpfs); el contenedor no debe poder plantar nada
        // fuera de ellos (design.md: contenedores endurecidos).
        if (! empty($spec['readonly'])) {
            $args[] = '--read-only';
        }
        foreach ($spec['tmpfs'] ?? [] as $mount) {
            $args[] = '--tmpfs';
            $args[] = $mount;
        }

        foreach ($spec['labels'] ?? [] as $key => $value) {
            $args[] = '--label';
            $args[] = "{$key}={$value}";
        }

        if (! empty($spec['openStdin'])) {
            $args[] = '--interactive';
        }
        if (! empty($spec['tty'])) {
            $args[] = '--tty';
        }

        $args[] = $spec['image'];
        if (! empty($spec['cmd'])) {
            $args = array_merge($args, $spec['cmd']);
        }

        return $this->run($args, json: false);
    }

    /**
     * Puerto del host publicado para un puerto de contenedor
     * (`docker port`): `null` si no hay.
     */
    public function publishedPort(string $name, int|string $containerPort): ?string
    {
        try {
            $process = new Process([
                $this->bin, 'port', $name, (string) $containerPort,
            ]);
            $process->run();
            $output = trim($process->getOutput());
            if ($output === '') {
                return null;
            }

            // "127.0.0.1:54321" (el ultimo si hay varios)
            $line = trim(explode('
', $output)[0]);

            return substr($line, strrpos($line, ':') + 1);
        } catch (\Throwable) {
            return null;
        }
    }

    public function start(string $name): void
    {
        $this->run(['start', $name]);
    }

    public function stop(string $name, int $timeout = 10): void
    {
        Log::info("DockerClient: stop {$name}");
        $this->run(['stop', '-t', (string) $timeout, $name]);
    }

    public function restart(string $name): void
    {
        Log::info("DockerClient: restart {$name}");
        $this->run(['restart', $name]);
    }

    public function remove(string $name, bool $force = true): void
    {
        Log::info("DockerClient: rm {$name} (force=".($force ? 1 : 0).') de: '.collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6))->map(fn ($f) => ($f['class'] ?? '?').'::'.$f['function'])->implode(' <- '));
        $this->run(['rm', $force ? '-f' : null, $name], allowFail: true);
    }

    /** Estado corto del contenedor (running, exited, ...) o null si no existe. */
    public function status(string $name): ?string
    {
        try {
            $process = new Process([
                $this->bin, 'inspect', '-f', '{{.State.Status}}', $name,
            ]);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }

            return trim($process->getOutput()) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * `State.StartedAt` del contenedor: cambia en CADA arranque (manual
     * `docker restart` NO incrementa `RestartCount` — verificado), asi que es
     * la senal fiable de "el proceso interno ha renacido" (re-attach
     * obligatorio: el reader viejo sigue el inode log anterior).
     */
    public function startedAt(string $name): ?string
    {
        try {
            $process = new Process([
                $this->bin, 'inspect', '-f', '{{.State.StartedAt}}', $name,
            ]);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }

            return trim($process->getOutput()) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function inspect(string $name): ?array
    {
        try {
            $output = $this->run(['inspect', $name]);

            return json_decode($output, true);
        } catch (DockerException) {
            return null;
        }
    }

    /**
     * Contenedores gestionados por TAAP (todos los que llevan la etiqueta).
     *
     * @return list<array{name: string, id: string, agent: int|null, instance: string, state: string}>
     */
    public function managedContainers(): array
    {
        try {
            $output = $this->run([
                'ps', '-a', '--filter', 'label=taap.managed=1',
                '--format', '{{.ID}}\t{{.Names}}\t{{.Label "taap.agent"}}\t{{.Label "taap.instance"}}\t{{.State}}',
            ]);
        } catch (DockerException) {
            return [];
        }

        $result = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            [$id, $name, $agent, $instance, $state] = array_pad(explode("\t", $line), 5, '');
            $result[] = [
                'id' => $id,
                'name' => ltrim($name, '/'),
                'agent' => $agent !== '' ? (int) $agent : null,
                'instance' => $instance,
                'state' => $state,
            ];
        }

        return $result;
    }

    /**
     * Proceso `docker attach` bidireccional con el contenedor.
     * Escribir en stdin = escribir en el stdin de PID 1; leer stdout = leer
     * su stdout. (Pi: su RPC JSONL.)
     */
    /**
     * Area de IPC con el agente (host): el PHP de este build tiene los
     * pipes de proc_open rotos, asi que el trafico RPC viaja por archivos.
     */
    public function rpcDir(int $agentId): string
    {
        $base = (string) config('taap.agents.rpc_base', '/tmp/taap-rpc');
        $dir = $base.'/'.$agentId;
        File::ensureDirectoryExists($dir);

        // Solo crear si no existen: truncar en cada llamada borraria el
        // prompt pendiente (in) y el historial (out) que siguen vivos.
        foreach (['in', 'out', 'err'] as $f) {
            if (! file_exists("$dir/$f")) {
                @file_put_contents("$dir/$f", '');
            }
        }

        return $dir;
    }

    /**
     * Lector persistente: `docker logs -f --tail 0` (solo salida nueva)
     * volcada a un archivo; PHP lo sondea. logs -f no toca el stdin del
     * contenedor: si el lector muere, el agente no se cae.
     */
    /**
     * Lector persistente del stdout del contenedor. El comando interno
     * ($inner) es configurable: por defecto `docker logs -f --tail 0` (los
     * runtimes rpc-stdio); el modo `api-events` (OpenCode) pasa el bucle
     * `curl -N` del stream SSE. En ambos casos lo que entre por el stdin de
     * `sh -c` va a `$dir/out` (el adapter lo drena) y stderr a `$dir/err`.
     */
    public function attachReader(string $name, string $dir, ?string $inner = null): ProcessStreams
    {
        $inner ??= 'docker logs -f --tail 0 '.escapeshellarg($name);

        // $dir va INLINEADO (no como variable de entorno: el coproceso no la
        // hereda y la redirección `>> $dir/out` moria al spawn).
        $process = new ProcessStreams([
            'sh', '-c',
            'trap "kill $(jobs -p)" TERM INT; '.$inner.' >> '.escapeshellarg($dir.'/out').' 2>> '.escapeshellarg($dir.'/err').' & wait',
        ]);
        file_put_contents($dir.'/reader.pid', (string) $process->pid);

        return $process;
    }

    /**
     * Escritor persistente: entrega cada linea de `$dir/in` al FIFO del
     * contenedor (`/rpc/in`) via `docker exec -i`. Un bucle con offset en
     * bytes (archivo `in.off`) resiste truncaduras y reinicios: `tail -f`
     * de BSD no sigue truncaduras.
     */
    public function startWriter(string $name, string $dir): ProcessStreams
    {
        $name = escapeshellarg($name);
        $script = <<<SH
off=\$(cat {$dir}/in.off 2>/dev/null || echo 0)
while :; do
  size=\$(wc -c < {$dir}/in 2>/dev/null | tr -d " ")
  if [ "\$size" -lt "\$off" ]; then off=0; fi
  if [ "\$size" -gt "\$off" ]; then
    tail -c +\$((off+1)) {$dir}/in | while IFS= read -r line; do
      [ -n "\$line" ] && printf "%s\\n" "\$line" | docker exec -i {$name} sh -c 'cat > /rpc/in' || true
    done
    off=\$size
    echo "\$off" > {$dir}/in.off
  fi
  sleep 0.2
done
SH;
        $process = new ProcessStreams([
            'sh', '-c',
            'trap "kill \$(jobs -p)" TERM INT; '.$script.' & wait',
        ]);
        file_put_contents($dir.'/writer.pid', (string) $process->pid);

        return $process;
    }

    /** Mata el coproceso registrado en .pid (si existe). */
    /**
     * Mata el coproceso registrado en `{dir}/{which}.pid` (y cualquier
     * huérfano cuyo cmdline mencione la carpeta).
     */
    public function killCoprocess(string $dir, string $which): void
    {
        $file = $dir.'/'.$which.'.pid';
        if (is_file($file)) {
            $pid = (int) trim((string) file_get_contents($file));
            $alive = $this->pidAlive($pid);
            if ($alive) {
                @posix_kill($pid, SIGTERM);
                // dar un momento a cerrar ficheros
                for ($i = 0; $i < 10 && $alive; $i++) {
                    usleep(100_000);
                    $alive = $this->pidAlive($pid);
                }
                if ($alive) {
                    @posix_kill($pid, SIGKILL);
                }
            }
            @unlink($file);
        }
        $this->killOrphans($dir);
    }

    /**
     * El pid sigue vivo? (probe de señal 0; el posix puede no estar).
     */
    protected function pidAlive(int $pid): bool
    {
        return $pid > 0 && extension_loaded('posix') && @posix_kill($pid, 0);
    }

    /**
     * Huérfanos: procesos cuyo cmdline menciona la carpeta rpc del agente
     * (writers/lectores no registrados en el .pid, por reinicios a mitad de
     * ciclo, etc.).
     */
    protected function killOrphans(string $dir): void
    {
        if (! extension_loaded('posix') || ! is_dir('/proc')) {
            return;
        }
        foreach (glob('/proc/[0-9]*') ?: [] as $proc) {
            $cmd = str_replace("\0", ' ', (string) @file_get_contents($proc.'/cmdline'));
            if ($cmd !== '' && str_contains($cmd, $dir)) {
                @posix_kill((int) basename($proc), SIGKILL);
            }
        }
    }

    /**
     * Coproceso vivo? (archivo .pid + probe de señal 0 + firma en el
     * cmdline para evitar falsos vivos por reutilización de PID/zombies).
     */
    public function coprocessAlive(string $dir, string $which, ?string $signature = null): bool
    {
        $file = $dir.'/'.$which.'.pid';
        if (! is_file($file)) {
            return false;
        }
        $pid = (int) trim((string) file_get_contents($file));

        if ($pid <= 0 || ! extension_loaded('posix') || ! @posix_kill($pid, 0)) {
            return false;
        }

        if ($signature !== null) {
            $cmdPath = "/proc/{$pid}/cmdline";
            // Sin /proc (macOS local) la firma no se puede verificar: se
            // acepta la vida del PID. En Linux/CI la firma sigue filtrando
            // reutilizaciones de PID y zombies.
            if (is_file($cmdPath)) {
                $cmd = str_replace("\0", ' ', (string) @file_get_contents($cmdPath));
                if ($cmd !== '' && ! str_contains($cmd, $signature)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Proceso `docker logs -f` (eventos/logs del contenedor). */
    public function logsFollowProcess(string $name): Process
    {
        $process = new Process([$this->bin, 'logs', '-f', $name]);
        $process->setTimeout(null);

        return $process;
    }

    public function logs(string $name, int $tail = 100): string
    {
        return $this->run(['logs', '--tail', (string) $tail, $name], allowFail: true);
    }

    /**
     * @param  list<string>  $command
     */
    public function exec(string $name, array $command, bool $tty = false): Process
    {
        $args = ['exec'];
        if ($tty) {
            $args[] = '-t';
        } else {
            $args[] = '-i';
        }
        $process = new Process(array_merge($args, [$name, ...$command]));
        $process->setTimeout(120);

        return $process;
    }

    /* ---------------- volúmenes / redes / imagenes ---------------- */

    public function volumeCreate(string $name): void
    {
        $this->run(['volume', 'create', $name], allowFail: true);
    }

    public function volumeRemove(string $name): void
    {
        $this->run(['volume', 'rm', $name], allowFail: true);
    }

    public function imagePull(string $image): void
    {
        $this->run(['pull', $image]);
    }

    public function imageExists(string $image): bool
    {
        try {
            $this->run(['image', 'inspect', $image]);

            return true;
        } catch (DockerException) {
            return false;
        }
    }

    /**
     * IP libre dentro de una red (inspecciona la red y elige la primera libre
     * a partir de la base de la subnet configurada).
     */
    public function allocateIp(string $networkName): ?string
    {
        $info = $this->run([
            'network', 'inspect', $networkName,
            '--format', '{{range .IPAM.Config}}{{.Subnet}}{{end}}',
        ], json: false);
        $subnet = trim($info);
        if ($subnet === '' || ! str_contains($subnet, '/')) {
            return null;
        }

        [$base] = explode('/', $subnet);
        $octets = explode('.', $base);
        $last = (int) array_pop($octets);
        $prefix = implode('.', $octets);
        $mask = (int) explode('/', $subnet)[1];
        $size = 32 - $mask;
        $count = min(2 ** $size - 2, 250); // margen: gateway y redes pequeñas

        // IPs ya en uso en la red
        $inUse = $this->usedIps($networkName);

        for ($i = 2; $i < $count; $i++) {
            $candidate = "{$prefix}.".($last + $i);
            if (! in_array($candidate, $inUse, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function usedIps(string $networkName): array
    {
        try {
            $nl = chr(10);
            $output = $this->run([
                'network', 'inspect', $networkName,
                '--format', '{{range $k, $v := .Containers}}{{$v.IPv4Address}}'.$nl.'{{end}}',
            ], json: false);
        } catch (DockerException) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($ip) => explode('/', trim($ip))[0], preg_split('/\R/', $output) ?: [])));
    }

    /* ---------------- base ---------------- */

    /**
     * @param  list<string|null>  $args
     */
    protected function run(array $args, bool $json = true, bool $allowFail = false): string
    {
        $args = array_filter($args, fn ($a) => $a !== null);
        $process = new Process(array_merge([$this->bin], $args));
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful() && ! $allowFail) {
            throw new DockerException(sprintf(
                'docker %s falló (rc=%d): %s',
                implode(' ', array_slice($args, 0, 3)),
                $process->getExitCode(),
                trim(substr($process->getErrorOutput(), 0, 500)),
            ));
        }

        return $process->getOutput();
    }
}

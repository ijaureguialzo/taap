<?php

namespace App\Support;

use RuntimeException;

/**
 * Proceso con pipes directamente accesibles (proc_open).
 *
 * Symfony Process 8 no expone el stdin de un proceso en marcha; para un
 * daemon que debe ESCRIBIRLE al stdio de un agente (RPC de Pi) necesitamos
 * los handles crudos.
 */
class ProcessStreams
{
    /** @var resource|null pipe de escritura (stdin del proceso) */
    public $stdin;

    /** @var resource|null pipe de lectura (stdout del proceso) */
    public $stdout;

    /** @var resource|null pipe de lectura (stderr del proceso) */
    public $stderr;

    public int $pid;

    /**
     * @var resource proceso de proc_open (sigue vivo hasta cerrar los pipes)
     */
    protected $proc;

    protected ?bool $running = null;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>|null  $env
     */
    public function __construct(array $command, ?array $env = null, ?string $cwd = null)
    {
        $spec = [
            0 => ['pipe', 'w'],
            1 => ['pipe', 'r'],
            2 => ['pipe', 'r'],
        ];

        $proc = proc_open($command, $spec, $pipes, $cwd, $env ?? null);

        if (! is_resource($proc)) {
            throw new RuntimeException('proc_open falló para: '.implode(' ', $command));
        }

        $this->proc = $proc;
        $this->pid = proc_get_status($proc)['pid'];
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
    }

    public function isRunning(): bool
    {
        if ($this->running === false) {
            return false;
        }

        $status = @proc_get_status($this->proc);
        $this->running = $status['running'];

        if (! $this->running) {
            $this->close();
        }

        return $this->running;
    }

    public function write(string $data): bool
    {
        return $this->stdin !== null && fwrite($this->stdin, $data) !== false;
    }

    public function stop(int $timeoutSeconds = 5): void
    {
        if (! $this->isRunning()) {
            return;
        }

        posix_kill($this->pid, SIGTERM);

        $deadline = microtime(true) + $timeoutSeconds;
        do {
            usleep(50_000);
            $status = @proc_get_status($this->proc);
        } while (microtime(true) < $deadline && $status['running']);

        if ($status['running']) {
            posix_kill($this->pid, SIGKILL);
        }

        $this->running = false;
        $this->close();
    }

    protected function close(): void
    {
        foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->stdin = $this->stdout = $this->stderr = null;
    }
}

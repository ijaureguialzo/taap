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

    protected $proc;

    protected ?bool $running = null;

    public function __construct(array $command, ?array $env = null, ?string $cwd = null)
    {
        $spec = [
            0 => ['pipe', 'w'],
            1 => ['pipe', 'r'],
            2 => ['pipe', 'r'],
        ];

        $this->proc = proc_open($command, $spec, $pipes, $cwd, $env ?? null);

        if (! is_resource($this->proc)) {
            throw new RuntimeException('proc_open falló para: ' . implode(' ', $command));
        }

        $this->pid = proc_get_status($this->proc)['pid'];
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
        $this->running = $status !== false && $status['running'];

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
        while (microtime(true) < $deadline && $this->isRunning()) {
            usleep(50_000);
        }

        if ($this->isRunning()) {
            posix_kill($this->pid, SIGKILL);
            $this->isRunning();
        }

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

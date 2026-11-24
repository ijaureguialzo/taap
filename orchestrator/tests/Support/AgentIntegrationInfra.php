<?php

namespace Tests\Support;

use App\Agents\AgentEvent;
use App\Agents\AgentManager;
use App\Agents\EventSink;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Infraestructura compartida de la integracion extremo a extremo de los
 * runtimes de agente (fix-bridge-cross-process-seams 4.x): contenedor real
 * del agente + gateway sintético + Model Proxy + `php -S` dentro de
 * taap-orchestrator + sqlite de test.
 *
 * Extraida de {Codex,OpenCode}RuntimeIntegrationTest para que los tests de
 * costura cross-proceso (dos instancias de adapter sobre el MISMO area de
 * ficheros) no dupliquen ~200 lineas de docker.
 */
trait AgentIntegrationInfra
{
    use RequiresDocker;

    protected ?Agent $agent = null;

    protected EventSink $sink;

    /** @var list<array{type: string, payload: array, at: string}> */
    public array $events = [];

    protected string $base;

    protected string $dbFile;

    protected string $servePort;

    protected string $gatewayName;

    protected string $gatewayPort;

    protected string $gatewayLog;

    /** @var list<string> */
    protected array $tempContainers = [];

    /** Opciones de infra: image, gateway, runtime, extra config. */
    protected function infra(array $opts): void
    {
        $this->skipUnlessDocker([$opts['image']]);
        if (! $this->dockerContainerExists('taap-orchestrator')) {
            $this->markTestSkipped('No hay contenedor taap-orchestrator (deploy local)');
        }

        $this->base = (string) config('taap.agents.data_base').'/taap-int-'.uniqid();
        File::ensureDirectoryExists($this->base);
        chmod($this->base, 0777); // el gateway (uid 1001) escribe su log aqui

        $this->dbFile = $this->base.'/test.sqlite';
        @unlink($this->dbFile);
        @unlink($this->dbFile.'-wal');
        @unlink($this->dbFile.'-shm');

        $this->servePort = (string) (18000 + random_int(1, 20000));
        $this->gatewayName = $opts['gatewayPrefix'].'-'.uniqid('', false);
        $this->gatewayPort = $opts['gatewayPort'];
        $this->gatewayLog = $this->base.'/gateway-reqs.jsonl';
        @file_put_contents($this->gatewayLog, '');
        chmod($this->gatewayLog, 0666);

        config([
            'broadcasting.default' => 'null',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->dbFile,
            'database.connections.sqlite.journal_mode' => 'delete',
            'taap.proxy.internal_url' => 'http://taap-orchestrator:'.$this->servePort,
            'taap.agents.data_base' => $this->base,
            'taap.agents.data_host_base' => $this->base,
            'taap.agents.rpc_base' => $this->base.'/rpc',
            ...($opts['config'] ?? []),
        ]);

        Artisan::call('migrate:fresh', ['--force' => true]);
        Queue::fake();

        $this->startGateway($opts);

        $this->copyDbToOrchestrator();
        $this->startServeInOrchestrator();
        $this->waitForHttpInOrchestrator('http://127.0.0.1:'.$this->servePort.'/up', 30, false);
        $this->waitForHttpInOrchestrator('http://'.$this->gatewayName.':'.$this->gatewayPort.'/api/hello', 20);

        // Sink que registra en $this->events ANTES de procesar: asi el test
        // ve los eventos de las dos instancias de adapter (worker y bridge),
        // que comparten este objeto.
        $this->sink = new class($this) extends EventSink
        {
            public function __construct(private object $test) {}

            public function dispatch(Agent $agent, AgentEvent $event): void
            {
                $this->test->events[] = $event->toArray();
                parent::dispatch($agent, $event);
            }
        };
        $this->app->instance(EventSink::class, $this->sink);
    }

    protected function infraTeardown(): void
    {
        try {
            Agent::query()->get()->each(function (Agent $a) {
                try {
                    app(AgentManager::class)->terminate($a);
                } catch (\Throwable) {
                    // ya no existe
                }
            });
            if ($this->agent !== null) {
                app(\App\Agents\RuntimeAdapters::class)->for($this->agent->refresh())->detach($this->agent->refresh());
            }
        } catch (\Throwable) {
            // sin agente (el test puede fallar antes)
        }

        $this->stopServeInOrchestrator();

        foreach ($this->tempContainers as $name) {
            $p = new SymfonyProcess(['docker', 'rm', '-f', $name]);
            $p->setTimeout(20);
            $p->run();
        }

        if (isset($this->base) && is_dir($this->base) && ! env('TAAP_INT_DEBUG')) {
            File::deleteDirectory($this->base);
        }
    }

    protected function dockerContainerExists(string $name): bool
    {
        $p = new SymfonyProcess(['docker', 'container', 'inspect', $name]);
        $p->run();

        return $p->isSuccessful();
    }

    protected function startGateway(array $opts): void
    {
        $p = new SymfonyProcess([
            'docker', 'run', '-d',
            '--name', $this->gatewayName,
            '--network', (string) config('taap.agents.network_internal'),
            '-v', $this->base.':/gw',
            '-v', __DIR__.'/../Support/'.$opts['gatewayMjs'].':/gw/gateway.mjs:ro',
            '--entrypoint', 'node',
            $opts['image'],
            '/gw/gateway.mjs', $this->gatewayPort, '/gw/gateway-reqs.jsonl',
        ]);
        $p->run();
        $this->tempContainers[] = $this->gatewayName;

        $this->assertTrue($p->isSuccessful(), 'docker run gateway: '.$p->getErrorOutput());
    }

    protected function startServeInOrchestrator(): void
    {
        $router = '/app/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
        $env = collect([
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'APP_DEBUG' => 'true',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/tmp/taap-int-test.sqlite',
            'BROADCAST_CONNECTION' => 'null',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'LOG_CHANNEL' => 'single',
            'LOG_FILE' => '/tmp/taap-int-laravel.log',
        ])->map(fn ($v, $k) => escapeshellarg($k).'='.escapeshellarg((string) $v))->implode(' ');

        $cmd = 'cd /app/public && exec env '.$env." php -S 0.0.0.0:{$this->servePort} {$router} > /tmp/taap-int-serve.log 2>&1";

        $p = new SymfonyProcess(['docker', 'exec', '-d', 'taap-orchestrator', 'sh', '-c', $cmd]);
        $p->run();
    }

    protected function stopServeInOrchestrator(): void
    {
        $killer = 'for d in /proc/[0-9]*; do if grep -q "php -S 0.0.0.0:'.$this->servePort.'" $d/cmdline 2>/dev/null; then kill $(basename $d) 2>/dev/null; fi; done; true';
        $p = new SymfonyProcess(['docker', 'exec', 'taap-orchestrator', 'sh', '-c', $killer]);
        $p->setTimeout(15);
        $p->run();
    }

    protected function waitForHttpInOrchestrator(string $url, int $seconds, bool $json = true): void
    {
        $script = '$c = @stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 2]]); '
            .'$r = @file_get_contents('.$this->phpStr($url).', false, $c); '
            .'$ok = $r !== false && ('.($json ? 'trim(substr($r, 0, 1)) === "{"' : '$r !== false').'); '
            .'exit($ok ? 0 : 1);';
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $p = new SymfonyProcess(['docker', 'exec', 'taap-orchestrator', 'php', '-r', $script]);
            $p->setTimeout(10);
            $p->run();
            if ($p->isSuccessful()) {
                return;
            }
            usleep(250_000);
        }
        $this->fail("{$url} no responde dentro de taap-orchestrator en {$seconds}s");
    }

    protected function phpStr(string $s): string
    {
        return "'".addcslashes($s, "'\\")."'";
    }

    protected function copyDbToOrchestrator(): void
    {
        @DB::select('PRAGMA wal_checkpoint(TRUNCATE);');
        (new SymfonyProcess(['docker', 'cp', $this->dbFile, 'taap-orchestrator:/tmp/taap-int-test.sqlite']))->run();
    }

    protected function gatewayRequests(): array
    {
        if (! $this->gatewayPort) {
            return [];
        }

        $raw = @file_get_contents(sprintf('http://127.0.0.1:%d/__requests', $this->gatewayPort));
        $data = $raw === false ? null : json_decode((string) $raw, true);

        if (is_array($data)) {
            return $data;
        }

        $p = new SymfonyProcess(['docker', 'exec', $this->gatewayName, 'cat', '/gw/gateway-reqs.jsonl']);
        $p->setTimeout(15);
        $p->run();

        return array_values(array_filter(array_map(
            fn (string $line) => json_decode($line, true),
            preg_split('/\r?\n/', $p->getOutput()) ?: [],
        ), fn ($o) => $o !== null));
    }

    /**
     * Mini-bridge: drena el adapter dado hasta que $until($agent, $events)
     * sea verdadero. Es el loop de taap:bridge en miniatura (attach si hace
     * falta + drain + dispatch al sink + probe si esta starting).
     */
    protected function pump(
        \App\Agents\AgentRuntimeAdapter $adapter,
        Agent $agent,
        \Closure $until,
        int $timeout = 120,
        float $tick = 0.25,
    ): Agent {
        $deadline = microtime(true) + $timeout;
        $lastProbe = 0;
        $agent = $agent->refresh();

        while (microtime(true) < $deadline) {
            if (! $adapter->isAttached($agent)) {
                $state = app(\App\Support\DockerClient::class)->status($agent->container_name ?? '');
                if ($state === 'running') {
                    $adapter->attach($agent);
                }
            }

            foreach ($adapter->drain($agent) as $event) {
                $this->sink->dispatch($agent->refresh(), $event);
            }

            $agent = $agent->refresh();

            if ($agent->status === AgentStatus::Starting
                && microtime(true) - $lastProbe > $adapter->probeInterval()) {
                $lastProbe = microtime(true);
                $adapter->probe($agent);
            }

            if ($until($agent, $this->events)) {
                return $agent;
            }

            usleep((int) ($tick * 1_000_000));
        }

        return $agent;
    }

    protected function eventTypes(): array
    {
        return array_map(fn (array $e) => $e['type'], $this->events);
    }

    protected function eventsOfType(string $type): array
    {
        return array_values(array_filter($this->events, fn (array $e) => $e['type'] === $type));
    }

    protected function lastOfType(string $type): ?array
    {
        $list = $this->eventsOfType($type);

        return $list === [] ? null : $list[count($list) - 1];
    }

    /* ── fixtures ── */

    protected function user(): User
    {
        return User::factory()->create();
    }

    protected function providerOpenaiGateway(User $user): ModelProvider
    {
        return ModelProvider::factory()->create([
            'name' => 'gateway',
            'type' => 'openai',
            'base_url' => 'http://'.$this->gatewayName.':'.$this->gatewayPort,
            'created_by' => $user->id,
        ]);
    }

    protected function modelFor(ModelProvider $provider, string $modelId, string $name): Model
    {
        return Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => $modelId,
            'name' => $name,
        ]);
    }

    protected function makeAgent(User $user, Model $model, string $name, string $runtime, string $image, array $config = []): Agent
    {
        $agent = Agent::factory()->create([
            'name' => $name,
            'owner_id' => $user->id,
            'runtime' => $runtime,
            'image' => $image,
            'model_id' => $model->id,
            'internet_enabled' => false,
            'status' => AgentStatus::Offline,
            'config' => $config,
        ]);

        app(AgentManager::class)->provision($agent);
        $this->agent = $agent;

        $this->copyDbToOrchestrator();

        return $agent->refresh();
    }
}

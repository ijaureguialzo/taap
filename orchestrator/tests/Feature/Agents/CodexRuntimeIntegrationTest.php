<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentEvent;
use App\Agents\AgentManager;
use App\Agents\AgentRuntimeAdapter;
use App\Agents\EventSink;
use App\Agents\RuntimeAdapters;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\ChatMessage;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use App\Support\DockerClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\RequiresDocker;
use Tests\TestCase;

/**
 * Integracion extremo a extremo del runtime Codex (add-codex-runtime 4.1/4.2):
 * contenedor real (taap/agent-codex:local) + `codex app-server` 0.171.0 +
 * Model Proxy (wire responses) + gateway sintético del wire Responses.
 * Se SALTA sin Docker o sin la imagen; en CI (con servicio Docker) es la
 * prueba de aceptacion del cambio.
 *
 * Infraestructura idéntica a la de ClaudeRuntimeIntegrationTest (todo dentro
 * de la VM de Docker; la app se sirve dentro de taap-orchestrator y el
 * gateway corre en un contenedor node de la red taap-internal; el gateway
 * reusa la imagen Codex que lleva node 24).
 *
 * 4.1: provision -> turno con aprobacion (allow) -> denegacion -> reinicio
 *      -> thread/resume con contexto intacto (el gateway ve crecer el input).
 * 4.2: proveedor caido -> evento `error`; aprobacion con timeout -> el agente
 *      no se queda colgado (el adapter la resuelve con cancel).
 */
class CodexRuntimeIntegrationTest extends TestCase
{
    use RequiresDocker;

    protected Agent $agent;

    protected EventSink $sink;

    protected AgentRuntimeAdapter $adapter;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessDocker(['taap/agent-codex:local']);
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
        $this->gatewayName = 'taap-int-codex-gw-'.uniqid('', false);
        $this->gatewayPort = '8902';
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
        ]);

        Artisan::call('migrate:fresh', ['--force' => true]);
        Queue::fake();

        $this->startGateway();
        $this->copyDbToOrchestrator();
        $this->startServeInOrchestrator();
        $this->waitForHttpInOrchestrator('http://127.0.0.1:'.$this->servePort.'/up', 30, false);
        $this->waitForHttpInOrchestrator('http://'.$this->gatewayName.':'.$this->gatewayPort.'/api/hello', 20);

        // Sink que registra en $this->events ANTES de procesar: asi el test
        // ve tambien los eventos que el adapter despacha internamente (p.ej.
        // el approvalResolved del timeout), no solo los del drain.
        $this->sink = new class($this) extends EventSink
        {
            public function __construct(private object $test) {}

            public function dispatch(Agent $agent, AgentEvent $event): void
            {
                $this->test->events[] = $event->toArray();
                parent::dispatch($agent, $event);
            }
        };
        // El adapter recibe el sink por inyeccion: hay que sustituir la
        // singleton ANTES de resolver el adapter para que los eventos que
        // despacha internamente (p.ej. approvalResolved del timeout) tambien
        // queden registrados.
        $this->app->instance(EventSink::class, $this->sink);
        $this->adapter = app(RuntimeAdapters::class)->for(new Agent(['runtime' => 'codex']));
    }

    protected function tearDown(): void
    {
        if (env('TAAP_INT_DEBUG')) {
            $list = new SymfonyProcess(['docker', 'ps', '-a', '--filter', 'name=taap-agent-', '--format', '{{.Names}}']);
            $list->setTimeout(15);
            $list->run();
            foreach (array_filter(array_map('trim', explode("\n", $list->getOutput()))) as $name) {
                $logs = new SymfonyProcess(['docker', 'logs', '--tail', '80', $name]);
                $logs->setTimeout(15);
                $logs->run();
                fwrite(STDERR, "=== AGENT LOG {$name} ===\n".rtrim($logs->getOutput()).rtrim($logs->getErrorOutput())."\n");
                $args = new SymfonyProcess(['docker', 'exec', $name, 'cat', '/config/codex-args.env']);
                $args->setTimeout(15);
                $args->run();
                fwrite(STDERR, "=== AGENT ARGS {$name} ===\n".rtrim($args->getOutput())."\n");
            }
        }

        try {
            Agent::query()->get()->each(function (Agent $a) {
                try {
                    app(AgentManager::class)->terminate($a);
                } catch (\Throwable) {
                    // ya no existe
                }
            });
            if (isset($this->adapter, $this->agent)) {
                $this->adapter->detach($this->agent);
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

        parent::tearDown();
    }

    /* ================= infraestructura de soporte ================= */

    protected function dockerContainerExists(string $name): bool
    {
        $p = new SymfonyProcess(['docker', 'container', 'inspect', $name]);
        $p->run();

        return $p->isSuccessful();
    }

    protected function startGateway(): void
    {
        // La imagen Codex lleva node 24: sirve para el gateway (no hace falta
        // la imagen Claude para esta suite).
        $p = new SymfonyProcess([
            'docker', 'run', '-d',
            '--name', $this->gatewayName,
            '--network', (string) config('taap.agents.network_internal'),
            '-v', $this->base.':/gw',
            '-v', __DIR__.'/../../Support/gateway-responses.mjs:/gw/gateway.mjs:ro',
            '--entrypoint', 'node',
            'taap/agent-codex:local',
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

    /* ================= fixtures ================= */

    protected function user(): User
    {
        return User::factory()->create();
    }

    /**
     * Proveedor "OpenAI" apuntando al gateway (el proxy hace passthrough;
     * codex habla el wire Responses nativo).
     */
    protected function openaiProvider(User $user): ModelProvider
    {
        return ModelProvider::factory()->create([
            'name' => 'gateway',
            'type' => 'openai',
            'base_url' => 'http://'.$this->gatewayName.':'.$this->gatewayPort,
            'created_by' => $user->id,
        ]);
    }

    protected function model(ModelProvider $provider, string $modelId = 'gpt-codex-int'): Model
    {
        return Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => $modelId,
            'name' => 'Codex Int',
        ]);
    }

    protected function makeAgent(User $user, Model $model, string $name, array $config = []): Agent
    {
        $agent = Agent::factory()->create([
            'name' => $name,
            'owner_id' => $user->id,
            'runtime' => 'codex',
            'image' => 'taap/agent-codex:local',
            'model_id' => $model->id,
            'internet_enabled' => false,
            'status' => AgentStatus::Offline,
            'config' => $config,
        ]);

        app(AgentManager::class)->provision($agent);

        $this->copyDbToOrchestrator();

        return $agent->refresh();
    }

    protected function copyDbToOrchestrator(): void
    {
        @DB::select('PRAGMA wal_checkpoint(TRUNCATE);');
        (new SymfonyProcess(['docker', 'cp', $this->dbFile, 'taap-orchestrator:/tmp/taap-int-test.sqlite']))->run();
    }

    /** Requests registrados por el gateway (vía docker exec: virtiofs). */
    /**
     * Peticiones que el gateway ha recibido. Fuente: el endpoint /__requests
     * del gateway (memoria, determinista) — el JSONL va por virtiofs y las
     * lecturas desde fuera pueden ver pagina cache inconsistente (QEMU).
     */
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

        // Fallback: fichero (por virtiofs, flaco — pero mejor que nada).
        $p = new SymfonyProcess(['docker', 'exec', $this->gatewayName, 'cat', '/gw/gateway-reqs.jsonl']);
        $p->setTimeout(15);
        $p->run();

        return array_values(array_filter(array_map(
            fn (string $line) => json_decode($line, true),
            preg_split('/\r?\n/', $p->getOutput()) ?: [],
        ), fn ($o) => $o !== null));
    }

    /* ================= mini-bridge ================= */

    protected function pump(
        Agent $agent,
        \Closure $until,
        int $timeout = 120,
        float $tick = 0.25,
    ): Agent {
        $deadline = microtime(true) + $timeout;
        $lastProbe = 0;
        $agent = $agent->refresh();

        while (microtime(true) < $deadline) {
            if (! $this->adapter->isAttached($agent)) {
                $state = app(DockerClient::class)->status($agent->container_name ?? '');
                if ($state === 'running') {
                    $this->adapter->attach($agent);
                }
            }

            // El sink (decorado en setUp) registra en $this->events.
            foreach ($this->adapter->drain($agent) as $event) {
                $this->sink->dispatch($agent->refresh(), $event);
            }

            $agent = $agent->refresh();

            if ($agent->status === AgentStatus::Starting
                && microtime(true) - $lastProbe > $this->adapter->probeInterval()) {
                $lastProbe = microtime(true);
                $this->adapter->probe($agent);
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

    /**
     * Resuelve la aprobacion por el adapter y registra el evento en la lista
     * del test (el dispatch lo hace el adapter, no el drain: sin el wrapper
     * no apareceria en $this->events).
     */
    protected function resolveApproval(Agent $agent, string $approvalId, bool $approved, ?string $note = null): void
    {
        $this->adapter->resolveApproval($agent, $approvalId, $approved, $note);
    }

    /* ================= 4.1: flujo completo ================= */

    public function test_full_flow_with_approval_restart_and_resume(): void
    {
        $user = $this->user();
        $provider = $this->openaiProvider($user);
        $model = $this->model($provider);

        $this->agent = $this->makeAgent($user, $model, 'codex-int');

        /* -- arrancado: probe hasta ready -- */
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 180);
        $this->assertSame('idle', $this->agent->refresh()->status->value, 'no llego a ready: '.json_encode($this->events));

        /* -- turno 1: exec_command -> aprobacion -> allow -- */
        $this->adapter->writeMessage($this->agent->refresh(), 'Ejecuta en bash: echo ping');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::WaitingApproval, 120);
        $approval = $this->eventsOfType('approval.request');
        $this->assertNotEmpty($approval, 'no llego la aprobacion: '.json_encode($this->eventTypes()));
        $this->assertStringContainsString('echo ping', (string) $approval[0]['payload']['prompt']);

        $this->resolveApproval($this->agent->refresh(), $approval[0]['payload']['id'], true, 'once');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && $this->eventsOfType('run.end') !== [], 120);

        $types = $this->eventTypes();
        foreach (['run.start', 'message.delta', 'tool.result', 'message.final', 'run.end', 'approval.resolved'] as $t) {
            $this->assertContains($t, $types, "falta {$t}: ".json_encode($types));
        }
        $this->assertTrue($this->eventsOfType('approval.resolved')[0]['payload']['approved']);

        // El mensaje final persistio en el chat
        $this->assertNotNull(ChatMessage::where('agent_id', $this->agent->id)->where('author', 'agent')->first());

        /* -- turno 2: exec_command -> denegacion -- */
        $this->adapter->writeMessage($this->agent->refresh(), 'Ejecuta en bash: echo denegado');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::WaitingApproval, 120);
        $approval2 = $this->eventsOfType('approval.request');
        $this->assertNotEmpty($approval2);

        $this->resolveApproval($this->agent->refresh(), $approval2[count($approval2) - 1]['payload']['id'], false, 'deny');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && count($this->eventsOfType('run.end')) >= 2, 120);

        $this->assertCount(2, $this->eventsOfType('run.end'));
        $resolved2 = $this->eventsOfType('approval.resolved');
        $this->assertFalse((bool) $resolved2[count($resolved2) - 1]['payload']['approved'], 'el deny se registro');
        // Tras la denegacion el agente continuo y produjo su cierre.
        $this->assertNotSame('', (string) $this->eventsOfType('message.final')[1]['payload']['content']);

        /* -- reinicio del contenedor -> thread/resume con contexto intacto -- */
        $p = new SymfonyProcess(['docker', 'restart', $this->agent->container_name]);
        $p->setTimeout(180);
        $p->run();
        $this->assertTrue($p->isSuccessful(), 'docker restart: '.$p->getErrorOutput());

        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 180);
        $this->assertSame('idle', $this->agent->refresh()->status->value, 'no recuperado tras restart: '.json_encode($this->events));

        $before = $this->gatewayRequests();
        $this->adapter->writeMessage($this->agent->refresh(), 'Dame un recordatorio');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && count($this->eventsOfType('run.end')) >= 3, 120);

        $requests = $this->gatewayRequests();
        $this->assertGreaterThan(count($before), count($requests));
        $last = $requests[count($requests) - 1];

        // Evidencia del resume: el request post-reinicio lleva el historial de
        // los turnos anteriores. Cota inferior (forma del test Claude): un
        // hilo con 2 turnos + 2 exec reenvia TODA la historia (spike: 19
        // items); >= 6 lo prueba sin depender de una lectura previa flaca.
        $this->assertGreaterThanOrEqual(6, (int) $last['nItems'], 'el hilo no llevo historial: '.json_encode($last));
        $this->assertStringContainsString('Bearer', (string) $last['auth'], 'el proxy presento el token de proveedor');
        $this->assertSame('gpt-codex-int', $last['model']);
    }

    /* ================= 4.2: fallos ================= */

    public function test_provider_failure_surfaces_error_event(): void
    {
        $user = $this->user();

        // Host que no existe en la VM: el upstream cae (fallo de DNS) y el
        // proxy devuelve 502 al app-server.
        $dead = ModelProvider::factory()->create([
            'name' => 'caido',
            'type' => 'openai',
            'base_url' => 'http://'.(string) config('taap.agents.network_internal').'-inexistente:'.$this->gatewayPort,
            'created_by' => $user->id,
        ]);
        $model = $this->model($dead);

        // max_retries: sin tope, codex reintenta con backoff exponencial
        // durante varios minutos contra un proveedor caido; con 1 el fallo
        // llega en segundos.
        $this->agent = $this->makeAgent($user, $model, 'codex-fail', ['max_retries' => 1]);

        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 180);
        $this->assertSame('idle', $this->agent->refresh()->status->value, 'no llego a ready: '.json_encode($this->events));

        $this->adapter->writeMessage($this->agent->refresh(), 'hola');
        $this->pump($this->agent, fn (Agent $a, array $ev) => $this->eventsOfType('error') !== [] || $a->status === AgentStatus::Error, 120);

        // El app-server expuso el fallo (evento error o run.end con error).
        $hasError = $this->eventsOfType('error') !== []
            || collect($this->eventsOfType('run.end'))->contains(fn (array $e) => ! $e['payload']['ok']);
        $this->assertTrue($hasError, 'debia llegar un error: '.json_encode($this->eventTypes()));
        // Y el agente no queda colgado: vuelve a un estado estable.
        $this->assertContains($this->agent->refresh()->status->value, ['idle', 'error']);
    }

    public function test_approval_timeout_denies_and_turn_continues(): void
    {
        $user = $this->user();
        $provider = $this->openaiProvider($user);
        $model = $this->model($provider);

        // approval_timeout_seconds: 3 (en produccion son 1800): el app-server
        // NO tiene timeout propio; el adapter lo implementa (drain) y la
        // aprobacion caducada se resuelve con cancel.
        $this->agent = $this->makeAgent($user, $model, 'codex-timeout', ['approval_timeout_seconds' => 3]);
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 180);
        $this->assertSame('idle', $this->agent->refresh()->status->value, 'no llego a ready: '.json_encode($this->events));

        $this->adapter->writeMessage($this->agent->refresh(), 'Ejecuta en bash: echo tardio');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && $this->eventsOfType('run.end') !== [], 120);

        // La aprobacion se solicito, nadie la resolvio y el timeout la denego.
        $this->assertNotEmpty($this->eventsOfType('approval.request'), 'no se solicito la aprobacion: '.json_encode($this->eventTypes()));
        $resolved = $this->eventsOfType('approval.resolved');
        $this->assertNotEmpty($resolved, 'el timeout debio resolver la aprobacion');
        $this->assertFalse((bool) $resolved[count($resolved) - 1]['payload']['approved'], 'el timeout deniega');
        $this->assertSame('timeout', $resolved[count($resolved) - 1]['payload']['note'] ?? null);

        // El agente continuo (el tool reporto la denegacion al modelo) y el
        // turno termino: no se quedo colgado.
        $this->assertNotEmpty($this->eventsOfType('run.end'));
        $this->assertSame('idle', $this->agent->refresh()->status->value);
    }
}

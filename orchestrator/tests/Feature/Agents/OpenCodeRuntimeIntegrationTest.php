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
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\RequiresDocker;
use Tests\TestCase;

/**
 * Integracion extremo a extremo del runtime OpenCode (add-opencode-runtime
 * 4.1/4.2): contenedor real (taap/agent-opencode:local) + `opencode serve`
 * 1.19.6 (modo `api-events`) + Model Proxy + gateway sintético OpenAI
 * chat-completions (y Anthropic /v1/messages para el formato dual).
 *
 * Se SALTA sin Docker o sin la imagen; en CI (con servicio Docker) es la
 * prueba de aceptacion del cambio. La infraestructura es la de
 * Claude/CodexRuntimeIntegrationTest: la app se sirve dentro de
 * taap-orchestrator y el gateway corre en un contenedor de la red
 * taap-internal (reusa la imagen OpenCode, que lleva node 24).
 *
 * 4.1: provision -> ready (server.connected) -> turno con permiso
 *      (bash ask -> allow) -> final/run.end -> reinicio del contenedor ->
 *      sesion reanudada (nMessages del gateway crece: el historial viaja).
 * 4.2: proveedor caido -> evento `error`; permiso sin responder -> timeout
 *      -> denegado; y el MISMO flujo con un provider `type: anthropic`
 *      (el formato dual del proxy + @ai-sdk/anthropic).
 */
class OpenCodeRuntimeIntegrationTest extends TestCase
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

        $this->skipUnlessDocker(['taap/agent-opencode:local']);
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
        $this->gatewayName = 'taap-int-oc-gw-'.uniqid('', false);
        $this->gatewayPort = '8903';
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
            // El adapter llama a la API del agente por HTTP y phpunit esta en
            // el host: el provision publica 4096 en 127.0.0.1:0 y el adapter
            // lo usa (baseUrl en modo test).
            'taap.agents.test_api_publish' => true,
        ]);

        Artisan::call('migrate:fresh', ['--force' => true]);
        Queue::fake();

        $this->startGateway();
        $this->copyDbToOrchestrator();
        $this->startServeInOrchestrator();
        $this->waitForHttpInOrchestrator('http://127.0.0.1:'.$this->servePort.'/up', 30, false);
        $this->waitForHttpInOrchestrator('http://'.$this->gatewayName.':'.$this->gatewayPort.'/api/hello', 20);

        // Sink que registra en $this->events ANTES de procesar.
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
        $this->adapter = app(RuntimeAdapters::class)->for(new Agent(['runtime' => 'opencode']));
    }

    protected function tearDown(): void
    {
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
        // La imagen OpenCode lleva node 24: sirve para el gateway.
        $p = new SymfonyProcess([
            'docker', 'run', '-d',
            '--name', $this->gatewayName,
            '--network', (string) config('taap.agents.network_internal'),
            '-v', $this->base.':/gw',
            '-v', __DIR__.'/../../Support/gateway-chat.mjs:/gw/gateway.mjs:ro',
            '--entrypoint', 'node',
            'taap/agent-opencode:local',
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

    protected function openaiProvider(User $user): ModelProvider
    {
        return ModelProvider::factory()->create([
            'name' => 'gateway',
            'type' => 'openai',
            // /v1: el Model Proxy lo une al path (base_url real de OpenAI ya
            // lo lleva); asi el gateway lo ve en /v1/chat/completions.
            'base_url' => 'http://'.$this->gatewayName.':'.$this->gatewayPort.'/v1',
            'created_by' => $user->id,
        ]);
    }

    protected function anthropicProvider(User $user): ModelProvider
    {
        return ModelProvider::factory()->create([
            'name' => 'gateway-ant',
            'type' => 'anthropic',
            'base_url' => 'http://'.$this->gatewayName.':'.$this->gatewayPort,
            'created_by' => $user->id,
        ]);
    }

    protected function model(ModelProvider $provider, string $modelId): Model
    {
        return Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => $modelId,
            'name' => 'OC Int',
        ]);
    }

    protected function makeAgent(User $user, Model $model, string $name, array $config = []): Agent
    {
        $agent = Agent::factory()->create([
            'name' => $name,
            'owner_id' => $user->id,
            'runtime' => 'opencode',
            'image' => 'taap/agent-opencode:local',
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

    protected function gatewayRequests(): array
    {
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

    /* ================= 4.1: flujo completo ================= */

    public function test_full_flow_with_approval_restart_and_resume(): void
    {
        $user = $this->user();
        $provider = $this->openaiProvider($user);
        $model = $this->model($provider, 'gpt-oc-int');

        $this->agent = $this->makeAgent($user, $model, 'oc-int');

        /* -- arrancado: server.connected -> ready -- */
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 180);
        $this->assertSame('idle', $this->agent->refresh()->status->value, 'no llego a ready: '.json_encode($this->events));

        /* -- turno 1: bash echo ping -> permiso -> allow -- */
        $this->adapter->writeMessage($this->agent->refresh(), 'Ejecuta en bash: echo ping');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::WaitingApproval, 120);
        $approval = $this->eventsOfType('approval.request');
        $this->assertNotEmpty($approval, 'no llego la aprobacion: '.json_encode($this->eventTypes()));
        $this->assertStringContainsString('echo ping', (string) $approval[0]['payload']['prompt']);

        $this->adapter->resolveApproval($this->agent->refresh(), $approval[0]['payload']['id'], true, 'once');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && $this->eventsOfType('run.end') !== [], 120);

        $types = $this->eventTypes();
        foreach (['run.start', 'tool.start', 'message.final', 'run.end', 'approval.resolved'] as $t) {
            $this->assertContains($t, $types, "falta {$t}: ".json_encode($types));
        }
        $this->assertTrue((bool) $this->eventsOfType('approval.resolved')[0]['payload']['approved']);

        // El mensaje final persistio en el chat.
        $this->assertNotNull(ChatMessage::where('agent_id', $this->agent->id)->where('author', 'agent')->first());

        /* -- reinicio del contenedor -> sesion reanudada (nMessages crece) -- */
        $p = new SymfonyProcess(['docker', 'restart', $this->agent->container_name]);
        $p->setTimeout(180);
        $p->run();
        $this->assertTrue($p->isSuccessful(), 'docker restart: '.$p->getErrorOutput());

        // El status puede estar en `idle` viejo (el turno anterior termino):
        // se espera el `status.change ready` NUEVO (server.connected del
        // opencode re-arrancado): la prueba de que el servidor volvio a
        // bindar el puerto y el reader reconecto.
        $ready = fn () => array_filter($this->events, fn (array $e) => ($e['type'] ?? '') === 'status.change' && ($e['payload']['status'] ?? null) === 'ready');
        $readyBefore = count($ready());
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && count($ready()) > $readyBefore, 180);
        $this->assertGreaterThan($readyBefore, count($ready()), 'no volvio a ready tras restart: '.json_encode($this->events));

        // En modo test el adapter enruta por `docker exec curl` (inmune al
        // forward de puerto de Docker Desktop): esperar a que el servidor
        // interno responda antes de enviar el siguiente prompt.
        $deadline = microtime(true) + 45;
        $healthy = false;
        while (microtime(true) < $deadline) {
            $p = new Process([
                'docker', 'exec', $this->agent->container_name, 'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '2', 'http://127.0.0.1:4096/api/health',
            ]);
            $p->setTimeout(8);
            $p->run();
            if (trim($p->getOutput()) !== '000') {
                $healthy = true;
                break;
            }
            usleep(500_000);
        }
        $this->assertTrue($healthy, 'el servidor interno no responde tras el restart');

        $before = $this->gatewayRequests();
        $this->adapter->writeMessage($this->agent->refresh(), 'Dame un recordatorio');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && count($this->eventsOfType('run.end')) >= 2, 120);

        $requests = $this->gatewayRequests();
        $this->assertGreaterThan(count($before), count($requests));
        $last = $requests[count($requests) - 1];

        // Evidencia del resume: el request post-reinicio lleva el historial
        // de los turnos anteriores (la sesion opencode vive en /data).
        $this->assertGreaterThanOrEqual(4, (int) ($last['nMessages'] ?? 0), 'la sesion no llevo historial: '.json_encode($last));
        $this->assertStringContainsString('Bearer', (string) $last['auth'], 'el proxy presento el token de proveedor');
        $this->assertSame('gpt-oc-int', $last['model']);

        // El turno post-reinicio termino con su final.
        $finals = $this->eventsOfType('message.final');
        $this->assertNotSame('', (string) $finals[count($finals) - 1]['payload']['content']);
    }

    /* ================= 4.2: fallos ================= */

    public function test_provider_failure_surfaces_error_event(): void
    {
        $user = $this->user();

        // Host que no existe en la VM: el upstream cae (fallo de DNS) y el
        // proxy devuelve 5xx; opencode lo expone en el stream (session.error
        // / message.updated con error) y el adapter normaliza a `error`.
        $dead = ModelProvider::factory()->create([
            'name' => 'caido',
            'type' => 'openai',
            'base_url' => 'http://'.(string) config('taap.agents.network_internal').'-inexistente:'.$this->gatewayPort,
            'created_by' => $user->id,
        ]);
        $model = $this->model($dead, 'gpt-oc-fail');

        $this->agent = $this->makeAgent($user, $model, 'oc-fail');

        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 180);
        $this->assertSame('idle', $this->agent->refresh()->status->value, 'no llego a ready: '.json_encode($this->events));

        $this->adapter->writeMessage($this->agent->refresh(), 'hola');
        $this->pump($this->agent, fn (Agent $a, array $ev) => $this->eventsOfType('error') !== [] || $a->status === AgentStatus::Error, 120);

        $hasError = $this->eventsOfType('error') !== []
            || collect($this->eventsOfType('run.end'))->contains(fn (array $e) => ! $e['payload']['ok']);
        $this->assertTrue($hasError, 'debia llegar un error: '.json_encode($this->eventTypes()));
        $this->assertContains($this->agent->refresh()->status->value, ['idle', 'error']);
    }

    public function test_approval_timeout_denies_and_turn_continues(): void
    {
        $user = $this->user();
        $provider = $this->openaiProvider($user);
        $model = $this->model($provider, 'gpt-oc-timeout');

        // approval_timeout_seconds: 3 (produccion 1800): opencode NO tiene
        // timeout propio (la sesion se queda busy); el adapter lo implementa
        // en el drain y la aprobacion caducada se resuelve con `reject`.
        $this->agent = $this->makeAgent($user, $model, 'oc-timeout', ['approval_timeout_seconds' => 3]);
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 180);
        $this->assertSame('idle', $this->agent->refresh()->status->value, 'no llego a ready: '.json_encode($this->events));

        $this->adapter->writeMessage($this->agent->refresh(), 'Ejecuta en bash: echo tardio');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && $this->eventsOfType('run.end') !== [], 120);

        $this->assertNotEmpty($this->eventsOfType('approval.request'), 'no se solicito la aprobacion: '.json_encode($this->eventTypes()));
        $resolved = $this->eventsOfType('approval.resolved');
        $this->assertNotEmpty($resolved, 'el timeout debio resolver la aprobacion');
        $this->assertFalse((bool) $resolved[count($resolved) - 1]['payload']['approved'], 'el timeout deniega');

        // El agente no queda colgado: el tool reporto la denegacion y el
        // turno termino.
        $this->assertNotEmpty($this->eventsOfType('run.end'));
        $this->assertSame('idle', $this->agent->refresh()->status->value);
    }

    /* ================= 4.2: formato dual (Anthropic) ================= */

    public function test_anthropic_provider_full_flow(): void
    {
        $user = $this->user();
        $provider = $this->anthropicProvider($user);
        $model = $this->model($provider, 'claude-oc-int');

        $this->agent = $this->makeAgent($user, $model, 'oc-ant');

        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 180);
        $this->assertSame('idle', $this->agent->refresh()->status->value, 'no llego a ready: '.json_encode($this->events));

        $this->adapter->writeMessage($this->agent->refresh(), 'Dame un recordatorio');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && $this->eventsOfType('run.end') !== [], 120);

        $types = $this->eventTypes();
        foreach (['run.start', 'message.final', 'run.end'] as $t) {
            $this->assertContains($t, $types, "falta {$t}: ".json_encode($types));
        }
        $finals = $this->eventsOfType('message.final');
        $this->assertNotEmpty((string) $finals[0]['payload']['content']);

        // El proxy sirvio el wire Anthropic nativo (x-api-key) al gateway.
        $requests = $this->gatewayRequests();
        $this->assertNotEmpty($requests, 'el gateway no vio peticiones: '.json_encode($requests));
        $last = $requests[count($requests) - 1];
        $this->assertSame('anthropic', $last['wire'] ?? null, 'el request no paso por /v1/messages: '.json_encode($last));
        $this->assertStringContainsString('claude-oc-int', (string) $last['model']);
    }
}

<?php

namespace Tests\Feature\Agents;

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
 * Integracion extremo a extremo del runtime Claude (grupo 7): contenedor
 * real (taap/agent-claude:local) + sidecar + SDK real + Model Proxy +
 * gateway Anthropic local. Se SALTA sin Docker o si faltan las imagenes;
 * en CI (con servicio Docker) es la prueba de aceptacion del cambio.
 *
 * Infraestructura (todo dentro de la VM de Docker, donde solo resuelven
 * los nombres de la red taap-internal; host.docker.internal NO existe en
 * algunos entornos):
 *   - La app del orquestador se "sirve" dentro del contenedor
 *     taap-orchestrator (php -S) compartiendo la BD sqlite del test
 *     (bind mount del directorio de datos).
 *   - El gateway corre en un contenedor node de la red taap-internal.
 *   - El contenedor del agente llama a http://taap-orchestrator:<puerto>
 *     y http://<gateway>:<puerto> (DNS interno de docker).
 *
 * 7.1: provision -> turno con aprobacion allow -> denegacion -> reinicio
 *      con sesion reanudada (el gateway ve crecer el historial).
 * 7.2: proveedor caido -> evento `error` visible en el chat.
 */
class ClaudeRuntimeIntegrationTest extends TestCase
{
    use RequiresDocker;

    protected Agent $agent;

    protected EventSink $sink;

    protected AgentRuntimeAdapter $adapter;

    /** @var list<array{type: string, payload: array, at: string}> */
    protected array $events = [];

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

        $this->skipUnlessDocker(['taap/agent-claude:local']);
        if (! $this->dockerContainerExists('taap-orchestrator')) {
            $this->markTestSkipped('No hay contenedor taap-orchestrator (deploy local)');
        }

        // Directorio bajo el arbol de datos de agentes: visible para el
        // orquestador (bind /data/agents) y para los contenedores de
        // agentes (mount de la misma ruta de host).
        $this->base = (string) config('taap.agents.data_base').'/taap-int-'.uniqid();
        File::ensureDirectoryExists($this->base);
        chmod($this->base, 0777); // el gateway (uid 1001) escribe su log aqui

        $this->dbFile = $this->base.'/test.sqlite';
        @unlink($this->dbFile);
        @unlink($this->dbFile.'-wal');
        @unlink($this->dbFile.'-shm');

        $this->servePort = (string) (18000 + random_int(1, 20000));
        $this->gatewayName = 'taap-int-gw-'.uniqid('', false);
        $this->gatewayPort = '8901';
        $this->gatewayLog = $this->base.'/gateway-reqs.jsonl';
        @file_put_contents($this->gatewayLog, '');
        chmod($this->gatewayLog, 0666);

        config([
            'broadcasting.default' => 'null',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->dbFile,
            // Sin WAL: el header del fichero persiste el modo y, en la VM de
            // Docker, el indice -shm no es coherente entre procesos. Con
            // journal `delete` todo el estado vive en el fichero principal:
            // la copia docker-cp siempre es completa y consistente.
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
        // Sonda sin BD (/up): evita que el worker abra el fichero de datos
        // antes de que makeAgent() reemplace la copia con la de fixtures.
        // La primera consulta a la BD la hara la propia SDK (post-fixtures).
        $this->waitForHttpInOrchestrator('http://127.0.0.1:'.$this->servePort.'/up', 30, false);
        $this->waitForHttpInOrchestrator('http://'.$this->gatewayName.':'.$this->gatewayPort.'/api/hello', 20);

        $this->sink = new EventSink;
        $this->adapter = app(RuntimeAdapters::class)->for(new Agent(['runtime' => 'claude']));
    }

    protected function tearDown(): void
    {
        // Primero la evidencia (debug) y despues el teardown: terminate
        // para los contenedores y los logs del sidecar se iran con ellos.
        if (env('TAAP_INT_DEBUG')) {
            $dump = new SymfonyProcess(['docker', 'exec', 'taap-orchestrator', 'sh', '-c', 'tail -c 3000 /tmp/taap-int-laravel.log 2>/dev/null']);
            $dump->setTimeout(15);
            $dump->run();
            fwrite(STDERR, '=== SERVE LOG (tail) ===
'.rtrim($dump->getOutput()).'
');
            // Logs del sidecar (stdout del contenedor del agente)
            $list = new SymfonyProcess(['docker', 'ps', '--filter', 'name=taap-agent-', '--format', '{{.Names}}']);
            $list->setTimeout(15);
            $list->run();
            foreach (array_filter(array_map('trim', explode('
', $list->getOutput()))) as $name) {
                if (! str_contains($name, 'web-pi') && ! str_contains($name, 'web-hermes')) {
                    $logs = new SymfonyProcess(['docker', 'logs', '--tail', '60', $name]);
                    $logs->setTimeout(15);
                    $logs->run();
                    fwrite(STDERR, "=== AGENT LOG {$name} ===
".rtrim($logs->getOutput()).rtrim($logs->getErrorOutput()).'
');
                }
                $cfg = new SymfonyProcess(['docker', 'exec', $name, 'cat', '/config/claude.json']);
                $cfg->setTimeout(15);
                $cfg->run();
                $out = trim($cfg->getOutput());
                if ($out !== '') {
                    $j = json_decode($out, true);
                    fwrite(STDERR, "=== AGENT CFG {$name}: base=".($j['anthropic_base_url'] ?? '?').' key8='.substr((string) ($j['anthropic_api_key'] ?? ''), 0, 8).' model='.($j['model'] ?? '?').'
');
                } else {
                    fwrite(STDERR, "=== AGENT CFG {$name}: VACIO (len=".strlen($out).' stdout='.trim($cfg->getOutput()).' err='.trim($cfg->getErrorOutput()).')
');
                }
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
        $p = new SymfonyProcess([
            'docker', 'run', '-d',
            '--name', $this->gatewayName,
            '--network', (string) config('taap.agents.network_internal'),
            '-v', $this->base.':/gw',
            '-v', __DIR__.'/../../Support/gateway.mjs:/gw/gateway.mjs:ro',
            '--entrypoint', 'node',
            'taap/agent-claude:local',
            '/gw/gateway.mjs', $this->gatewayPort, '/gw/gateway-reqs.jsonl',
        ]);
        $p->run();
        $this->tempContainers[] = $this->gatewayName;

        $this->assertTrue($p->isSuccessful(), 'docker run gateway: '.$p->getErrorOutput());
    }

    protected function startServeInOrchestrator(): void
    {
        // Ruta absoluta: el router usa getcwd() como public, pero el
        // camino del router se resuelve igualmente contra el cwd.
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
            // $json: exigir JSON (un fallo de bootstrap da HTML de PHP); en
            // false basta que el worker responda (liveness del php -S).
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

    /** Proveedor Anthropic -> gateway (DNS interno de docker). */
    protected function anthropicProvider(User $user): ModelProvider
    {
        return ModelProvider::factory()->create([
            'name' => 'gateway',
            'type' => 'anthropic',
            'base_url' => 'http://'.$this->gatewayName.':'.$this->gatewayPort,
            'created_by' => $user->id,
        ]);
    }

    protected function model(ModelProvider $provider, string $modelId = 'claude-sonnet-4-5'): Model
    {
        return Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => $modelId,
            'name' => 'Claude Sonnet 4.5',
        ]);
    }

    protected function makeAgent(User $user, Model $model, string $name, array $config = []): Agent
    {
        $agent = Agent::factory()->create([
            'name' => $name,
            'owner_id' => $user->id,
            'runtime' => 'claude',
            'image' => 'taap/agent-claude:local',
            'model_id' => $model->id,
            'internet_enabled' => false,
            'status' => AgentStatus::Offline,
            // null en la BD cuando es vacio (evita escribir [] en la columna)
            'config' => $config,
        ]);

        app(AgentManager::class)->provision($agent);

        $this->copyDbToOrchestrator();

        return $agent->refresh();
    }

    /**
     * Copia la BD sqlite del test a taap-orchestrator (fs local del contenedor).
     * No se comparte el fichero por bind mount: la caché de paginas de la VM
     * de Docker no es coherente para el mismo fichero leido desde host y desde
     * el contenedor (SQLite ve contenido stale). La copia puntual tras las
     * fixtures si lo es: los writes del test en vuelo (eventos/status) no los
     * lee el Model Proxy.
     */
    protected function copyDbToOrchestrator(): void
    {
        // checkpoint: vaciar el WAL para que la copia contenga todo
        @DB::select('PRAGMA wal_checkpoint(TRUNCATE);');
        (new SymfonyProcess(['docker', 'cp', $this->dbFile, 'taap-orchestrator:/tmp/taap-int-test.sqlite']))->run();
    }

    /** Requests registrados por el gateway (se comparte el fichero via bind). */
    protected function gatewayRequests(): array
    {
        // Se lee desde el contenedor del gateway, no del host: el fichero
        // vive en el bind (virtiofs) y el page cache de la VM no refleja los
        // appends en el lado host de forma fiable.
        $p = new SymfonyProcess(['docker', 'exec', $this->gatewayName, 'cat', '/gw/gateway-reqs.jsonl']);
        $p->setTimeout(15);
        $p->run();
        if (! $p->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $line) => json_decode($line, true),
            preg_split('/\r?\n/', $p->getOutput()) ?: [],
        ), fn ($o) => $o !== null));
    }

    /* ================= mini-bridge ================= */

    /**
     * Reproduce el bucle del bridge (attach/drain/probe) hasta que
     * $until($agent, $events) sea verdad o expire el timeout.
     */
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
                    if (env('TAAP_INT_DEBUG')) {
                        $d2 = app(DockerClient::class)->rpcDir($agent->id);
                        $p2 = (int) (@file_get_contents("$d2/reader.pid") ?: 0);
                        fwrite(STDERR, "[dbg post-attach] rpid={$p2} alive=".(($p2 > 0 && @posix_kill($p2, 0)) ? 'yes' : 'no').' ps=['.trim((string) shell_exec("ps -p {$p2} -o stat,command 2>/dev/null | tail -1"))."]\n");
                    }
                }
            }

            foreach ($this->adapter->drain($agent) as $event) {
                $this->events[] = $event->toArray();
                $this->sink->dispatch($agent->refresh(), $event);
            }

            $agent = $agent->refresh();

            if ($agent->status === AgentStatus::Starting
                && microtime(true) - $lastProbe > $this->adapter->probeInterval()) {
                $lastProbe = microtime(true);
                $this->adapter->probe($agent);
            }

            if (env('TAAP_INT_DEBUG')) {
                $dir = app(DockerClient::class)->rpcDir($agent->id);
                $pid = (int) (@file_get_contents("$dir/reader.pid") ?: 0);
                $alive = $pid > 0 && @shell_exec("kill -0 {$pid} 2>/dev/null") !== null;
                $posixres = var_export(@posix_kill($pid, 0), true);
                $shres = trim((string) @shell_exec("kill -0 {$pid} 2>/dev/null; echo rc=\$?"));
                $stat = trim((string) @shell_exec("ps -p {$pid} -o stat= 2>/dev/null"));
                $ppid = trim((string) @shell_exec("ps -p {$pid} -o ppid= 2>/dev/null"));
                $exitcode = null;
                if (! $alive && extension_loaded('pcntl')) {
                    $w = @pcntl_waitpid($pid, $st, WNOHANG);
                    if ($w === $pid) {
                        $exitcode = pcntl_wifexited($st) ? pcntl_wexitstatus($st) : 'sig'.pcntl_wtermsig($st);
                    }
                }
                $errdump = trim((string) @file_get_contents("$dir/err"));
                fwrite(STDERR, sprintf(
                    "[dbg t=%.1f] state=%s attached=%s out=%s in=%s rpid=%d alive=%s status=%s ev=%d err=[%s] exit=%s %s\n",
                    microtime(true) - ($deadline - $timeout),
                    app(DockerClient::class)->status($agent->container_name ?? ''),
                    var_export($this->adapter->isAttached($agent->refresh()), true),
                    (string) @filesize("$dir/out"), (string) @filesize("$dir/in"),
                    $pid, var_export($alive, true), $agent->status->value, count($this->events), substr($errdump, 0, 200), $exitcode ?? '?', "posix={$posixres} sh=[{$shres}] stat=[{$stat}]",
                ));
            }

            if ($until($agent, $this->events)) {
                return $agent;
            }

            usleep((int) ($tick * 1_000_000));
        }

        return $agent;
    }

    /** Tipos de eventos vistos, en orden. */
    protected function eventTypes(): array
    {
        return array_map(fn (array $e) => $e['type'], $this->events);
    }

    protected function eventsOfType(string $type): array
    {
        return array_values(array_filter($this->events, fn (array $e) => $e['type'] === $type));
    }

    /* ================= 7.1: flujo completo ================= */

    public function test_full_flow_with_approval_restart_and_resume(): void
    {
        $user = $this->user();
        $provider = $this->anthropicProvider($user);
        $model = $this->model($provider);

        $this->agent = $this->makeAgent($user, $model, 'claude-int');

        /* -- arrancado: probe hasta ready -- */
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 120);
        $this->assertSame('idle', $this->agent->refresh()->status->value, 'no llego a ready: '.json_encode($this->events));

        /* -- turno 1: bash -> aprobacion -> allow -- */
        $this->adapter->writeMessage($this->agent->refresh(), 'Ejecuta en bash: echo ping');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::WaitingApproval, 90);
        $approval = $this->eventsOfType('approval.request');
        $this->assertNotEmpty($approval, 'no llego la aprobacion: '.json_encode(array_values(array_intersect_key($this->events, array_flip(range(max(0, count($this->events) - 8), count($this->events)))))) ?: json_encode($this->events));
        // El payload de approval.request es {run_id, id, prompt, options}
        // (contrato v1.0, igual que Pi): la herramienta va en el prompt.
        $this->assertStringContainsString('Bash', (string) $approval[0]['payload']['prompt']);

        $this->adapter->resolveApproval($this->agent->refresh(), $approval[0]['payload']['id'], true, 'once');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && $this->eventsOfType('run.end') !== [], 90);

        $types = $this->eventTypes();
        foreach (['run.start', 'message.delta', 'tool.start', 'tool.result', 'message.final', 'run.end', 'approval.resolved'] as $t) {
            $this->assertContains($t, $types, "falta {$t}: ".json_encode($types));
        }
        $this->assertTrue($this->eventsOfType('approval.resolved')[0]['payload']['approved']);

        // El mensaje final persistio en el chat
        $this->assertNotNull(ChatMessage::where('agent_id', $this->agent->id)->where('author', 'agent')->first());

        /* -- turno 2: bash -> denegacion -- */
        $this->adapter->writeMessage($this->agent->refresh(), 'Ejecuta en bash: echo denegado');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::WaitingApproval, 90);
        $approval2 = $this->eventsOfType('approval.request');
        $this->assertNotEmpty($approval2);

        $this->adapter->resolveApproval($this->agent->refresh(), $approval2[count($approval2) - 1]['payload']['id'], false, 'once');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && count($this->eventsOfType('run.end')) >= 2, 90);

        $this->assertCount(2, $this->eventsOfType('run.end'));
        $resolved2 = $this->eventsOfType('approval.resolved');
        $this->assertFalse($resolved2[count($resolved2) - 1]['payload']['approved']);
        // Tras la denegacion el agente continuo y produjo su cierre.
        $this->assertNotSame('', (string) $this->eventsOfType('message.final')[1]['payload']['content']);

        /* -- reinicio del contenedor -> sesion reanudada -- */
        $p = new SymfonyProcess(['docker', 'restart', $this->agent->container_name]);
        $p->setTimeout(120);
        $p->run();
        $this->assertTrue($p->isSuccessful(), 'docker restart: '.$p->getErrorOutput());

        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 120);
        $this->assertSame('idle', $this->agent->refresh()->status->value, 'no recuperado tras restart: '.json_encode($this->events));

        $before = count($this->gatewayRequests());
        $this->adapter->writeMessage($this->agent->refresh(), 'Dame un recordatorio');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && count($this->eventsOfType('run.end')) >= 3, 90);

        $requests = $this->gatewayRequests();
        $this->assertGreaterThan($before, count($requests));
        $last = $requests[count($requests) - 1];

        // Evidencia de `resume`: el request post-reinicio lleva el historial
        // de los turnos anteriores (el gateway lo cuenta en `nMessages`).
        $this->assertGreaterThanOrEqual(6, (int) $last['nMessages'], 'la sesion no llevo historial: '.json_encode($last));
        $this->assertSame('x-api-key', $last['auth'], 'el SDK debe enviar x-api-key por la puerta del proxy');
    }

    /* ================= 7.2: fallos ================= */

    public function test_approval_timeout_denies_and_turn_continues(): void
    {
        $user = $this->user();
        $provider = $this->anthropicProvider($user);
        $model = $this->model($provider);

        // approval_timeout_seconds: 2s (en produccion son 1800): sin
        // respuesta del admin, el sidecar deniega solo y el turno termina.
        $this->agent = $this->makeAgent($user, $model, 'claude-timeout', ['approval_timeout_seconds' => 2]);
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 120);
        $this->assertSame('idle', $this->agent->refresh()->status->value);

        $this->adapter->writeMessage($this->agent->refresh(), 'Ejecuta en bash: echo tardio');
        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle && $this->eventsOfType('run.end') !== [], 90);

        // La aprobacion se solicito, nadie la resolvio y el timeout la denego.
        $this->assertNotEmpty($this->eventsOfType('approval.request'), 'no se solicito la aprobacion: '.json_encode($this->eventTypes()));
        $resolved = $this->eventsOfType('approval.resolved');
        $this->assertNotEmpty($resolved, 'el timeout debio resolver la aprobacion');
        $this->assertFalse((bool) $resolved[count($resolved) - 1]['payload']['approved'], 'el timeout deniega');

        // El agente continuo y el turno termino correctamente.
        $this->assertNotEmpty($this->eventsOfType('run.end'));
        $this->assertSame('idle', $this->agent->refresh()->status->value);
    }

    public function test_provider_failure_surfaces_error_event(): void
    {
        $user = $this->user();

        // Host que no existe en la VM: el upstream cae con fallo de DNS
        $dead = ModelProvider::factory()->create([
            'name' => 'caido',
            'type' => 'anthropic',
            'base_url' => 'http://'.config('taap.agents.network_internal').'-inexistente:'.$this->gatewayPort,
            'created_by' => $user->id,
        ]);
        $model = $this->model($dead);

        // max_retries: sin acesos, la SDK reintenta las peticiones al
        // proveedor caido durante varios minutos; con 1 el error llega en
        // segundos y el evento `error` TAAP tambien.
        $this->agent = $this->makeAgent($user, $model, 'claude-fail', ['max_retries' => 1]);

        $this->pump($this->agent, fn (Agent $a) => $a->status === AgentStatus::Idle, 120);
        $this->assertSame('idle', $this->agent->refresh()->status->value);

        $this->adapter->writeMessage($this->agent->refresh(), 'hola');
        $this->pump($this->agent, fn (Agent $a, array $ev) => $this->eventsOfType('error') !== [] || $a->status === AgentStatus::Error, 90);

        $this->assertNotCount(0, $this->eventsOfType('error'), 'debia llegar un evento error: '.json_encode($this->eventTypes()));
    }
}

<?php

namespace App\Agents;

use App\Enums\AgentRuntime;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentToken;
use App\Models\User;
use App\Support\DockerClient;
use App\Support\DockerException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Ciclo de vida de agentes (spec agent-lifecycle). Traduce operaciones de
 * nivel de dominio a operaciones Docker y mantiene la base de datos
 * coherente con el estado real.
 *
 * Un agente = un contenedor de larga vida:
 *   - volúmenes: bind mounts bajo /var/lib/taap/agents/<id>/ (data, workspace, config)
 *   - redes: taap-internal SIEMPRE (IP fija); taap-egress solo si internet_enabled
 *   - restart: unless-stopped (24/7; un "stop" de usuario no se re-arranca)
 *   - etiqueta taap.agent=<id> para la reconciliacion
 */
class AgentManager
{
    public function __construct(
        protected DockerClient $docker,
        protected ConfigRenderer $config,
    ) {}

    /* ================= provision ================= */

    /** Puerto API a publicar (solo modo test; null en produccion). */
    protected function testApiPort(Agent $agent): ?int
    {
        if (! config('taap.agents.test_api_publish', false)) {
            return null;
        }

        return $agent->runtime?->registry()['api_port'] ?? null;
    }

    /**
     * Crea TODO lo que el agente necesita: directorios, token, config
     * renderizada, contenedor y arranque. Idempotente (re-provisiona lo que
     * falte). Devuelve el agente actualizado.
     */
    public function provision(Agent $agent): Agent
    {
        $agent->container_name ??= $agent->defaultContainerName();

        // 1. IP fija en taap-internal (la pide el contenedor; el ACL del
        //    proxy squid la lista cuando sea necesario)
        if (empty($agent->ip)) {
            // La DB es la fuente de verdad de las reservas (D2): un
            // contenedor Created no aparece en la red, pero su IP ya no
            // es libre para otro agente.
            $reserved = Agent::whereKeyNot($agent->id)
                ->whereNotNull('ip')
                ->pluck('ip')
                ->all();
            $agent->ip = $this->docker->allocateIp(
                (string) config('taap.agents.network_internal'),
                $reserved,
            );
        }

        // 2. Token del agente (Model Proxy + MCP bridge) - se guarda ANTES
        //    del agent (hasOne: no serializar la relacion en la fila)
        if (! $agent->token()->exists()) {
            AgentToken::issueFor($agent)->save();
        }

        // 3. Config nativa renderizada en el area persistente del agente
        $mounts = $this->config->render($agent);

        // 4. Contenedor
        $name = $agent->container_name;
        $existing = $this->docker->status($name);
        if ($existing !== null) {
            $this->docker->remove($name);
        }

        $networks = [[
            'name' => (string) config('taap.agents.network_internal'),
            'ip' => $agent->ip,
        ]];
        if ($agent->internet_enabled) {
            $networks[] = [
                'name' => (string) config('taap.agents.network_egress'),
                'ip' => null,
            ];
        }
        // Modo test: Docker ignora `-p` con una sola red user-defined, asi
        // que se adjunta la red de egress como segunda (el -p se une a ella;
        // el squid no da ACL al agente sin Internet: sin cambio de conducta).
        if ($this->testApiPort($agent) !== null && ! $agent->internet_enabled) {
            $networks[] = [
                'name' => (string) config('taap.agents.network_egress'),
                'ip' => null,
            ];
        }

        $spec = [
            'agent_id' => $agent->id,
            'name' => $name,
            'image' => $agent->image,
            'networks' => $networks,
            'volumes' => $mounts,
            'env' => $this->containerEnv($agent),
            'restart' => $agent->auto_restart ? 'unless-stopped' : 'no',
            // Solo en tests de integracion: el adapter llama a la API del
            // agente por HTTP y phpunit corre en el HOST (fuera de la red
            // taap-internal de la VM): se publica 4096 en 127.0.0.1:0
            // (localhost del host; sin exposicion externa).
            'ports' => $this->testApiPort($agent) !== null
                ? ['127.0.0.1:0:'.$this->testApiPort($agent)]
                : [],
            'openStdin' => true,
            'tty' => false,
            // Endurecimiento: rootfs en solo lectura donde el runtime lo
            // permita. Lo que necesite escribir va a binds (config/data/
            // workspace) o tmpfs (/tmp; /rpc: FIFOs del puente TAAP).
            // Excepcion: la imagen de Hermes usa s6-linux-init, cuyo stage0
            // necesita rootfs escribible (no viable en solo lectura).
            'readonly' => $agent->runtime !== AgentRuntime::Hermes,
            'tmpfs' => $agent->runtime === AgentRuntime::Hermes
                ? ['/tmp:size=64m,mode=1777', '/rpc:size=8m,mode=1777']
                : ['/tmp:size=64m,mode=1777', '/rpc:size=8m,mode=1777'],
            'labels' => [
                'taap.runtime' => $agent->runtime->value,
                'taap.owner' => (string) $agent->owner_id,
                'taap.instance' => (string) config('taap.instance', 'default'),
            ],
        ];

        if (! $this->docker->imageExists($agent->image)) {
            $this->docker->imagePull($agent->image);
        }

        // Primero el estado (el reconcile del bridge respeta `starting`)
        $agent->status = AgentStatus::Starting;
        $agent->started_at = now();
        $agent->save();

        $containerId = $this->docker->createContainer($spec);
        $agent->container_id = $containerId;
        $agent->container_name = $name;
        $agent->save();
        $this->docker->start($name);

        // Puerto publicado en modo test (el adapter lo usa como base URL).
        if ($port = $this->testApiPort($agent)) {
            $published = $this->docker->publishedPort($name, $port);
            if ($published !== null) {
                $config = $agent->config ?? [];
                $config['api_test_port'] = $published;
                $agent->config = $config;
                $agent->save();
            }
        }

        // Sincroniza el ACL de egress (el proxy decide por IP)
        app(EgressSync::class)->sync();

        Log::info("Agente {$agent->id} provisionado: {$name} ({$agent->ip})");

        return $agent;
    }

    /**
     * @return array<string, string>
     */
    protected function containerEnv(Agent $agent): array
    {
        $env = [
            'TAAP_AGENT_ID' => (string) $agent->id,
            'TAAP_ORCHESTRATOR' => config('taap.proxy.internal_url', 'http://taap-orchestrator:8000'),
        ];

        // La imagen node:24 trae http_proxy=none; undici lo parsea con
        // new URL() y falla ('none' no es URL). Lo sobreescribimos siempre.
        //
        // El trafico de todos los agentes (con o sin Internet) pasa por el
        // proxy squid con allowlist (design.md D5): un comando inyectado no
        // sale de la allowlist aunque el agente tenga Internet. La red NAT
        // (taap-egress) solo se adjunta si internet_enabled: los agentes sin
        // Internet no tienen ruta de salida aparte del proxy.
        //
        // host.docker.internal y taap-orchestrator van SIEMPRE en no_proxy:
        // el Model Proxy y el bridge son servicios internos (no Internet) y
        // llaman directo; solo el resto de trafico pasa por squid.
        $noProxy = 'localhost,127.0.0.1,host.docker.internal,taap-orchestrator';
        $env['no_proxy'] = $noProxy;
        $env['NO_PROXY'] = $noProxy;

        $proxy = (string) config('taap.agents.egress_proxy');
        $env['PROXY_URL'] = $proxy;
        $env['http_proxy'] = $proxy;
        $env['https_proxy'] = $proxy;
        $env['HTTP_PROXY'] = $proxy;
        $env['HTTPS_PROXY'] = $proxy;

        return $env;
    }

    /* ================= operaciones ================= */

    public function start(Agent $agent): Agent
    {
        if (! $agent->container_name || $this->docker->status($agent->container_name) === null) {
            // Sin contenedor (o eliminado): (re)provisionar
            return $this->provision($agent);
        }

        $agent->status = AgentStatus::Starting;
        $agent->started_at = now();
        $agent->save();
        $this->docker->start($agent->container_name);

        return $agent;
    }

    public function stop(Agent $agent): Agent
    {
        if ($agent->container_name) {
            try {
                $this->docker->stop($agent->container_name);
            } catch (DockerException $e) {
                Log::warning("stop: {$e->getMessage()}");
            }
        }

        $agent->status = AgentStatus::Stopped;
        $agent->save();

        app(EventSink::class)->dispatch($agent, AgentEvent::statusChange(AgentStatus::Stopped->value));

        return $agent;
    }

    public function restart(Agent $agent): Agent
    {
        // Re-renderiza la config nativa: los cambios de modelo/MCP se aplican
        // al reiniciar sin recrear el contenedor
        $this->config->render($agent);

        if ($agent->container_name && $this->docker->status($agent->container_name) !== null) {
            $this->docker->restart($agent->container_name);
        } else {
            return $this->start($agent);
        }

        $agent->status = AgentStatus::Starting;
        $agent->save();

        return $agent;
    }

    /**
     * Elimina el agente: contenedor, area persistente, token. (La fila la
     * borra el controlador - soft delete - junto con lo que en cascade toca.)
     */
    public function terminate(Agent $agent): void
    {
        if ($agent->container_name) {
            $this->docker->remove($agent->container_name);
        }

        // Volcar los logs breves antes de borrar todo
        if ($agent->container_name) {
            $this->docker->logs($agent->container_name, 50);
        }

        $dir = $this->config->dir($agent);
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }

        $agent->token?->delete();
        app(EgressSync::class)->sync();

        Log::info("Agente {$agent->id} terminado");
    }

    /* ================= reconciliacion (24/7) ================= */

    /**
     * Alinea DB <-> Docker. Se llama por scheduler (minuto) y al arrancar
     * el bridge: detecta contenedores muertos/zombies y estados huérfanos.
     */
    public function reconcile(): void
    {
        $managed = collect($this->docker->managedContainers())
            ->keyBy(fn (array $c) => $c['agent'] !== null ? (int) $c['agent'] : -1);

        // 1. Agentes de la DB
        foreach (Agent::with('token')->get() as $agent) {
            $container = $managed->get($agent->id);
            $state = $container['state'] ?? null;

            if ($agent->status === AgentStatus::Stopped) {
                // El usuario lo detuvo: el contenedor debe estar parado/borrado
                if ($state === 'running') {
                    $this->docker->stop($agent->container_name);
                    $agent->save();
                }

                continue;
            }

            if ($state === null) {
                // Contenedor desaparecido (o nunca creado)
                if ($agent->isRunning()) {
                    $agent->status = AgentStatus::Offline;
                    $agent->save();
                    if ($agent->auto_restart) {
                        $this->start($agent);
                    }
                }

                continue;
            }

            if ($state === 'exited' || $state === 'dead') {
                // Fuera de policy: re-arrancar si tiene que estar vivo
                if ($agent->auto_restart) {
                    try {
                        $this->docker->start($agent->container_name);
                        $agent->status = AgentStatus::Starting;
                    } catch (DockerException $e) {
                        $agent->status = AgentStatus::Error;
                        Log::warning("reconcile: re-arranque falló {$agent->id}: {$e->getMessage()}");
                    }
                    $agent->save();
                } else {
                    $agent->status = AgentStatus::Offline;
                    $agent->save();
                }
            }

            if ($state === 'created') {
                // agent-runtime-reliability D3: creado sin arrancar (p. ej.
                // colision de IP en el arranque): misma politica que un crash.
                if ($agent->auto_restart) {
                    try {
                        $this->docker->start($agent->container_name);
                        $agent->status = AgentStatus::Starting;
                    } catch (DockerException $e) {
                        $agent->status = AgentStatus::Error;
                        Log::warning("reconcile: arranque del contenedor creado falló {$agent->id}: {$e->getMessage()}");
                    }
                    $agent->save();
                } else {
                    $agent->status = AgentStatus::Offline;
                    $agent->save();
                }
            }

            // Mantener container_id fresco
            if ($container['id'] !== $agent->container_id) {
                $agent->container_id = $container['id'];
                $agent->save();
            }
        }

        // 2. Contenedores gestionados sin agente en DB -> zombies: borrar
        $agentIds = Agent::pluck('id');
        foreach ($managed as $agentId => $container) {
            $local = (string) config('taap.instance', 'default');
            // Sin etiqueta de instancia (contenedores antiguos) -> tratar como
            // propia para no dejar huérfanos
            $instance = $container['instance'] !== '' ? $container['instance'] : $local;
            if ($instance !== $local
                || ($agentId === -1 || ! $agentIds->contains($agentId))) {
                if ($instance === $local) {
                    $this->docker->remove($container['name']);
                    Log::info("reconcile: contenedor zombie eliminado {$container['name']}");
                }
            }
        }
    }

    /* ================= cuota ================= */

    public function assertQuota(User $user, ?Agent $excluding = null): void
    {
        $running = $user->agents()
            ->whereNotIn('status', [AgentStatus::Stopped->value, AgentStatus::Offline->value])
            ->when($excluding, fn ($q, $a) => $q->whereKeyNot($a->id))
            ->count();

        if ($running >= $user->agentQuota()) {
            throw new QuotaExceededException(
                "Límite de agentes en ejecución alcanzado ({$running}/{$user->agentQuota()})."
            );
        }
    }

    public static function runtimeFor(Agent $agent): AgentRuntime
    {
        return $agent->runtime ?? AgentRuntime::Pi;
    }

    public function containerUrl(Agent $agent, int $port): string
    {
        // Los contenedores se hablan por nombre en las redes de TAAP
        return 'http://'.$agent->container_name.':'.$port;
    }
}

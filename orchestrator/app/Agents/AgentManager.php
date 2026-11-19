<?php

namespace App\Agents;

use App\Enums\AgentRuntime;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AgentToken;
use App\Models\User;
use App\Support\DockerClient;
use App\Support\DockerException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
    ) {
    }

    /* ================= provision ================= */

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
            $agent->ip = $this->docker->allocateIp(config('taap.agents.network_internal'));
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
            'name' => config('taap.agents.network_internal'),
            'ip' => $agent->ip,
        ]];
        if ($agent->internet_enabled) {
            $networks[] = [
                'name' => config('taap.agents.network_egress'),
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
            'openStdin' => true,
            'tty' => false,
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

        // Sincroniza el ACL de egress (el proxy decide por IP)
        app(EgressSync::class)->sync();

        Log::info("Agente {$agent->id} provisionado: {$name} ({$agent->ip})");

        return $agent;
    }

    protected function containerEnv(Agent $agent): array
    {
        $env = [
            'TAAP_AGENT_ID' => (string) $agent->id,
            'TAAP_ORCHESTRATOR' => config('taap.proxy.internal_url', 'http://taap-orchestrator:8000'),
        ];

        // La imagen node:24 trae http_proxy=none; undici lo parsea con
        // new URL() y falla ('none' no es URL). Lo sobreescribimos siempre.
        //
        // host.docker.internal y taap-orchestrator van SIEMPRE en no_proxy:
        // el Model Proxy y el bridge son servicios internos (no Internet) y
        // llaman directo; solo el resto de trafico pasa por squid.
        $noProxy = 'localhost,127.0.0.1,host.docker.internal,taap-orchestrator';
        $env['no_proxy'] = $noProxy;
        $env['NO_PROXY'] = $noProxy;

        if ($agent->internet_enabled) {
            // Salida directa (NAT): sin proxy
            $env['http_proxy'] = '';
            $env['https_proxy'] = '';
            $env['HTTP_PROXY'] = '';
            $env['HTTPS_PROXY'] = '';
        } else {
            // Todo por el proxy squid (identificado por IP de origen)
            $proxy = (string) config('taap.agents.egress_proxy');
            $env['PROXY_URL'] = $proxy;
            $env['http_proxy'] = $proxy;
            $env['https_proxy'] = $proxy;
            $env['HTTP_PROXY'] = $proxy;
            $env['HTTPS_PROXY'] = $proxy;
        }

        return $env;
    }

    /* ================= operaciones ================= */

    public function start(Agent $agent): Agent
    {
        if (!$agent->container_name || $this->docker->status($agent->container_name) === null) {
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
            \Illuminate\Support\Facades\File::deleteDirectory($dir);
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
            ->keyBy(fn (array $c) => $c['agent'] ? (int) $c['agent'] : null);

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
                if ($agent->auto_restart && $agent->status !== AgentStatus::Stopped) {
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
            if (($container['instance'] ?? $local) !== $local
                || ($agentId === null || ! $agentIds->contains($agentId))) {
                if (($container['instance'] ?? $local) === $local) {
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
        return 'http://' . $agent->container_name . ':' . $port;
    }
}

<?php

namespace App\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\ModelProvider;
use App\Support\DockerClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza el fragmento de ACLs del proxy squid (design.md D5):
 *   - taap_agents:   todas las IPs de agentes (activos o no; inofensivo)
 *   - taap_internet: solo las de agentes con internet_enabled
 *   - model_hosts:   hosts de los endpoints de los providers de modelo
 *
 * Escribe el fichero en el volumen compartido y `squid -k reconfigure`.
 */
class EgressSync
{
    public function __construct(
        protected DockerClient $docker,
    ) {
    }

    protected function aclFile(): string
    {
        return (string) config('taap.egress_acl_file', '/var/lib/taap/squid/taap-acl.conf');
    }

    public function sync(): void
    {
        $agents = Agent::where('ip', '!=', '')->get();
        $allIps = $agents->pluck('ip')->all();
        $internetIps = $agents
            ->filter(fn (Agent $a) => $a->internet_enabled && $a->status->isRunning())
            ->pluck('ip')
            ->all();

        $lines = [];
        $lines[] = count($allIps) > 0
            ? 'acl taap_agents src ' . implode(' ', $allIps)
            : 'acl taap_agents src 127.0.0.1';
        $lines[] = count($internetIps) > 0
            ? 'acl taap_internet src ' . implode(' ', $internetIps)
            : 'acl taap_internet src 127.0.0.1';

        $lines[] = $this->modelHostsLines();

        try {
            $aclFile = $this->aclFile();
            File::ensureDirectoryExists(dirname($aclFile));
            File::put($aclFile, implode("\n", $lines) . "\n");
        } catch (\Throwable $e) {
            Log::warning('egress-sync: no se pudo escribir el fragmento ACL: ' . $e->getMessage());

            return;
        }

        try {
            $this->docker->exec('taap-egress', ['squid', '-k', 'reconfigure'])->run();
        } catch (\Throwable $e) {
            Log::warning("egress-sync: reconfigure sin efecto (¿egress fuera?): {$e->getMessage()}");
        }
    }

    protected function modelHostsLines(): string
    {
        $hosts = [];

        // Model Proxy del orquestador: por donde todos los agentes llaman
        // a sus modelos (la clave real nunca sale de aqui).
        $hosts[] = parse_url((string) config('taap.proxy.internal_url'), PHP_URL_HOST) ?: '';
        $hosts[] = parse_url((string) config('taap.agents.orchestrator_url'), PHP_URL_HOST) ?: '';
        $hosts[] = '127.0.0.1';
        $hosts[] = 'localhost';

        foreach (ModelProvider::all() as $provider) {
            $hosts[] = (string) parse_url($provider->base_url, PHP_URL_HOST);
        }

        $lines = [];
        foreach (array_unique(array_filter($hosts)) as $host) {
            // URL absoluta (http://host:8010/...) para peticiones normales
            $lines[] = 'acl model_hosts_url url_regex ^https?://' . preg_quote($host, '/') . '([:]\\d+)?($|/)';
            // Taneos CONNECT (https): squid no aplica url_regex a CONNECT;
            // dstdomain cubre el dominio (exacto o subdominios)
            $lines[] = 'acl model_hosts_dns dstdomain .' . $host;
        }
        // centinela: nada mas
        $lines[] = 'acl model_hosts_url url_regex ^.!';

        return implode("\n", $lines);
    }
}

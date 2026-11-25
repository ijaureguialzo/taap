<?php

namespace App\Console\Commands;

use App\Agents\AgentManager;
use App\Models\Agent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rollout del FS compartido (shared-filesystem 8.1): aplica la nueva forma
 * de mounts a todos los agentes (o a los ids de `--only`): re-renderiza la
 * config y, si el set de mounts del contenedor vivo difiere, lo recrea
 * (flujo 6.5). Sin carpetas = solo arena; con carpetas = arena + binds.
 * Las sesiones se reanudan (viven en el volumen, id de sesion estable).
 *
 * Uso:
 *   php artisan taap:agents:reprovision             # todos
 *   php artisan taap:agents:reprovision --only=3,7 # algunos
 *   php artisan taap:agents:reprovision --dry-run  # solo informa
 */
class TaapAgentsReprovision extends Command
{
    protected $signature = 'taap:agents:reprovision
        {--only= : ids de agentes, coma-separados}
        {--dry-run : informa sin aplicar}';

    protected $description = 'Aplica la nueva forma de mounts (arena + carpetas) a los agentes reanudando las sesiones';

    public function handle(AgentManager $manager): int
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $this->option('only'))));
        $dry = (bool) $this->option('dry-run');

        $agents = Agent::query()
            ->whereNull('deleted_at')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->get();

        if ($agents->isEmpty()) {
            $this->info('No hay agentes que reprovisionar.');

            return self::SUCCESS;
        }

        $this->info(($dry ? '[dry-run] ' : '').'Procesando '.$agents->count().' agente(s)...');

        $failed = 0;
        foreach ($agents as $agent) {
            $container = $agent->container_name ? ' (contenedor '.$agent->container_name.')' : ' (sin contenedor)';

            if ($dry) {
                $needed = $manager->needsMountRecreate($agent);
                $this->line(sprintf(
                    '  %s #%d "%s"%s: %s',
                    $agent->runtime?->value,
                    $agent->id,
                    $agent->name,
                    $container,
                    $needed ? 'recrea el contenedor (cambian los mounts)' : 'docker restart (mounts ya correctos)',
                ));

                continue;
            }

            try {
                $manager->restart($agent);
                $this->line(sprintf(
                    '  OK %s #%d "%s"%s: config aplicada; la sesion se reanuda',
                    $agent->runtime?->value,
                    $agent->id,
                    $agent->name,
                    $container,
                ));
            } catch (\Throwable $e) {
                $failed++;
                Log::error("reprovision: agente {$agent->id} fallo: {$e->getMessage()}");
                $this->error(sprintf(
                    '  FAIL %s #%d "%s": %s',
                    $agent->runtime?->value,
                    $agent->id,
                    $agent->name,
                    $e->getMessage(),
                ));
            }
        }

        $this->info($failed === 0 ? 'Completado: todos los agentes aplicados.' : "Completado con {$failed} fallo(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

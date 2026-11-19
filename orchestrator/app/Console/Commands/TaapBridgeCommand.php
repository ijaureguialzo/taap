<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Log;

use App\Agents\AgentManager;
use App\Agents\AgentRuntimeAdapter;
use App\Agents\EventSink;
use App\Agents\RuntimeAdapters;
use App\Enums\AgentRuntime;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Support\ProcessStreams;
use Illuminate\Console\Command;
use Illuminate\Queue\Worker;
use Symfony\Component\Process\Process;

/**
 * Demonio 24/7 del orquestador: el corazon del "dejar instrucciones y
 * volver a ver como va" (design.md D6).
 *
 * Hilo principal: stream_select sobre los stdio de los agentes Pi
 * (docker attach) - normaliza y difunde eventos.
 * Fiber: worker de la cola taap-bridge (ordenes: attach/detach/mensajes/
 *        aprobaciones/config; todos rapidos - las corridas Hermes largas
 *        corren en un coproceso aparte).
 * Timers: polling de estado (Hermes) y reconciliacion DB<->Docker.
 */
class TaapBridgeCommand extends Command
{
    protected $signature = 'taap:bridge
        {--status-every=30 : seg entre polls de estado}
        {--reconcile-every=60 : seg entre reconciliaciones}
        {--offline-after=15 : seg sin contenedor vivo antes de marcar offline}';

    protected $description = 'Puente de larga vida entre el orquestador y los contenedores de agentes';

    /** @var array<int, array{agent: Agent, adapter: AgentRuntimeAdapter, deadSince: ?int}> */
    protected array $sessions = [];

    protected ?\App\Support\ProcessStreams $workerBridge = null;

    protected ?\App\Support\ProcessStreams $workerDefault = null;

    protected bool $running = true;

    public function handle(
        AgentManager $manager,
        RuntimeAdapters $adapters,
        EventSink $sink,
    ): int {
        /** @var Worker $worker */
        $worker = new Worker(
            $this->laravel['queue'],
            $this->laravel['events'],
            $this->laravel[\Illuminate\Contracts\Debug\ExceptionHandler::class],
            fn () => $this->laravel->isDownForMaintenance(),
        );
        $this->info('TaapBridge: arrancando');

        pcntl_signal(SIGTERM, fn () => $this->running = false);
        pcntl_signal(SIGINT, fn () => $this->running = false);

        // Estado inicial alineado
        $manager->reconcile();

        // Adjuntar todos los agentes que deben estar vivos
        foreach ($this->liveAgents() as $agent) {
            $this->ensureSession($agent, $adapters);
        }

        // Workers de cola como coprocesos: procesan jobs (BridgeSendMessage,
        // broadcasts) sin entrelazar pcntl con el bucle. Dos procesos
        // separados: un crash en una cola no mata la otra.
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $this->workerBridge = new ProcessStreams([
            $php, $artisan, 'queue:work',
            '--queue=taap-bridge', '--sleep=1', '--tries=2',
        ]);
        $this->workerDefault = new ProcessStreams([
            $php, $artisan, 'queue:work',
            '--queue=default', '--sleep=1', '--tries=2',
        ]);

        $lastStatus = time();
        $lastReconcile = time();
        $lastRecovery = time();

        while ($this->running) {
            pcntl_signal_dispatch();
            $now = time();

            // Pi: verificar vida del IPC y recoger eventos (sondeo de archivo)
            foreach ($this->sessions as $id => &$session) {
                $agent = $session['agent'];
                $adapter = $session['adapter'];

                if ($agent->runtime === AgentRuntime::Pi && ! $adapter->isAttached($agent)) {
                    $state = $this->dockerState($agent);
                    if ($state === 'running') {
                        // El contenedor vive: (re)adjuntar el IPC y despertar
                        $adapter->attach($agent);
                        if (! $agent->status->isRunning() && $agent->status !== AgentStatus::Starting) {
                            Log::info("bridge: forceStart {$agent->id} desde " . $agent->status->value);
                            $agent->forceFill(['status' => AgentStatus::Starting])->save();
                            $sink->dispatch($agent->refresh(), \App\Agents\AgentEvent::statusChange(AgentStatus::Starting->value));
                        }
                    } elseif ($agent->status->isRunning() || $agent->status === AgentStatus::Starting) {
                        // Grace: un docker restart rompe el IPC 1-2s
                        $since = $session['deadSince'] ?? null;
                        $session['deadSince'] = $since ?? $now;
                        if ($now - $session['deadSince'] > (int) $this->option('offline-after')) {
                            $agent->forceFill(['status' => AgentStatus::Offline])->save();
                            $sink->dispatch($agent->refresh(), \App\Agents\AgentEvent::statusChange(AgentStatus::Offline->value));
                        }
                    }
                } elseif ($agent->runtime === AgentRuntime::Pi) {
                    $session['deadSince'] = null;
                }

                foreach ($adapter->drain($agent) as $event) {
                    $sink->dispatch($agent->refresh(), $event);
                }

                // Arrancando: re-sondear (la respuesta de get_state puede
                // haber llegado antes de que el lector se pusiera al dia)
                if ($agent->runtime === AgentRuntime::Pi
                    && $agent->status === AgentStatus::Starting
                    && $now - ($session['lastProbe'] ?? 0) > 5) {
                    $session['lastProbe'] = $now;
                    $adapter->probe($agent);
                }
            }
            unset($session);

            // Mantener los workers coproceso vivos (re-arrancarlos si caen)
            if ($this->workerBridge === null || ! $this->workerBridge->isRunning()) {
                $this->workerBridge = new ProcessStreams([
                    PHP_BINARY, base_path('artisan'), 'queue:work',
                    '--queue=taap-bridge', '--sleep=1', '--tries=2',
                ]);
            }
            if ($this->workerDefault === null || ! $this->workerDefault->isRunning()) {
                $this->workerDefault = new ProcessStreams([
                    PHP_BINARY, base_path('artisan'), 'queue:work',
                    '--queue=default', '--sleep=1', '--tries=2',
                ]);
            }

            usleep(100_000);


            if ($now - $lastStatus >= (int) $this->option('status-every')) {
                $lastStatus = $now;
                foreach ($this->liveAgents() as $agent) {
                    if ($agent->runtime === AgentRuntime::Hermes) {
                        $status = $adapters->for($agent)->status($agent);
                        if ($status !== $agent->status) {
                            $agent->forceFill(['status' => $status])->save();
                            $sink->dispatch($agent->refresh(), \App\Agents\AgentEvent::statusChange($status->value));
                        }
                    }
                }
                // Sin session: vivos (arrancados por reconcile) y offline
                // cuyo contenedor vuelve a vivir (recuperacion tras restart)
                foreach ($this->liveAgents() as $agent) {
                    if (! isset($this->sessions[$agent->id])) {
                        $this->ensureSession($agent, $adapters);
                    }
                }
            }

            if ($now - $lastRecovery >= 10) {
                $lastRecovery = $now;
                // Offline cuyo contenedor vuelve a vivir -> despertar
                foreach (Agent::where('status', AgentStatus::Offline->value)->get() as $agent) {
                    if ($this->dockerState($agent) === 'running') {
                        $agent->forceFill(['status' => AgentStatus::Starting])->save();
                        $sink->dispatch($agent->refresh(), \App\Agents\AgentEvent::statusChange(AgentStatus::Starting->value));
                        $this->ensureSession($agent, $adapters);
                    }
                }
            }

            if ($now - $lastReconcile >= (int) $this->option('reconcile-every')) {
                $lastReconcile = $now;
                try {
                    $manager->reconcile();
                } catch (\Throwable $e) {
                    $this->error('reconcile: ' . $e->getMessage());
                }
                $this->sweepDeadAgents($adapters, $sink);
            }

            // Pausa minima para no pegarse al 100% de CPU
            usleep(100_000);
        }

        $this->info('TaapBridge: cierre limpio');
        $this->workerBridge?->stop(5);
        $this->workerDefault?->stop(5);
        foreach ($this->sessions as $session) {
            $session['adapter']->detach($session['agent']);
        }

        // Barrido final: no dejar bucles writer/lector huérfanos
        foreach ($this->sessions as $id => $session) {
            $dir = $session['dir'] ?? app(\App\Support\DockerClient::class)->rpcDir($id);
            app(\App\Support\DockerClient::class)->killCoprocess($dir, 'reader');
            app(\App\Support\DockerClient::class)->killCoprocess($dir, 'writer');
        }

        return self::SUCCESS;
    }

    protected function liveAgents()
    {
        return Agent::whereNotIn('status', [AgentStatus::Stopped->value, AgentStatus::Offline->value])
            ->get()
            ->values();
    }

    protected function ensureSession(Agent $agent, RuntimeAdapters $adapters): void
    {
        if (isset($this->sessions[$agent->id])) {
            if ($this->sessions[$agent->id]['adapter']->isAttached($agent)) {
                return;
            }
            unset($this->sessions[$agent->id]);
        }

        $adapter = $adapters->for($agent);
        $adapter->attach($agent);
        $this->sessions[$agent->id] = [
            'agent' => $agent->refresh(),
            'adapter' => $adapter,
        ];
    }

    protected function dockerState(Agent $agent): ?string
    {
        return app(\App\Support\DockerClient::class)->status($agent->container_name);
    }

    protected function sweepDeadAgents(RuntimeAdapters $adapters, EventSink $sink): void
    {
        foreach (Agent::with('token')->get() as $agent) {
            if ($agent->status === AgentStatus::Stopped) {
                continue;
            }

            if ($this->dockerState($agent) === null && $agent->status->isRunning()) {
                $agent->status = $agent->auto_restart ? AgentStatus::Error : AgentStatus::Offline;
                $agent->save();
                $sink->dispatch($agent->refresh(), \App\Agents\AgentEvent::statusChange($agent->status->value));
            }
        }
    }
}

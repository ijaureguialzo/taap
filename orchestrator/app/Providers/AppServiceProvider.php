<?php

namespace App\Providers;

use App\Agents\AgentManager;
use App\Agents\ConfigRenderer;
use App\Agents\EgressSync;
use App\Agents\EventSink;
use App\Agents\RuntimeAdapters;
use App\Services\FilesystemService;
use App\Support\DockerClient;
use App\Support\ForceHttps;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AgentManager::class);
        $this->app->singleton(ConfigRenderer::class);
        $this->app->singleton(EgressSync::class);
        $this->app->singleton(EventSink::class);
        $this->app->singleton(RuntimeAdapters::class);
        $this->app->singleton(DockerClient::class);

        // FS compartido (shared-filesystem): el nucleo lo consumen la API v1
        // y la web (Livewire); la raiz fisica viene de la config (2.3).
        $this->app->singleton(FilesystemService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Fuerza https por entorno (change configurable-https-urls):
        // TAAP_FORCE_HTTPS=true (deploy TLS tras el proxy) / false / sin
        // definir -> derivacion del esquema de APP_URL (2f56d5b: el dev
        // local en Docker con APP_URL=http no debe redirigir a https).
        URL::forceHttps(
            ForceHttps::resolve(config('taap.force_https'), (string) config('app.url'))
        );

        // El healthcheck de Docker golpea /up: que refleje la BD. Sin esta
        // comprobacion el contenedor reporta healthy aunque no pueda hablar
        // con Postgres (y la pila arranca a medias).
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::connection()->select('select 1');
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

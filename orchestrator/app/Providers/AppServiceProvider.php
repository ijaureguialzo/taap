<?php

namespace App\Providers;

use App\Agents\AgentManager;
use App\Agents\ConfigRenderer;
use App\Agents\EgressSync;
use App\Agents\EventSink;
use App\Agents\RuntimeAdapters;
use App\Services\FilesystemService;
use App\Support\DockerClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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

        // Fuerza https solo si la URL de la app la declara (deploy TLS tras el
        // proxy). Con APP_ENV=production + APP_URL=http (dev local en Docker)
        // no fuerza, para que los redirects no muestren https.
        URL::forceHttps(str_starts_with((string) config('app.url'), 'https://'));
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

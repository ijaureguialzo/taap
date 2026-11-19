<?php

namespace App\Providers;

use App\Agents\AgentManager;
use App\Agents\ConfigRenderer;
use App\Agents\EgressSync;
use App\Agents\EventSink;
use App\Agents\RuntimeAdapters;
use App\Support\DockerClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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

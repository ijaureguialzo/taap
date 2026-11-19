<?php

namespace App\Providers;

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
        $this->app->singleton(\App\Agents\AgentManager::class);
        $this->app->singleton(\App\Agents\ConfigRenderer::class);
        $this->app->singleton(\App\Agents\EgressSync::class);
        $this->app->singleton(\App\Agents\EventSink::class);
        $this->app->singleton(\App\Agents\RuntimeAdapters::class);
        $this->app->singleton(\App\Support\DockerClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Idioma (spec localizacion): preferencia del usuario > sesion >
     * navegador. Fallback `es`; solo es/en/eu soportados.
     */
    protected function resolveLocale(): void
    {
        $supported = ['es', 'en', 'eu'];

        $user = auth()->user();
        $session = session();

        if ($user?->locale && in_array($user->locale, $supported, true)) {
            app()->setLocale($user->locale);
        } elseif (($session->get('locale') !== null && in_array($session->get('locale'), $supported, true))) {
            app()->setLocale($session->get('locale'));
        } elseif (($accept = $this->fromAcceptHeader()) !== null) {
            app()->setLocale($accept);
            $session->put('locale', $accept);
        } else {
            app()->setLocale('es');
        }
    }

    /** @return string|null */
    protected function fromAcceptHeader(): ?string
    {
        $header = request()->header('Accept-Language', '');
        $preferred = [];
        foreach (explode(',', $header) as $part) {
            [$tag, $params] = array_pad(explode(';', $part, 2), 2, '');
            $q = 1.0;
            foreach (explode(';', $params) as $p) {
                if (str_starts_with(trim($p), 'q=')) {
                    $q = (float) trim(substr($p, 2));
                }
            }
            $preferred[] = [trim($tag), $q];
        }
        usort($preferred, fn ($a, $b) => $b[1] <=> $a[1]);
        $supported = ['es', 'en', 'eu'];
        foreach ($preferred as [$tag, $q]) {
            if ($q <= 0) {
                continue;
            }
            $base = strtolower(substr($tag, 0, 2));
            if (in_array($base, $supported, true)) {
                return $base;
            }
        }

        return null;
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

<?php

namespace Tests\Feature\Providers;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * Aplica TAAP_FORCE_HTTPS por el provider (configurable-https-urls): fija
 * la config, re-ejecuta AppServiceProvider::boot() (es idempotente) y
 * afirma el esquema real de url(). Cubre los 3 escenarios de la spec
 * web-deployment ("Esquema de URL configurable").
 */
final class ForceHttpsTest extends TestCase
{
    private function bootWith(?bool $forceHttps, string $appUrl): void
    {
        config(['taap.force_https' => $forceHttps, 'app.url' => $appUrl]);

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_deploy_tls_con_app_url_interna_http_fuerza_https(): void
    {
        $this->bootWith(true, 'http://127.0.0.1:8000');

        $this->assertStringStartsWith('https://', url('/assets/app.css'));
    }

    public function test_sin_variable_y_app_url_http_mantiene_http(): void
    {
        $this->bootWith(null, 'http://127.0.0.1:8011');

        $this->assertStringStartsWith('http://', url('/assets/app.css'));
    }

    public function test_sin_variable_y_app_url_https_deriva_https(): void
    {
        $this->bootWith(null, 'https://taap.itlent.eus');

        $this->assertStringStartsWith('https://', url('/assets/app.css'));
    }

    public function test_falso_explicito_tiene_preferencia_sobre_app_url_https(): void
    {
        $this->bootWith(false, 'https://taap.itlent.eus');

        $this->assertStringStartsWith('http://', url('/assets/app.css'));
    }
}

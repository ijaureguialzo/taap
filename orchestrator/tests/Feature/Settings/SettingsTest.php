<?php

namespace Tests\Feature\Settings;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_apply_when_key_is_missing(): void
    {
        $settings = app(Settings::class);

        $this->assertSame('true', $settings->get('registration_enabled'));
        $this->assertTrue($settings->isRegistrationEnabled());
    }

    public function test_value_is_persisted_and_read_back(): void
    {
        $settings = app(Settings::class);

        $settings->setRegistrationEnabled(false);

        $this->assertSame('false', $settings->get('registration_enabled'));
        $this->assertFalse($settings->isRegistrationEnabled());
    }

    public function test_set_overwrites_existing_value(): void
    {
        $settings = app(Settings::class);

        $settings->set('registration_enabled', 'false');
        $settings->set('registration_enabled', 'true');

        $this->assertSame('true', $settings->get('registration_enabled'));
    }

    public function test_set_with_null_removes_the_key(): void
    {
        $settings = app(Settings::class);

        $settings->set('registration_enabled', 'false');
        $this->assertFalse($settings->boolean('registration_enabled'));

        $settings->set('registration_enabled', null);

        // Tras borrar la clave, reaparece el default del esquema.
        $this->assertTrue($settings->boolean('registration_enabled'));
        $this->assertSame('true', $settings->get('registration_enabled'));
    }

    public function test_boolean_parsing_is_tolerant(): void
    {
        $settings = app(Settings::class);

        $settings->set('registration_enabled', 'yes');
        $this->assertTrue($settings->boolean('registration_enabled'));

        $settings->set('registration_enabled', '1');
        $this->assertTrue($settings->boolean('registration_enabled'));

        $settings->set('registration_enabled', 'off');
        $this->assertFalse($settings->boolean('registration_enabled'));
    }
}

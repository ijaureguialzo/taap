<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@taap.test']);
    }

    public function test_admin_can_read_settings(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJson(['registration_enabled' => true]);
    }

    public function test_non_admin_cannot_read_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/settings')
            ->assertForbidden();
    }

    public function test_admin_can_toggle_registration_and_it_persists(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/settings', ['registration_enabled' => false])
            ->assertOk()
            ->assertJson(['registration_enabled' => false]);

        $this->assertFalse(app(Settings::class)->isRegistrationEnabled());
    }

    public function test_non_admin_cannot_toggle_registration(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/settings', ['registration_enabled' => false])
            ->assertForbidden();

        $this->assertTrue(app(Settings::class)->isRegistrationEnabled());
    }

    public function test_toggle_reflects_in_home_and_register(): void
    {
        // El estado persistido (compartido por web y home/register) lo fija el toggle.
        app(Settings::class)->setRegistrationEnabled(false);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('/register');

        $this->get(route('register'))
            ->assertRedirect(route('home', absolute: false));
    }

    public function test_settings_validates_boolean_value(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/settings', ['registration_enabled' => 'not-a-boolean'])
            ->assertStatus(422);
    }
}

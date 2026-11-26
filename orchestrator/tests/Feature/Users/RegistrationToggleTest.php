<?php

namespace Tests\Feature\Users;

use App\Livewire\Admin\RegistrationToggle;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
    }

    protected function makeAdmin(): User
    {
        return User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@taap.test']);
    }

    public function test_home_shows_register_link_when_registration_is_open(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('/register');
    }

    public function test_home_hides_register_link_when_registration_is_closed(): void
    {
        app(Settings::class)->setRegistrationEnabled(false);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('/register');
    }

    public function test_register_get_redirects_to_home_with_message_when_closed(): void
    {
        app(Settings::class)->setRegistrationEnabled(false);

        $this->get(route('register'))
            ->assertRedirect(route('home', absolute: false))
            ->assertSessionHas('error');
    }

    public function test_register_post_redirects_and_creates_no_user_when_closed(): void
    {
        app(Settings::class)->setRegistrationEnabled(false);

        $this->post(route('register.store'), [
            'name' => 'No Nuevo',
            'email' => 'no-nuevo@taap.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('home', absolute: false));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'no-nuevo@taap.test']);
    }

    public function test_admin_can_toggle_registration_and_it_persists(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test(RegistrationToggle::class)
            ->assertSet('enabled', true)
            ->set('enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(app(Settings::class)->isRegistrationEnabled());
    }

    public function test_toggle_card_defaults_open(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test(RegistrationToggle::class)
            ->assertSet('enabled', true);
    }

    public function test_registration_is_open_by_default(): void
    {
        $this->get(route('register'))->assertOk();
    }
}

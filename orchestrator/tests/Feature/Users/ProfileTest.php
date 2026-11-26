<?php

namespace Tests\Feature\Users;

use App\Livewire\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'User Test',
            'email' => 'test-user@taap.test',
            // El factory usa 'password' por defecto.
            'locale' => 'es',
        ]);
        $this->actingAs($this->user);
        app()->setLocale('es');
    }

    public function test_profile_page_is_displayed(): void
    {
        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('test-user@taap.test')
            ->assertSee('readonly', false);
    }

    public function test_user_can_edit_name_and_locale(): void
    {
        Livewire::test(Profile::class)
            ->set('name', 'Nuevo Nombre')
            ->set('locale', 'eu')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Nuevo Nombre', $this->user->fresh()->name);
        $this->assertSame('eu', $this->user->fresh()->locale);
    }

    public function test_password_fields_may_be_left_empty_and_save_keeps_password(): void
    {
        Livewire::test(Profile::class)
            ->set('name', 'Solo Nombre')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Solo Nombre', $this->user->fresh()->name);
        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        Livewire::test(Profile::class)
            ->set('locale', 'fr')
            ->call('save')
            ->assertHasErrors(['locale']);

        $this->assertSame('es', $this->user->fresh()->locale);
    }

    public function test_password_change_requires_current_password(): void
    {
        Livewire::test(Profile::class)
            ->set('current_password', 'incorrecta-1')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('save')
            ->assertHasErrors(['current_password']);

        $this->assertFalse(Hash::check('password123', $this->user->fresh()->password));
    }

    public function test_password_change_requires_confirmation(): void
    {
        Livewire::test(Profile::class)
            ->set('current_password', 'password')
            ->set('password', 'password123')
            ->set('password_confirmation', 'distinta123')
            ->call('save')
            ->assertHasErrors(['password']);

        $this->assertFalse(Hash::check('password123', $this->user->fresh()->password));
    }

    public function test_password_change_applies_and_revokes_tokens_and_other_sessions(): void
    {
        $token = $this->user->createToken('api')->plainTextToken;
        DB::table('sessions')->insert([
            'id' => 'old-session',
            'user_id' => $this->user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => now()->unix(),
        ]);

        Livewire::test(Profile::class)
            ->set('current_password', 'password')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('password123', $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertNull(DB::table('sessions')->where('id', 'old-session')->first());
    }

    public function test_email_is_readonly_on_page(): void
    {
        $html = Livewire::test(Profile::class)->html();

        $this->assertStringContainsString('readonly', $html);
        $this->assertStringContainsString('test-user@taap.test', $html);
    }
}

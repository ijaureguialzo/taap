<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La unica configuracion de perfil del usuario en v1 es el idioma
 * (spec localizacion). El kit inicial de Laravel no aplica a esta app.
 */
class LanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_language_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create(['locale' => 'es']));

        $this->get(route('profile.language'))->assertOk();
    }

    public function test_language_preference_can_be_updated(): void
    {
        $user = User::factory()->create(['locale' => 'es']);

        $this->actingAs($user)->post(route('profile.language'), ['locale' => 'eu']);

        $this->assertSame('eu', $user->fresh()->locale);
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $user = User::factory()->create(['locale' => 'es']);

        $this->actingAs($user)->post(route('profile.language'), ['locale' => 'fr']);

        $this->assertSame('es', $user->fresh()->locale);
    }
}

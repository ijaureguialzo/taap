<?php

namespace Tests\Feature\Models;

use App\Livewire\Models\Index;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Catalogo compartido de modelos (improve-provider-model-management 3.x):
 * todo usuario autenticado ve todos los modelos; solo el admin ve los
 * botones de accion.
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
        // En Livewire::test no corre el middleware SetLocale: fijar el
        // idioma por defecto de la spec para poder asierto las cadenas.
        app()->setLocale('es');
    }

    public function test_non_admin_sees_all_models_read_only(): void
    {
        $other = User::factory()->create();
        $provAdmin = ModelProvider::factory()->create(['name' => 'prov-admin', 'created_by' => $this->admin->id]);
        $provOther = ModelProvider::factory()->create(['name' => 'prov-other', 'created_by' => $other->id]);
        Model::factory()->create(['provider_id' => $provAdmin->id, 'model_id' => 'm-admin', 'name' => 'Modelo admin']);
        Model::factory()->create(['provider_id' => $provOther->id, 'model_id' => 'm-other', 'name' => 'Modelo other']);

        $this->actingAs($other);
        $html = Livewire::test(Index::class)->html();

        // Catálogo compartido: ve los modelos de proveedores creados por otros
        $this->assertStringContainsString('Modelo admin', $html);
        $this->assertStringContainsString('Modelo other', $html);
        // Solo lectura: sin botones de accion
        $this->assertStringNotContainsString('Nuevo modelo', $html);
        $this->assertStringNotContainsString('delete(', $html);
    }

    public function test_admin_sees_all_models_with_actions(): void
    {
        $other = User::factory()->create();
        $provAdmin = ModelProvider::factory()->create(['name' => 'prov-admin', 'created_by' => $this->admin->id]);
        $provOther = ModelProvider::factory()->create(['name' => 'prov-other', 'created_by' => $other->id]);
        Model::factory()->create(['provider_id' => $provAdmin->id, 'model_id' => 'm-admin', 'name' => 'Modelo admin']);
        Model::factory()->create(['provider_id' => $provOther->id, 'model_id' => 'm-other', 'name' => 'Modelo other']);

        $html = Livewire::test(Index::class)->html();

        $this->assertStringContainsString('Modelo admin', $html);
        $this->assertStringContainsString('Modelo other', $html);
        $this->assertStringContainsString('Nuevo modelo', $html);
        $this->assertStringContainsString('delete(', $html);
    }

    public function test_non_admin_save_is_rejected_without_mutation(): void
    {
        $other = User::factory()->create();
        $prov = ModelProvider::factory()->create(['created_by' => $this->admin->id]);
        $this->actingAs($other);

        Livewire::test(Index::class, [
            'provider_id' => $prov->id,
            'model_id' => 'intruso',
            'name' => 'Intruso',
        ])->call('save')->assertSee('Solo los administradores');

        $this->assertSame(0, Model::count());
    }

    public function test_non_admin_delete_is_rejected_without_mutation(): void
    {
        $other = User::factory()->create();
        $prov = ModelProvider::factory()->create(['created_by' => $this->admin->id]);
        $m = Model::factory()->create(['provider_id' => $prov->id, 'model_id' => 'm1']);
        $this->actingAs($other);

        Livewire::test(Index::class)->call('delete', $m->id)->assertSee('Solo los administradores');

        $this->assertNotNull($m->refresh());
        $this->assertNotNull($prov->refresh());
    }

    public function test_admin_can_manage_models_of_providers_created_by_others(): void
    {
        $other = User::factory()->create();
        $prov = ModelProvider::factory()->create(['created_by' => $other->id]);

        Livewire::test(Index::class, [
            'provider_id' => $prov->id,
            'model_id' => 'm-creado',
            'name' => 'Nombre nuevo',
        ])->call('save');

        $m = Model::where('provider_id', $prov->id)->where('model_id', 'm-creado')->first();
        $this->assertNotNull($m);
        $this->assertSame('Nombre nuevo', $m->name);
    }
}

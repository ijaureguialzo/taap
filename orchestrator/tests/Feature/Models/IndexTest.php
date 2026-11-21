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
}

<?php

namespace Tests\Feature\Models;

use App\Livewire\Models\Index;
use App\Models\Agent;
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

    public function test_admin_can_edit_model_fields_and_provider_stays(): void
    {
        $other = User::factory()->create();
        $prov = ModelProvider::factory()->create(['created_by' => $other->id]);
        $m = Model::factory()->create([
            'provider_id' => $prov->id,
            'model_id' => 'm1',
            'name' => 'Original',
            'context_window' => 8000,
            'max_tokens' => 2000,
            'capabilities' => ['tools'],
        ]);
        // En uso por un agente: la edicion no lo debe interrumpir
        $agent = Agent::factory()->create(['model_id' => $m->id]);

        Livewire::test(Index::class, [
            'edit_id' => $m->id,
            'provider_id' => $m->provider_id,
            'model_id' => 'm1',
            'name' => 'Nuevo nombre',
            'context_window' => 32000,
            'max_tokens' => 4096,
            'capabilities' => ['tools', 'vision'],
        ])->call('save');

        $m->refresh();
        $this->assertSame('Nuevo nombre', $m->name);
        $this->assertSame(32000, $m->context_window);
        $this->assertSame(4096, $m->max_tokens);
        $this->assertSame(['tools', 'vision'], $m->capabilities);
        // D5: el proveedor queda intacto
        $this->assertSame($prov->id, $m->provider_id);
        // El agente en uso sigue ahi (FK y datos intactos)
        $this->assertNotNull($agent->refresh());
        $this->assertSame($m->id, $agent->fresh()->model_id);
    }

    public function test_edit_rejects_duplicate_model_id_of_same_provider(): void
    {
        $prov = ModelProvider::factory()->create(['created_by' => $this->admin->id]);
        $m = Model::factory()->create(['provider_id' => $prov->id, 'model_id' => 'm1', 'name' => 'Uno']);
        Model::factory()->create(['provider_id' => $prov->id, 'model_id' => 'm2', 'name' => 'Dos']);

        Livewire::test(Index::class, [
            'edit_id' => $m->id,
            'provider_id' => $prov->id,
            'model_id' => 'm2', // duplicado del mismo provider
            'name' => 'X',
        ])->call('save')->assertHasErrors('model_id');

        $m->refresh();
        $this->assertSame('m1', $m->model_id, 'el guardado debe quedar invalidado');
    }

    public function test_edit_allows_keeping_own_model_id(): void
    {
        $provA = ModelProvider::factory()->create(['created_by' => $this->admin->id]);
        $provB = ModelProvider::factory()->create(['created_by' => $this->admin->id]);
        $m = Model::factory()->create(['provider_id' => $provA->id, 'model_id' => 'gpt-4o', 'name' => 'A']);
        // El mismo model_id existe en OTRO provider: no debe colisionar
        Model::factory()->create(['provider_id' => $provB->id, 'model_id' => 'gpt-4o', 'name' => 'B']);

        Livewire::test(Index::class, [
            'edit_id' => $m->id,
            'provider_id' => $provA->id,
            'model_id' => 'gpt-4o', // su propio valor (ignore)
            'name' => 'Renombrado',
        ])->call('save')->assertHasNoErrors();

        $this->assertSame('Renombrado', $m->refresh()->name);
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

<?php

namespace Tests\Feature\Providers;

use App\Livewire\Providers\Index;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Salud de proveedor (improve-provider-model-management 1.x): la
 * comprobacion persiste el resultado (status + last_checked_at) y el
 * guardado la ejecuta (el provider queda persistido en ambos casos).
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

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function provider(array $attrs = []): ModelProvider
    {
        return ModelProvider::factory()->create($attrs + ['created_by' => $this->admin->id]);
    }

    public function test_check_success_persists_ok_status(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'gpt-4o']]], 200)]);
        $p = $this->provider();

        Livewire::test(Index::class)->call('check', $p->id);

        $p->refresh();
        $this->assertSame(ModelProvider::STATUS_OK, $p->status);
        $this->assertNotNull($p->last_checked_at);
    }

    public function test_check_http_error_persists_error_status(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);
        $p = $this->provider();

        Livewire::test(Index::class)->call('check', $p->id);

        $p->refresh();
        $this->assertSame(ModelProvider::STATUS_ERROR, $p->status);
        $this->assertNotNull($p->last_checked_at);
    }

    public function test_check_timeout_persists_error_status(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);
        $p = $this->provider();

        Livewire::test(Index::class)->call('check', $p->id);

        $p->refresh();
        $this->assertSame(ModelProvider::STATUS_ERROR, $p->status);
        $this->assertNotNull($p->last_checked_at);
    }

    public function test_check_uses_format_dependent_endpoint(): void
    {
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->url();

            return Http::response(['data' => []], 200);
        });

        // El base_url openai YA lleva su /v1; el anthropic NO lo lleva (D3).
        $openai = $this->provider(['type' => 'openai', 'base_url' => 'http://gw.local:8000/v1']);
        $anthropic = $this->provider(['type' => 'anthropic', 'base_url' => 'http://gw.local:8901']);

        Livewire::test(Index::class)->call('check', $openai->id);
        Livewire::test(Index::class)->call('check', $anthropic->id);

        $this->assertSame(['http://gw.local:8000/v1/models', 'http://gw.local:8901/v1/models'], $seen);
    }

    public function test_save_with_dead_endpoint_persists_provider_and_marks_unhealthy(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        // El flash se consume al terminar la actualizacion (Livewire
        // SupportRedirects), asi que se asierto en el HTML re-renderizado.
        Livewire::test(Index::class, [
            'name' => 'gw',
            'type' => 'openai',
            'base_url' => 'http://gw.local:8000/v1',
            'api_key' => 'sk-test',
        ])->call('save')->assertSee('Guardado, pero el endpoint no respondió (marcado no sano). (500)');

        $p = ModelProvider::where('name', 'gw')->first();
        $this->assertNotNull($p, 'el provider debe guardarse aunque el endpoint este caido');
        $this->assertSame(ModelProvider::STATUS_ERROR, $p->status);
        $this->assertNotNull($p->last_checked_at);
    }

    public function test_save_success_persists_provider_and_marks_healthy(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        Livewire::test(Index::class, [
            'name' => 'gw',
            'type' => 'openai',
            'base_url' => 'http://gw.local:8000/v1',
            'api_key' => 'sk-test',
        ])->call('save')->assertSee('Guardado.');

        $p = ModelProvider::where('name', 'gw')->first();
        $this->assertNotNull($p);
        $this->assertSame(ModelProvider::STATUS_OK, $p->status);
    }

    public function test_non_admin_sees_all_providers_read_only(): void
    {
        $other = User::factory()->create();
        $pAdmin = $this->provider(['name' => 'prov-admin']);
        $pOther = $this->provider(['name' => 'prov-other', 'created_by' => $other->id]);

        $this->actingAs($other);
        $html = Livewire::test(Index::class)->html();

        // Catálogo compartido: ve los creados por otros
        $this->assertStringContainsString('prov-admin', $html);
        $this->assertStringContainsString('prov-other', $html);
        // Solo lectura: sin botones de accion
        $this->assertStringNotContainsString('Nuevo proveedor', $html);
        $this->assertStringNotContainsString('startEdit(', $html);
        $this->assertStringNotContainsString('check(', $html);
        $this->assertStringNotContainsString('delete(', $html);
        $this->assertStringNotContainsString('Comprobar conectividad', $html);
    }

    public function test_admin_sees_all_providers_with_actions(): void
    {
        $other = User::factory()->create();
        $this->provider(['name' => 'prov-admin']);
        $this->provider(['name' => 'prov-other', 'created_by' => $other->id]);

        $html = Livewire::test(Index::class)->html();

        $this->assertStringContainsString('prov-admin', $html);
        $this->assertStringContainsString('prov-other', $html);
        $this->assertStringContainsString('Nuevo proveedor', $html);
        $this->assertStringContainsString('startEdit(', $html);
        $this->assertStringContainsString('check(', $html);
        $this->assertStringContainsString('delete(', $html);
    }

    public function test_non_admin_save_create_is_rejected_without_mutation(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other);
        $countBefore = ModelProvider::count();

        Http::fake(['*' => Http::response(['data' => []], 200)]);

        Livewire::test(Index::class, [
            'name' => 'intruso',
            'type' => 'openai',
            'base_url' => 'http://x.local/v1',
            'api_key' => 'k',
        ])->call('save')->assertSee('Solo los administradores');

        $this->assertSame($countBefore, ModelProvider::count());
        $this->assertNull(ModelProvider::where('name', 'intruso')->first());
    }

    public function test_non_admin_save_update_is_rejected_without_mutation(): void
    {
        $other = User::factory()->create();
        $p = $this->provider(['name' => 'prov', 'api_key' => 'clave-original']);
        $this->actingAs($other);

        Http::fake(['*' => Http::response(['data' => []], 200)]);

        Livewire::test(Index::class, [
            'edit_id' => $p->id,
            'name' => 'modificado',
            'type' => $p->type,
            'base_url' => $p->base_url,
            'api_key' => 'otra',
        ])->call('save')->assertSee('Solo los administradores');

        $p->refresh();
        $this->assertSame('prov', $p->name);
        $this->assertSame('clave-original', $p->api_key);
    }

    public function test_non_admin_check_is_rejected_without_mutation(): void
    {
        $other = User::factory()->create();
        $p = $this->provider(); // status = null (nunca comprobado)
        $this->actingAs($other);

        Http::fake(['*' => Http::response(['data' => []], 200)]);

        Livewire::test(Index::class)->call('check', $p->id)->assertSee('Solo los administradores');

        $p->refresh();
        $this->assertNull($p->status);
        $this->assertNull($p->last_checked_at);
    }

    public function test_non_admin_delete_is_rejected_without_mutation(): void
    {
        $other = User::factory()->create();
        $p = $this->provider();
        $m = Model::factory()->create(['provider_id' => $p->id]);
        $this->actingAs($other);

        Livewire::test(Index::class)->call('delete', $p->id)->assertSee('Solo los administradores');

        $this->assertNotNull($p->refresh());
        $this->assertNotNull($m->refresh());
    }

    public function test_admin_save_update_can_touch_providers_created_by_others(): void
    {
        $other = User::factory()->create();
        $p = $this->provider(['name' => 'prov', 'created_by' => $other->id]);

        Http::fake(['*' => Http::response(['data' => []], 200)]);

        Livewire::test(Index::class, [
            'edit_id' => $p->id,
            'name' => 'modificado',
            'type' => $p->type,
            'base_url' => $p->base_url,
        ])->call('save');

        $p->refresh();
        $this->assertSame('modificado', $p->name);
        // La edicion del admin no pisa al creador original (auditoria, D1)
        $this->assertSame($other->id, $p->created_by);
    }

    public function test_health_badges_render_three_states(): void
    {
        $checked = now()->subDay();
        $this->provider(['status' => ModelProvider::STATUS_OK, 'last_checked_at' => $checked]);
        $this->provider(['status' => ModelProvider::STATUS_ERROR, 'last_checked_at' => $checked]);
        $this->provider(['status' => null, 'last_checked_at' => null]);

        $html = Livewire::test(Index::class)->html();

        $this->assertStringContainsString('Sano', $html);
        $this->assertStringContainsString('No sano', $html);
        $this->assertStringContainsString('Sin comprobar', $html);
        $this->assertStringContainsString('Últ. comprobación: '.$checked->format('Y-m-d H:i'), $html);
    }

    public function test_save_discovers_unregistered_models_and_keeps_curated_ones(): void
    {
        $p = $this->provider();
        $existing = Model::factory()->create([
            'provider_id' => $p->id,
            'model_id' => 'gpt-4o',
            'name' => 'Mi GPT curado',
        ]);

        Http::fake(['*' => Http::response(['data' => [
            ['id' => 'gpt-4o'],
            ['id' => 'gpt-4o-mini'],
            ['id' => 'o1'],
        ]], 200)]);

        Livewire::test(Index::class, [
            'edit_id' => $p->id,
            'name' => $p->name,
            'type' => $p->type,
            'base_url' => $p->base_url,
        ])->call('save')->assertSee('Guardado. 2 modelos nuevos descubiertos.');

        $modelIds = Model::where('provider_id', $p->id)->pluck('model_id')->all();
        sort($modelIds);
        $this->assertSame(['gpt-4o', 'gpt-4o-mini', 'o1'], $modelIds);
        // Merge no destructivo (D4): la fila curada no se toca (nombre propio intacto)
        $this->assertSame('Mi GPT curado', $existing->refresh()->name);
    }

    public function test_save_does_not_discover_for_anthropic_provider(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'claude-sonnet-4-5']]], 200)]);

        Livewire::test(Index::class, [
            'name' => 'ant',
            'type' => 'anthropic',
            'base_url' => 'http://gw.local:8901',
            'api_key' => 'k',
        ])->call('save');

        $this->assertSame(0, Model::count(), 'para anthropic no hay descubrimiento automatico (D4)');
    }

    public function test_discovery_failure_does_not_create_models_and_keeps_provider(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        Livewire::test(Index::class, [
            'name' => 'gw',
            'type' => 'other',
            'base_url' => 'http://gw.local:9000/v1',
            'api_key' => 'k',
        ])->call('save');

        $p = ModelProvider::where('name', 'gw')->first();
        $this->assertNotNull($p);
        $this->assertSame(0, Model::where('provider_id', $p->id)->count());
    }

    public function test_update_with_dead_endpoint_keeps_provider_and_marks_unhealthy(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $p = $this->provider(['name' => 'gw']);

        Livewire::test(Index::class, [
            'edit_id' => $p->id,
            'name' => 'gw',
            'type' => 'openai',
            'base_url' => 'http://gw.local:8000/v1',
        ])->call('save')->assertSee('Guardado, pero el endpoint no respondió (marcado no sano). (500)');

        $p->refresh();
        $this->assertSame('gw', $p->name);
        $this->assertSame(ModelProvider::STATUS_ERROR, $p->status);
    }
}

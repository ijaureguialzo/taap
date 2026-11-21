<?php

namespace Tests\Feature\Providers;

use App\Livewire\Providers\Index;
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

<?php

namespace Tests\Feature\Providers;

use App\Agents\AgentEvent;
use App\Agents\AgentManager;
use App\Agents\ChatService;
use App\Agents\EventSink;
use App\Enums\AgentStatus;
use App\Jobs\BridgeSendMessage;
use App\Livewire\Agents\Create;
use App\Livewire\Models\Index as ModelsIndex;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Models\Agent;
use App\Models\AgentToken;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Flujo completo: salud del provider ↔ ciclo de vida del agente
 * (improve-provider-model-management 8.1).
 *
 * La pata "agente -> provider" pasa por el proxy de modelo
 * (`/api/proxy/agents/{id}/v1/...`): el contenedor nunca ve la clave del
 * proveedor y las salidas del provider (200/422) se relayan tal cual.
 * AgentManager se fakea (no hay Docker en test); el upstream va por
 * `Http::fake()`; el puente (BridgeSendMessage) va a `Queue::fake()`.
 */
class HealthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->create();
        $this->app->setLocale('es');
        Queue::fake();
    }

    protected function provider(string $base = 'http://127.0.0.1:9999/v1', ?string $status = null): ModelProvider
    {
        $p = ModelProvider::factory()->create([
            'type' => 'openai',
            'name' => 'prov-test',
            'base_url' => $base,
            'api_key' => 'sk-test-123',
            'created_by' => $this->user->id,
        ]);

        if ($status !== null) {
            $p->forceFill(['status' => $status, 'last_checked_at' => now()])->save();
        }

        return $p;
    }

    protected function model(ModelProvider $p, string $modelId = 'gpt-test'): Model
    {
        return Model::factory()->create([
            'provider_id' => $p->id,
            'model_id' => $modelId,
            'name' => 'Modelo '.$modelId,
        ]);
    }

    /** Agente en servicio con token de proxy emitido. */
    protected function agent(Model $m): Agent
    {
        $a = Agent::factory()->running()->create([
            'owner_id' => $this->user->id,
            'model_id' => $m->id,
        ]);

        AgentToken::issueFor($a)->save();

        return $a;
    }

    /** Fake minimo de AgentManager: provision/start/stop no tocan Docker. */
    protected function fakeManager(): AgentManager
    {
        $manager = new class extends AgentManager
        {
            public function __construct()
            {
                // sin DockerClient/ConfigRenderer: los metodos fakeados no los usan
            }

            public function provision(Agent $agent): Agent
            {
                return $agent;
            }

            public function start(Agent $agent): Agent
            {
                return $agent;
            }

            public function stop(Agent $agent): Agent
            {
                return $agent;
            }
        };
        $this->app->instance(AgentManager::class, $manager);

        return $manager;
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(Agent $a): array
    {
        return ['Authorization' => 'Bearer '.$a->token->token];
    }

    /**
     * @return TestResponse<Response>
     */
    protected function chatProxy(Agent $a, ?Model $m): TestResponse
    {
        $modelId = $m?->model_id;

        return $this->postJson(
            '/api/proxy/agents/'.$a->id.'/v1/chat/completions',
            ['model' => $modelId ?? '', 'messages' => [['role' => 'user', 'content' => 'hola']]],
            $this->bearer($a),
        );
    }

    /** Provider sano: el chat fluye y el proxy relaya el 200 del upstream. */
    public function test_chat_flow_with_healthy_provider(): void
    {
        $this->fakeManager();

        $p = $this->provider(status: ModelProvider::STATUS_OK);
        $m = $this->model($p);
        $a = $this->agent($m);

        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'hola!'], 'reasoning_content' => null]],
        ], 200)]);

        // Pata web: el mensaje se persiste y el puente lo encola (sin Docker).
        app(ChatService::class)->send($a, $this->user, 'hola');
        Queue::assertPushed(BridgeSendMessage::class);
        $this->assertNotNull($a->fresh()->last_active_at);
        $this->assertDatabaseHas('chat_messages', ['agent_id' => $a->id, 'content' => 'hola']);

        // Pata contenedor: el proxy relaya el 200 del provider tal cual.
        $this->chatProxy($a->fresh(), $m)
            ->assertStatus(200)
            ->assertJsonPath('choices.0.message.content', 'hola!');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/chat/completions'));

        // Fin del turno: el puente reporta via EventSink y el agente queda listo.
        app(EventSink::class)->dispatch($a->fresh(), AgentEvent::statusChange('idle'));
        $this->assertSame(AgentStatus::Idle, $a->fresh()->status);
    }

    /**
     * Provider no sano: el proxy relaya el 422 del upstream (con el mensaje
     * del provider, que identifica el problema); al recomprobar el provider
     * (status ok) el chat vuelve a fluir.
     */
    public function test_unhealthy_provider_yields_422_and_recovering_rechecks_to_ok(): void
    {
        $this->fakeManager();

        $p = $this->provider(status: ModelProvider::STATUS_ERROR);
        $m = $this->model($p);
        $a = $this->agent($m);

        // El upstream rechaza el primer chat (clave invalida, cuota...):
        // 422 translucido. El probe de comprobacion (`/models`) siempre
        // responde 200: al recomprobar, el provider pasa a ok.
        Http::fake([
            '*models' => Http::response(['data' => []], 200),
            '*chat/completions' => Http::sequence()
                ->push(['error' => ['message' => 'invalid api key (prov-test)']], 422)
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'listo'], 'reasoning_content' => null]]], 200),
        ]);

        $this->chatProxy($a, $m)
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'invalid api key (prov-test)');

        // La web vuelve a comprobar el provider (admin): queda marcado ok.
        $this->actingAs($this->admin);
        Livewire::test(ProvidersIndex::class)->call('check', $p->id);
        $this->assertSame(ModelProvider::STATUS_OK, $p->fresh()->status);

        // Y el chat fluye de nuevo con el mismo agente (2.ª respuesta de la secuencia).
        $this->chatProxy($a->fresh(), $m)->assertStatus(200);
    }

    /**
     * Borrado del provider: la fila de model desaparece (cascade) y el
     * agente se huérfana (agents.model_id -> NULL, nullOnDelete): el proxy
     * responde 422 "sin modelo" y el CLI ve el listado de modelos vacio;
     * al reasignar al agente a un modelo nuevo, el chat vuelve a fluir.
     */
    public function test_deleting_provider_orphans_agents_and_reassigning_restores_chat(): void
    {
        $this->fakeManager();

        $p = $this->provider();
        $m = $this->model($p);
        $a = $this->agent($m);

        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'reasoning_content' => null]],
        ], 200)]);
        $this->chatProxy($a, $m)->assertStatus(200);

        // Admin borra el provider: model desaparece (cascade) y el agente
        // queda huérfano (model_id -> NULL).
        $this->actingAs($this->admin);
        Livewire::test(ProvidersIndex::class)->call('delete', $p->id);
        $this->assertNull($p->fresh());
        $this->assertNull($m->fresh());
        $this->assertNull($a->fresh()->model_id);

        // El proxy ya no ve modelo: 422 sin modelo configurado.
        $this->chatProxy($a->fresh(), null)
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'el agente no tiene modelo configurado');

        // El CLI Claude lo ve en la validacion de modelos: listado vacio.
        $this->getJson('/api/proxy/agents/'.$a->id.'/v1/models', $this->bearer($a))
            ->assertStatus(200)
            ->assertJsonPath('data', []);

        // Recuperacion: provider + modelo nuevos; el admin reasigna al agente.
        $p2 = $this->provider('http://127.0.0.1:9998/v1');
        $m2 = $this->model($p2, 'gpt-test-2');
        $at = $a->fresh();
        $at->model_id = $m2->id;
        $at->save();

        $this->chatProxy($at, $m2)->assertStatus(200);
    }

    /**
     * Journey de punta a punta (8.1): el admin crea el provider por UI
     * (el guardado lo comprueba y descubre sus modelos) → el no-admin ve el
     * catálogo compartido en solo lectura → el admin edita un modelo → se
     * crea un agente con ese modelo (sano) → el provider degrada a error →
     * el modelo queda marcado en el formulario de creacion.
     */
    public function test_journey_from_provider_creation_to_unhealthy_mark(): void
    {
        $this->fakeManager();

        // 1. El admin crea el provider: el guardado lo comprueba (200) y
        //    descubre los modelos listados.
        $this->actingAs($this->admin);
        // Secuencia de probes: 1.º (guardado) 200 con listado; 2.º
        // (recomprobacion del paso 5) 500. Dos Http::fake() con el mismo
        // patron no se sustituyen entre si: gana el primero registrado.
        Http::fake(['*models' => Http::sequence()
            ->push(['data' => [['id' => 'gpt-test']]], 200)
            ->push(['error' => ['message' => 'boom']], 500)
            ->whenEmpty(Http::response(['data' => []], 200)),
        ]);

        Livewire::test(ProvidersIndex::class, [
            'creating' => true,
            'name' => 'prov-ui',
            'type' => 'openai',
            'base_url' => 'http://127.0.0.1:9999/v1',
            'api_key' => 'sk-test',
        ])->call('save');

        $p = ModelProvider::where('name', 'prov-ui')->first();
        $this->assertNotNull($p, 'el proveedor debe persistirse');
        $this->assertSame(ModelProvider::STATUS_OK, $p->status);
        $m = Model::where('provider_id', $p->id)->where('model_id', 'gpt-test')->first();
        $this->assertNotNull($m, 'el descubrimiento debe crear el modelo');

        // 2. El no-admin ve el catálogo compartido en solo lectura.
        $this->actingAs($this->user);
        $html = Livewire::test(ProvidersIndex::class)->html();
        $this->assertStringContainsString('prov-ui', $html);
        $this->assertDoesNotMatchRegularExpression('/wire:click="check\\(/', $html);

        // 3. El admin edita el modelo (proveedor bloqueado, D5).
        $this->actingAs($this->admin);
        Livewire::test(ModelsIndex::class, [
            'creating' => true,
            'edit_id' => $m->id,
            'provider_id' => $p->id,
            'model_id' => $m->model_id,
            'name' => 'Modelo renombrado',
        ])->call('save');
        $this->assertSame('Modelo renombrado', $m->fresh()->name);

        // 4. Se crea un agente con ese modelo (sano).
        $this->actingAs($this->user);
        Livewire::test(Create::class, [
            'name' => 'agente-via-flujo',
            'runtime' => 'pi',
            'model_id' => $m->id,
        ])->call('submit');
        $a = Agent::where('name', 'agente-via-flujo')->first();
        $this->assertNotNull($a);
        $this->assertSame($m->id, $a->model_id);

        // 5. El provider degrada: la recomprobacion consume el 500 de la
        //    secuencia.
        $this->actingAs($this->admin);
        Livewire::test(ProvidersIndex::class)->call('check', $p->id);
        $this->assertSame(ModelProvider::STATUS_ERROR, $p->fresh()->status);

        // 6. Y el modelo queda marcado en el formulario de creacion (D7).
        $this->actingAs($this->user);
        $html = Livewire::test(Create::class, ['runtime' => 'pi'])->html();
        $opt = collect(explode('<option', $html))->first(fn ($o) => str_contains($o, 'Modelo renombrado'));
        $this->assertNotNull($opt, 'el modelo del provider no sano sigue en el desplegable');
        $this->assertStringContainsString('text-muted', $opt);
        $this->assertStringContainsString('no sano', $opt);
    }
}

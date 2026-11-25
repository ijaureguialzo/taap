<?php

namespace Tests\Feature\Api;

use App\Agents\AgentManager;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Validacion de formato modelo↔runtime en la API de agentes (ios-model-
 * runtime-compatibility, spec model-management "Compatibilidad modelo-
 * runtime en la API"): POST/PUT /agents devuelve 422 con un mensaje claro
 * que nombra formato y runtime, y el agente no queda con ese modelo.
 */
class AgentsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');

        // El ciclo de vida no se toca en estos tests: mocks que no usan
        // Docker (mismo patron que ChatSendTest).
        $manager = $this->createMock(AgentManager::class);
        $manager->method('provision')->willReturnArgument(0);
        $manager->method('start')->willReturnCallback(
            function (Agent $agent): Agent {
                $agent->forceFill(['status' => AgentStatus::Starting, 'started_at' => now()])->save();

                return $agent;
            },
        );
        $this->app->instance(AgentManager::class, $manager);

        // La cola va fake: BridgeApplyConfig no aplica config contra un
        // contenedor que no existe en el entorno de test.
        Queue::fake();
    }

    protected function provider(string $type, string $name): ModelProvider
    {
        return ModelProvider::factory()->create([
            'name' => $name,
            'type' => $type,
            'created_by' => $this->user->id,
        ]);
    }

    protected function model(ModelProvider $provider, string $modelId): Model
    {
        return Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => $modelId,
            'name' => 'Modelo '.$modelId,
        ]);
    }

    /* ---------- store ---------- */

    public function test_store_rejects_model_with_incompatible_format(): void
    {
        $anthropic = $this->provider('anthropic', 'prov-anthropic');
        $model = $this->model($anthropic, 'm-anthropic');

        $res = $this->postJson('/api/v1/agents', [
            'name' => 'mi-agente',
            'runtime' => 'pi',
            'model_id' => $model->id,
        ])->assertUnprocessable();

        // Mensaje claro que nombra el formato y el runtime (es: locale
        // del usuario por el middleware SetLocale).
        $message = $res->json('errors.model_id.0');
        $this->assertStringContainsString('anthropic', $message);
        $this->assertStringContainsString('Pi', $message);

        // El agente no queda creado
        $this->assertDatabaseCount('agents', 0);
    }

    public function test_store_accepts_compatible_model(): void
    {
        $openai = $this->provider('openai', 'prov-openai');
        $model = $this->model($openai, 'm-openai');

        $this->postJson('/api/v1/agents', [
            'name' => 'mi-agente',
            'runtime' => 'pi',
            'model_id' => $model->id,
        ])->assertCreated();

        $agent = Agent::first();
        $this->assertSame($model->id, $agent->model_id);
        $this->assertSame('pi', $agent->runtime->value);
    }

    public function test_store_without_model_is_valid(): void
    {
        $this->postJson('/api/v1/agents', [
            'name' => 'sin-modelo',
            'runtime' => 'pi',
        ])->assertCreated();

        $this->assertNull(Agent::first()->model_id);
    }

    /* ---------- update ---------- */

    public function test_update_rejects_incompatible_model_and_keeps_the_previous_one(): void
    {
        $openai = $this->provider('openai', 'prov-openai');
        $anthropic = $this->provider('anthropic', 'prov-anthropic');
        $mOpenai = $this->model($openai, 'm-openai');
        $mAnthropic = $this->model($anthropic, 'm-anthropic');

        $agent = Agent::factory()->create([
            'owner_id' => $this->user->id,
            'runtime' => 'pi',
            'model_id' => $mOpenai->id,
            'status' => AgentStatus::Offline,
        ]);

        $res = $this->putJson("/api/v1/agents/{$agent->id}", [
            'name' => $agent->name,
            'model_id' => $mAnthropic->id,
        ])->assertUnprocessable();

        $message = $res->json('errors.model_id.0');
        $this->assertStringContainsString('anthropic', $message);
        $this->assertStringContainsString('Pi', $message);

        // El agente conserva su modelo anterior (el runtime no es editable
        // por la API: la compatibilidad se comprueba contra el actual).
        $agent->refresh();
        $this->assertSame($mOpenai->id, $agent->model_id);
    }

    public function test_update_accepts_compatible_model(): void
    {
        $openai = $this->provider('openai', 'prov-openai');
        $mOne = $this->model($openai, 'm-one');
        $mTwo = $this->model($openai, 'm-two');

        $agent = Agent::factory()->create([
            'owner_id' => $this->user->id,
            'runtime' => 'pi',
            'model_id' => $mOne->id,
            'status' => AgentStatus::Offline,
        ]);

        $this->putJson("/api/v1/agents/{$agent->id}", [
            'name' => $agent->name,
            'model_id' => $mTwo->id,
        ])->assertOk();

        $agent->refresh();
        $this->assertSame($mTwo->id, $agent->model_id);
    }

    /* ---------- fs_mounts (shared-filesystem 9.1) ---------- */

    public function test_store_with_fs_mounts_syncs_them(): void
    {
        $this->postJson('/api/v1/agents', [
            'name' => 'con-carpeta',
            'runtime' => 'pi',
            'fs_mounts' => [
                ['path' => '/ana/proyectos', 'mode' => 'read_write'],
                ['path' => '/admin/docs', 'mode' => 'read_only'],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('agent_fs_mounts', [
            'agent_id' => 1, 'path' => '/ana/proyectos', 'mode' => 'read_write',
        ]);
        $this->assertDatabaseHas('agent_fs_mounts', [
            'agent_id' => 1, 'path' => '/admin/docs', 'mode' => 'read_only',
        ]);

        // El payload expone los mounts (contrato estable para la app iOS)
        $this->getJson('/api/v1/agents/1')
            ->assertOk()
            ->assertJsonPath('agent.fs_mounts.0.path', '/ana/proyectos')
            ->assertJsonPath('agent.fs_mounts.1.mode', 'read_only');
    }

    public function test_update_fs_mounts_syncs_and_applies(): void
    {
        $agent = Agent::factory()->create([
            'owner_id' => $this->user->id,
            'runtime' => 'pi',
            'status' => AgentStatus::Idle,
        ]);

        // Un mock propio: el update con fs_mounts debe llamar a restart (6.5)
        $manager = $this->createMock(AgentManager::class);
        $manager->method('provision')->willReturnArgument(0);
        // Dos PUT con fs_mounts => dos restart (6.5): al montar y al desmontar
        $manager->expects($this->exactly(2))->method('restart')->willReturnArgument(0);
        $this->app->instance(AgentManager::class, $manager);

        $this->putJson("/api/v1/agents/{$agent->id}", [
            'name' => $agent->name,
            'fs_mounts' => [['path' => '/ana', 'mode' => 'read_only']],
        ])->assertOk()->assertJsonPath('agent.fs_mounts.0.path', '/ana');

        $this->assertDatabaseHas('agent_fs_mounts', [
            'agent_id' => $agent->id, 'path' => '/ana', 'mode' => 'read_only',
        ]);

        // `fs_mounts: []` vacia los mounts
        $this->putJson("/api/v1/agents/{$agent->id}", [
            'name' => $agent->name,
            'fs_mounts' => [],
        ])->assertOk();
        $this->assertDatabaseCount('agent_fs_mounts', 0);
    }

    public function test_fs_mounts_validation_rejects_bad_paths_and_modes(): void
    {
        $this->postJson('/api/v1/agents', [
            'name' => 'v1',
            'runtime' => 'pi',
            'fs_mounts' => [['path' => 'sin-barras', 'mode' => 'read_write']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['fs_mounts.0.path']);

        $this->postJson('/api/v1/agents', [
            'name' => 'v2',
            'runtime' => 'pi',
            'fs_mounts' => [['path' => '/ana', 'mode' => 'rw']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['fs_mounts.0.mode']);

        // Path con .. = salto de raiz: se niega (mismo principio del D6)
        $this->postJson('/api/v1/agents', [
            'name' => 'v3',
            'runtime' => 'pi',
            'fs_mounts' => [['path' => '/ana/../outside', 'mode' => 'rw']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['fs_mounts.0.path', 'fs_mounts.0.mode']);

        $this->assertDatabaseCount('agents', 0);
    }
}

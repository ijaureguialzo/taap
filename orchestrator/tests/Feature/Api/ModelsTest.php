<?php

namespace Tests\Feature\Api;

use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lista de modelos de la API v1 (ios-model-runtime-compatibility, spec
 * model-management "Lista de modelos filtrable por runtime" +
 * "Informacion de proveedor en la lista de modelos"): filtro opcional
 * ?runtime= con la misma semantica de compatibilidad que la web y payload
 * aditivo con el tipo de formato y el estado de salud del proveedor.
 */
class ModelsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    protected function provider(string $type, string $name, ?string $status = null): ModelProvider
    {
        return ModelProvider::factory()->create([
            'name' => $name,
            'type' => $type,
            'status' => $status,
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

    protected function ids(?string $runtime = null): array
    {
        $url = $runtime === null ? '/api/v1/models' : "/api/v1/models?runtime={$runtime}";

        return array_map(fn ($m) => (int) $m['id'], $this->getJson($url)->assertOk()->json('models'));
    }

    public function test_filters_models_by_runtime_format(): void
    {
        $openai = $this->provider('openai', 'prov-openai');
        $anthropic = $this->provider('anthropic', 'prov-anthropic');
        $other = $this->provider('other', 'prov-other');
        $mOpenai = $this->model($openai, 'm-openai');
        $mAnthropic = $this->model($anthropic, 'm-anthropic');
        $mOther = $this->model($other, 'm-other');

        // Claude solo consume Anthropic: solo sus modelos
        $this->assertSame([$mAnthropic->id], $this->ids('claude'));

        // OpenCode consume ambos formatos: todos los modelos
        $this->assertEqualsCanonicalizing(
            [$mOpenai->id, $mAnthropic->id, $mOther->id],
            $this->ids('opencode'),
        );

        // Pi consume openai: openai + other (other se trata como OpenAI)
        $this->assertEqualsCanonicalizing([$mOpenai->id, $mOther->id], $this->ids('pi'));
    }

    public function test_without_parameter_returns_all_models(): void
    {
        $openai = $this->provider('openai', 'prov-openai');
        $anthropic = $this->provider('anthropic', 'prov-anthropic');
        $mOpenai = $this->model($openai, 'm-openai');
        $mAnthropic = $this->model($anthropic, 'm-anthropic');

        $this->assertEqualsCanonicalizing([$mOpenai->id, $mAnthropic->id], $this->ids());
    }

    public function test_unknown_runtime_value_is_rejected(): void
    {
        $this->getJson('/api/v1/models?runtime=marco')->assertUnprocessable();
    }

    public function test_payload_includes_provider_type_and_health_status(): void
    {
        $openai = $this->provider('openai', 'prov-ok', status: ModelProvider::STATUS_OK);
        $anthropic = $this->provider('anthropic', 'prov-error', status: ModelProvider::STATUS_ERROR);
        $other = $this->provider('other', 'prov-unknown'); // sin comprobar
        $mOpenai = $this->model($openai, 'm-openai');
        $mAnthropic = $this->model($anthropic, 'm-anthropic');
        $mOther = $this->model($other, 'm-other');

        $models = $this->getJson('/api/v1/models')->assertOk()->json('models');
        $byId = [];
        foreach ($models as $m) {
            $byId[(int) $m['id']] = $m;
        }

        // provider sigue siendo el nombre en string (el DTO de iOS lo exige)
        $this->assertSame('prov-ok', $byId[$mOpenai->id]['provider']);
        $this->assertSame('openai', $byId[$mOpenai->id]['provider_type']);
        $this->assertSame(ModelProvider::STATUS_OK, $byId[$mOpenai->id]['provider_status']);

        $this->assertSame('anthropic', $byId[$mAnthropic->id]['provider_type']);
        $this->assertSame(ModelProvider::STATUS_ERROR, $byId[$mAnthropic->id]['provider_status']);

        // sin comprobacion: null explicito en el JSON
        $this->assertSame('other', $byId[$mOther->id]['provider_type']);
        $this->assertArrayHasKey('provider_status', $byId[$mOther->id]);
        $this->assertNull($byId[$mOther->id]['provider_status']);
    }
}

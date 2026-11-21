<?php

namespace Tests\Unit;

use App\Enums\AgentRuntime;
use App\Models\Model;
use App\Models\ModelProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Compatibilidad modelo↔runtime (ios-model-runtime-compatibility 1.1,
 * design D1): una sola fuente de verdad en AgentRuntime::
 * compatibleProviderTypes() + Model::isCompatibleWith(). La semantica es la
 * de la spec model-management: `anthropic` solo runtimes que declaran el
 * formato anthropic; `openai`/`other` runtimes que declaran openai (other se
 * trata como OpenAI, design v1.0) o que no declaran formatos.
 */
class ModelCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tabla de los 5 runtimes del registro × los 3 tipos de proveedor.
     *
     * @return array<string, array{0: AgentRuntime, 1: array<string, bool>}>
     */
    public static function providerTypeTable(): array
    {
        return [
            'pi' => [AgentRuntime::Pi, [
                'openai' => true, 'anthropic' => false, 'other' => true,
            ]],
            'hermes' => [AgentRuntime::Hermes, [
                'openai' => true, 'anthropic' => false, 'other' => true,
            ]],
            'claude' => [AgentRuntime::Claude, [
                'openai' => false, 'anthropic' => true, 'other' => false,
            ]],
            'codex' => [AgentRuntime::Codex, [
                'openai' => true, 'anthropic' => false, 'other' => true,
            ]],
            'opencode' => [AgentRuntime::OpenCode, [
                'openai' => true, 'anthropic' => true, 'other' => true,
            ]],
        ];
    }

    #[DataProvider('providerTypeTable')]
    public function test_provider_types_declared_per_runtime(AgentRuntime $runtime, array $expected): void
    {
        $this->assertSame(
            array_values(array_filter(
                ['openai', 'other', 'anthropic'],
                fn (string $type) => $expected[$type],
            )),
            $runtime->compatibleProviderTypes(),
            "los tipos compatibles del runtime {$runtime->value}",
        );
    }

    public function test_model_compatibility_table_for_all_runtimes_and_provider_types(): void
    {
        $providers = [
            'openai' => ModelProvider::factory()->create(['name' => 'prov-openai', 'type' => 'openai']),
            'anthropic' => ModelProvider::factory()->create(['name' => 'prov-anthropic', 'type' => 'anthropic']),
            'other' => ModelProvider::factory()->create(['name' => 'prov-other', 'type' => 'other']),
        ];

        $models = [];
        foreach ($providers as $type => $provider) {
            $models[$type] = Model::factory()->create([
                'provider_id' => $provider->id,
                'model_id' => "m-{$type}",
                'name' => "Modelo {$type}",
            ]);
        }

        foreach (static::providerTypeTable() as $name => [$runtime, $expected]) {
            foreach ($providers as $type => $provider) {
                $this->assertSame(
                    $expected[$type],
                    $models[$type]->isCompatibleWith($runtime),
                    "modelo de tipo {$type} vs runtime {$name}",
                );
            }
        }
    }

    public function test_model_without_provider_is_incompatible(): void
    {
        // El provider se borra (el FK en cascade borra la fila del modelo,
        // pero el objeto ya cargado sigue vivo): su relation pasa a null y
        // el modelo no es compatible con NINGUN runtime.
        $provider = ModelProvider::factory()->create(['name' => 'prov-x', 'type' => 'openai']);
        $created = Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => 'm-x',
            'name' => 'Modelo x',
        ]);
        // Instancia fresca (sin la relation cargada) para que el acceso a
        // `provider` tras el borrado consulte la base vacía.
        $model = Model::find($created->id);
        $provider->delete();

        $this->assertNull($model->provider);
        $this->assertFalse($model->isCompatibleWith(AgentRuntime::Pi));
        $this->assertFalse($model->isCompatibleWith(AgentRuntime::OpenCode));
    }

    public function test_runtime_without_declared_formats_is_treated_as_openai(): void
    {
        // Path defensivo (design D1): un runtime sin formatos consume
        // openai/other. Hoy los cinco runtimes declaran formato; el override
        // de config simula uno sin declarar.
        config(['taap.runtimes.pi.capabilities' => ['native', 'restart']]);

        $this->assertSame([], AgentRuntime::Pi->modelFormats());
        $this->assertSame(['openai', 'other'], AgentRuntime::Pi->compatibleProviderTypes());
    }
}

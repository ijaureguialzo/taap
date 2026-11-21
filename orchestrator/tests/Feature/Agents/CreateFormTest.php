<?php

namespace Tests\Feature\Agents;

use App\Enums\AgentRuntime;
use App\Livewire\Agents\Create;
use App\Models\Agent;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Formulario de creacion gobernado por el registro (add-claude-code-runtime
 * 2.3): runtimes del registro (sin hardcode), etiquetas localizadas y
 * filtro de modelos por formato compatible (spec model-management).
 */
class CreateFormTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
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

    public function test_form_lists_only_registered_runtimes(): void
    {
        $openai = $this->provider('openai', 'prov-openai');
        $anthropic = $this->provider('anthropic', 'prov-anthropic');
        $this->model($openai, 'm-openai');
        $this->model($anthropic, 'm-anthropic');

        $html = Livewire::test(Create::class)->html();

        foreach (AgentRuntime::supported() as $value) {
            $this->assertStringContainsString('value="'.$value.'"', $html, "falta el runtime {$value}");
        }

        // Las etiquetas vienen del registro, no el valor crudo
        $this->assertStringContainsString('Claude Code', $html);
        $this->assertStringContainsString('Hermes', $html);
        $this->assertStringContainsString('Codex', $html);
    }

    public function test_models_are_filtered_by_runtime_format(): void
    {
        $openai = $this->provider('openai', 'prov-openai');
        $anthropic = $this->provider('anthropic', 'prov-anthropic');
        $other = $this->provider('other', 'prov-other');
        $mOpenai = $this->model($openai, 'modelo-openai');
        $mAnthropic = $this->model($anthropic, 'modelo-anthropic');
        $mOther = $this->model($other, 'modelo-other');

        // Claude solo consume Anthropic: solo ve los de ese formato
        $html = Livewire::test(Create::class, ['runtime' => 'claude'])->html();
        $this->assertStringContainsString('modelo-anthropic', $html);
        $this->assertStringNotContainsString('modelo-openai', $html);
        $this->assertStringNotContainsString('modelo-other', $html);

        // Pi consume OpenAI: ve openai y `other` (el design lo trata como OpenAI)
        $html = Livewire::test(Create::class, ['runtime' => 'pi'])->html();
        $this->assertStringContainsString('modelo-openai', $html);
        $this->assertStringContainsString('modelo-other', $html);
        $this->assertStringNotContainsString('modelo-anthropic', $html);
    }

    public function test_submit_rejects_incompatible_model_format(): void
    {
        $anthropic = $this->provider('anthropic', 'prov-anthropic');
        $mAnthropic = $this->model($anthropic, 'claude-sonnet-4-5');

        Livewire::test(Create::class, [
            'name' => 'mi-agente',
            'runtime' => 'pi',
            'model_id' => $mAnthropic->id,
        ])
            ->call('submit')
            ->assertHasErrors('model_id');
    }

    public function test_create_with_compatible_model_provisions_it(): void
    {
        $anthropic = $this->provider('anthropic', 'prov-anthropic');
        $m = $this->model($anthropic, 'claude-sonnet-4-5');

        // Sin Docker el provision falla, pero la validacion pasa y el agente
        // queda creado (el submit captura el error de provision y lo flashea).
        Livewire::test(Create::class, [
            'name' => 'mi-agente',
            'runtime' => 'claude',
            'model_id' => $m->id,
        ])->call('submit');

        $agent = Agent::where('name', 'mi-agente')->first();
        $this->assertNotNull($agent);
        $this->assertSame('claude', $agent->runtime->value);
        $this->assertSame($m->id, $agent->model_id);
        $this->assertSame('taap/agent-claude:local', $agent->image);
    }
    public function test_models_of_unhealthy_providers_are_labeled_but_still_selectable(): void
    {
        $this->app->setLocale('es');

        $bad = $this->provider('openai', 'prov-bad');
        $bad->forceFill(['status' => ModelProvider::STATUS_ERROR, 'last_checked_at' => now()])->save();
        $this->model($bad, 'modelo-malo');

        $good = $this->provider('openai', 'prov-good');
        $good->forceFill(['status' => ModelProvider::STATUS_OK, 'last_checked_at' => now()])->save();
        $this->model($good, 'modelo-bueno');

        // status null (nunca comprobado): tampoco va marcado
        $this->model($this->provider('openai', 'prov-neutral'), 'modelo-neutral');

        $html = Livewire::test(Create::class, ['runtime' => 'pi'])->html();

        $optionBad = collect(explode('<option', $html))->first(fn ($o) => str_contains($o, 'modelo-malo'));
        $this->assertNotNull($optionBad, 'el modelo del proveedor no sano sigue en el desplegable');
        $this->assertStringContainsString('text-muted', $optionBad);
        $this->assertStringContainsString('no sano', $optionBad);

        $optionGood = collect(explode('<option', $html))->first(fn ($o) => str_contains($o, 'modelo-bueno'));
        $this->assertNotNull($optionGood);
        $this->assertStringNotContainsString('no sano', $optionGood);
        $this->assertStringNotContainsString('text-muted', $optionGood);

        $optionNeutral = collect(explode('<option', $html))->first(fn ($o) => str_contains($o, 'modelo-neutral'));
        $this->assertNotNull($optionNeutral);
        $this->assertStringNotContainsString('no sano', $optionNeutral);
    }

    public function test_create_agent_with_model_of_unhealthy_provider_is_allowed(): void
    {
        $bad = $this->provider('openai', 'prov-bad');
        $bad->forceFill(['status' => ModelProvider::STATUS_ERROR, 'last_checked_at' => now()])->save();
        $m = $this->model($bad, 'modelo-malo');

        // La marca es informativa: la seleccion y el submit no se bloquean.
        Livewire::test(Create::class, [
            'name' => 'mi-agente',
            'runtime' => 'pi',
            'model_id' => $m->id,
        ])->call('submit');

        $agent = Agent::where('name', 'mi-agente')->first();
        $this->assertNotNull($agent, 'crear con un modelo de provider no sano debe ser permitido');
        $this->assertSame($m->id, $agent->model_id);
    }
}

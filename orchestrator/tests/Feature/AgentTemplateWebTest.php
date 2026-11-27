<?php

namespace Tests\Feature;

use App\Enums\AgentRuntime;
use App\Livewire\Agents\Create;
use App\Models\AgentTemplate;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentTemplateWebTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Model $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'tmpl-web@taap.test']);
        $provider = ModelProvider::factory()->create(['type' => 'openai', 'created_by' => $this->user->id]);
        $this->model = Model::factory()->create([
            'provider_id' => $provider->id,
            'model_id' => 'gpt-web',
            'name' => 'GPT Web',
        ]);
        $this->actingAs($this->user);
    }

    public function test_apply_template_prefills_runtime_model_and_internet(): void
    {
        $template = AgentTemplate::create([
            'owner_id' => $this->user->id,
            'name' => 'Revisor',
            'runtime' => 'pi',
            'model_id' => $this->model->id,
            'internet' => true,
        ]);

        Livewire::test(Create::class)
            ->set('template_id', $template->id)
            ->call('applyTemplate');

        $this->assertSame('pi', $template->runtime);
        // El componente queda con los valores pre-rellenados.
        Livewire::test(Create::class)
            ->set('template_id', $template->id)
            ->call('applyTemplate')
            ->assertSet('runtime', 'pi')
            ->assertSet('model_id', $this->model->id)
            ->assertSet('internet_enabled', true);
    }

    public function test_apply_template_with_incompatible_model_discards_it(): void
    {
        // Claude: openai no es compatible → el modelo debe descartarse.
        $template = AgentTemplate::create([
            'owner_id' => $this->user->id,
            'name' => 'Revisor Claude',
            'runtime' => 'claude',
            'model_id' => $this->model->id, // openai: incompatible
            'internet' => false,
        ]);

        Livewire::test(Create::class)
            ->set('template_id', $template->id)
            ->call('applyTemplate')
            ->assertSet('runtime', 'claude')
            ->assertSet('internet_enabled', false)
            ->assertSet('model_id', null);
    }

    public function test_template_repo_flushes_template_name_on_apply(): void
    {
        $template = AgentTemplate::create([
            'owner_id' => $this->user->id,
            'name' => 'Docs',
            'runtime' => 'pi',
            'internet' => false,
        ]);

        Livewire::test(Create::class)
            ->set('template_id', $template->id)
            ->call('applyTemplate')
            ->assertSet('last_template_name', 'Docs');
    }
}

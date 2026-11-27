<?php

namespace Tests\Feature\Api;

use App\Models\AgentTemplate;
use App\Models\Model;
use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected ModelProvider $provider;

    protected Model $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'tmpl-owner@taap.test']);
        $this->provider = ModelProvider::factory()->create(['type' => 'openai', 'created_by' => $this->owner->id]);
        $this->model = Model::factory()->create([
            'provider_id' => $this->provider->id,
            'model_id' => 'gpt-tmpl',
            'name' => 'GPT Tmpl',
        ]);
    }

    public function test_user_can_create_template_and_list_own(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->postJson('/api/v1/templates', [
            'name' => 'Mi plantilla',
            'runtime' => 'pi',
            'model_id' => $this->model->id,
            'internet' => true,
        ])->assertCreated()->assertJsonPath('template.name', 'Mi plantilla');

        $this->getJson('/api/v1/templates')
            ->assertOk()
            ->assertJsonCount(1, 'templates');

        $this->assertDatabaseHas('agent_templates', [
            'owner_id' => $this->owner->id,
            'name' => 'Mi plantilla',
            'runtime' => 'pi',
        ]);
    }

    public function test_template_rejects_incompatible_model(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        // Claude solo consume anthropic: openai es incompatible.
        $this->postJson('/api/v1/templates', [
            'name' => 'Claude',
            'runtime' => 'claude',
            'model_id' => $this->model->id,
        ])->assertStatus(422);
    }

    public function test_template_rejects_unsupported_runtime(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->postJson('/api/v1/templates', [
            'name' => 'Fake',
            'runtime' => 'bogus',
            'model_id' => null,
        ])->assertStatus(422);
    }

    public function test_template_update_and_delete(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $template = AgentTemplate::create([
            'owner_id' => $this->owner->id,
            'name' => 'Editar',
            'runtime' => 'pi',
            'model_id' => $this->model->id,
            'internet' => true,
        ]);

        $this->putJson('/api/v1/templates/'.$template->id, [
            'name' => 'Editado',
            'runtime' => 'pi',
            'model_id' => null,
            'internet' => false,
        ])->assertOk()->assertJsonPath('template.name', 'Editado');

        $this->deleteJson('/api/v1/templates/'.$template->id)
            ->assertOk();

        $this->assertDatabaseMissing('agent_templates', ['id' => $template->id]);
    }

    public function test_user_cannot_delete_other_users_template(): void
    {
        $other = User::factory()->create(['email' => 'tmpl-other@taap.test']);
        $template = AgentTemplate::create([
            'owner_id' => $other->id,
            'name' => 'Ajena',
            'runtime' => 'pi',
        ]);

        $this->actingAs($this->owner, 'sanctum');

        $this->deleteJson('/api/v1/templates/'.$template->id)
            ->assertStatus(403);
    }

    public function test_admin_sees_all_templates_but_cannot_edit_other_users(): void
    {
        $other = User::factory()->create(['email' => 'tmpl-admin-other@taap.test']);
        AgentTemplate::create([
            'owner_id' => $other->id,
            'name' => 'Ajena Admin',
            'runtime' => 'pi',
        ]);

        $admin = User::factory()->admin()->create(['email' => 'tmpl-admin@taap.test']);
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/v1/templates?all=true')
            ->assertOk()
            ->assertJsonCount(1, 'templates');

        $this->getJson('/api/v1/templates')
            ->assertOk()
            ->assertJsonCount(0, 'templates');
    }
}

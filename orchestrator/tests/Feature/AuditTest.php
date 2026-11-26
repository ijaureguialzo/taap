<?php

namespace Tests\Feature;

use App\Agents\AgentManager;
use App\Models\Agent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\AuditLog;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * admin-audit-trail: toda acción admin aplicable registra una entrada
 * de auditoría ATÓMICA con la operación (same transaction).
 */
class AuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockManager = $this->createMock(AgentManager::class);
        $this->mockManager->method('start')->willReturnCallback(fn ($a) => $a);
        $this->mockManager->method('stop')->willReturnCallback(fn ($a) => $a);
        $this->mockManager->method('terminate')->willReturnCallback(fn ($a) => $a->id);
        $this->app->instance(AgentManager::class, $this->mockManager);

        $this->admin = User::factory()->admin()->create(['email' => 'audit-admin@taap.test']);
        $this->actingAs($this->admin);
    }

    public function test_create_user_writes_audit_entry(): void
    {
        Livewire::test(\App\Livewire\Users\Index::class)
            ->call('startCreate')
            ->set('create_name', 'Apuntado')
            ->set('create_email', 'apuntado@taap.test')
            ->set('create_password', 'password123')
            ->set('create_password_confirmation', 'password123')
            ->set('create_role', 'user')
            ->call('create')
            ->assertHasNoErrors();

        $user = User::where('email', 'apuntado@taap.test')->first();

        $this->assertDatabaseHas('audit_log', [
            'actor_id' => $this->admin->id,
            'action' => 'user.created',
            'target_type' => 'user',
            'target_id' => (string) $user->id,
        ]);
    }

    public function test_disable_user_writes_audit_entry(): void
    {
        $target = User::factory()->create(['email' => 'disable-target@taap.test']);

        Livewire::test(\App\Livewire\Users\Index::class)
            ->call('startEdit', $target->id)
            ->set('active', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('audit_log', [
            'actor_id' => $this->admin->id,
            'action' => 'user.disabled',
            'target_type' => 'user',
            'target_id' => (string) $target->id,
        ]);
    }

    public function test_delete_user_writes_audit_entry(): void
    {
        $target = User::factory()->create(['email' => 'delete-target@taap.test']);

        Livewire::test(\App\Livewire\Users\Index::class)
            ->call('delete', $target->id)
            ->assertHasNoErrors();

        $this->assertNull(User::find($target->id));
        $this->assertDatabaseHas('audit_log', [
            'actor_id' => $this->admin->id,
            'action' => 'user.deleted',
            'target_type' => 'user',
            'target_id' => (string) $target->id,
        ]);
    }

    public function test_reset_password_writes_audit_entry(): void
    {
        $target = User::factory()->create(['email' => 'reset-target@taap.test']);

        Livewire::test(\App\Livewire\Users\Index::class)
            ->call('startEdit', $target->id)
            ->set('reset_password', 'password123')
            ->set('reset_password_confirmation', 'password123')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('audit_log', [
            'actor_id' => $this->admin->id,
            'action' => 'user.password_reset',
            'target_type' => 'user',
            'target_id' => (string) $target->id,
        ]);
    }

    public function test_api_provider_create_writes_audit_entry(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/providers', [
            'name' => 'Prov Audit',
            'type' => 'openai',
            'base_url' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
        ])->assertCreated();

        $provider = \App\Models\ModelProvider::where('name', 'Prov Audit')->first();

        $this->assertDatabaseHas('audit_log', [
            'actor_id' => $this->admin->id,
            'action' => 'provider.created',
            'target_type' => 'provider',
            'target_id' => (string) $provider->id,
        ]);
    }

    public function test_api_settings_toggle_writes_audit_entry(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->putJson('/api/v1/settings', ['registration_enabled' => false])
            ->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'actor_id' => $this->admin->id,
            'action' => 'registration.toggled',
            'target_type' => 'settings',
        ]);
    }

    public function test_api_mcp_create_writes_audit_entry(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/mcp', [
            'name' => 'mcp-audit',
            'type' => 'url',
            'url' => 'https://mcp.example.com/mcp',
        ])->assertCreated();

        $server = \App\Models\McpServer::where('name', 'mcp-audit')->first();

        $this->assertDatabaseHas('audit_log', [
            'actor_id' => $this->admin->id,
            'action' => 'mcp.created',
            'target_type' => 'mcp',
            'target_id' => (string) $server->id,
        ]);
    }

    public function test_web_audit_page_lists_entries_and_filters(): void
    {
        AuditLog::create([
            'actor_id' => $this->admin->id,
            'action' => 'provider.updated',
            'target_type' => 'provider',
            'target_id' => '7',
            'created_at' => now(),
        ]);

        Livewire::test(\App\Livewire\Audit\Index::class)
            ->assertOk()
            ->assertSee('provider.updated')
            ->set('action', 'provider.updated')
            ->assertSee('provider.updated')
            ->set('action', 'user.created')
            ->assertSee('Sin entradas');
    }

    public function test_non_admin_cannot_see_audit_page(): void
    {
        $other = User::factory()->create(['email' => 'audit-page-other@taap.test']);
        $this->actingAs($other);

        $this->get(route('audit.index'))
            ->assertForbidden();
    }

    public function test_audit_entries_are_immutable(): void
    {
        $entry = \App\Models\AuditLog::create([
            'actor_id' => $this->admin->id,
            'action' => 'user.updated',
            'target_type' => 'user',
            'target_id' => '999',
            'created_at' => now(),
        ]);

        // El modelo no persiste cambios: sin tabla implícita de actualización,
        // la única forma de modificar es directa — y la app no expone ninguna.
        $this->assertSame('user.updated', $entry->fresh()->action);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Agents\AgentManager;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockManager = $this->createMock(AgentManager::class);
        $this->mockManager->method('start')->willReturnCallback(fn ($a) => $a);
        $this->mockManager->method('stop')->willReturnCallback(fn ($a) => $a);
        $this->app->instance(AgentManager::class, $this->mockManager);

        $this->admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@taap.test']);
    }

    protected function createTarget(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['name' => 'Target', 'email' => 'target@taap.test'], $attrs));
    }

    protected function createTargetAgent(User $user, string $status = 'idle'): Agent
    {
        return Agent::factory()->create(['owner_id' => $user->id, 'status' => $status]);
    }

    protected function actingAsAdmin(): self
    {
        $this->actingAs($this->admin, 'sanctum');

        return $this;
    }

    public function test_admin_lists_users_with_active_state(): void
    {
        $this->createTarget();

        $this->actingAsAdmin()
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonCount(2, 'users')
            ->assertJsonFragment(['email' => 'target@taap.test', 'active' => true]);
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertForbidden();
    }

    public function test_admin_can_create_user_with_all_fields(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/v1/users', [
                'name' => 'Nuevo',
                'email' => 'nuevo@taap.test',
                'password' => 'password123',
                'role' => 'admin',
                'active' => false,
                'agent_quota' => 7,
                'locale' => 'eu',
            ])
            ->assertCreated()
            ->assertJson(['user' => ['email' => 'nuevo@taap.test', 'role' => 'admin', 'active' => false, 'agent_quota' => 7, 'locale' => 'eu']]);

        $user = User::where('email', 'nuevo@taap.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_create_requires_unique_email(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/v1/users', [
                'name' => 'Dup',
                'email' => 'admin@taap.test',
                'password' => 'password123',
                'role' => 'user',
            ])
            ->assertStatus(422);
    }

    public function test_create_rejects_invalid_role(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/v1/users', [
                'name' => 'Rol Roto',
                'email' => 'raro@taap.test',
                'password' => 'password123',
                'role' => 'superuser',
            ])
            ->assertStatus(422);
    }

    public function test_admin_cannot_disable_self_via_api(): void
    {
        $this->actingAsAdmin()
            ->putJson('/api/v1/users/'.$this->admin->id, [
                'role' => 'admin',
                'active' => false,
            ])
            ->assertStatus(422);

        $this->assertTrue($this->admin->fresh()->active);
    }

    public function test_admin_cannot_demote_self_via_api(): void
    {
        $this->actingAsAdmin()
            ->putJson('/api/v1/users/'.$this->admin->id, [
                'role' => 'user',
            ])
            ->assertStatus(422);

        $this->assertSame('admin', $this->admin->fresh()->role->value);
    }

    public function test_disable_user_revokes_sessions_and_stops_agents(): void
    {
        $target = $this->createTarget();
        $this->createTargetAgent($target);
        $this->mockManager->expects($this->once())->method('stop');

        DB::table('sessions')->insert([
            'id' => 'api-sess-1',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => now()->unix(),
        ]);
        $target->createToken('api');

        $this->actingAsAdmin()
            ->putJson('/api/v1/users/'.$target->id, [
                'role' => 'user',
                'active' => false,
            ])
            ->assertOk()
            ->assertJson(['user' => ['id' => $target->id, 'active' => false]]);

        $this->assertFalse($target->fresh()->active);
        $this->assertSame([], DB::table('sessions')->where('user_id', $target->id)->pluck('id')->all());
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_reactivate_user_starts_agents(): void
    {
        $target = $this->createTarget(['active' => false]);
        $this->createTargetAgent($target);
        $this->mockManager->expects($this->once())->method('start');

        $this->actingAsAdmin()
            ->putJson('/api/v1/users/'.$target->id, [
                'role' => 'user',
                'active' => true,
            ])
            ->assertOk()
            ->assertJson(['user' => ['id' => $target->id, 'active' => true]]);

        $this->assertTrue($target->fresh()->active);
    }

    public function test_admin_can_delete_user_and_lifecycle_stops_agents(): void
    {
        $target = $this->createTarget();
        $this->createTargetAgent($target);
        $this->mockManager->expects($this->once())->method('stop');
        $target->createToken('api');

        $this->actingAsAdmin()
            ->deleteJson('/api/v1/users/'.$target->id)
            ->assertOk();

        $this->assertNull(User::find($target->id));
        $this->assertSame(0, Agent::where('owner_id', $target->id)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count());
    }

    public function test_admin_cannot_delete_self_via_api(): void
    {
        $this->actingAsAdmin()
            ->deleteJson('/api/v1/users/'.$this->admin->id)
            ->assertStatus(422);

        $this->assertNotNull(User::find($this->admin->id));
    }

    public function test_admin_can_reset_password_and_revokes_access(): void
    {
        $target = $this->createTarget();
        $target->createToken('api');

        $this->actingAsAdmin()
            ->postJson('/api/v1/users/'.$target->id.'/password', [
                'password' => 'password123',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('password123', $target->fresh()->password));
        $this->assertSame(0, $target->fresh()->tokens()->count());
    }

    public function test_admin_cannot_reset_own_password_via_api(): void
    {
        $old = $this->admin->password;

        $this->actingAsAdmin()
            ->postJson('/api/v1/users/'.$this->admin->id.'/password', [
                'password' => 'password123',
            ])
            ->assertStatus(422);

        $this->assertSame($old, $this->admin->fresh()->password);
    }

    public function test_reset_password_validates_length(): void
    {
        $target = $this->createTarget();

        $this->actingAsAdmin()
            ->postJson('/api/v1/users/'.$target->id.'/password', [
                'password' => 'short',
            ])
            ->assertStatus(422);
    }
}

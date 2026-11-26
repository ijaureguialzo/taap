<?php

namespace Tests\Feature\Users;

use App\Agents\AgentManager;
use App\Enums\UserRole;
use App\Livewire\Users\Index;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
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

        $this->admin = User::factory()->admin()->create(['name' => 'Admin Principal', 'email' => 'admin@taap.test']);
        $this->actingAs($this->admin);
        app()->setLocale('es');
    }

    protected function createTarget(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['name' => 'Target', 'email' => 'target@taap.test'], $attrs));
    }

    protected function createTargetAgent(User $user, string $status = 'idle'): Agent
    {
        return Agent::factory()->create(['owner_id' => $user->id, 'status' => $status]);
    }

    protected function hasFlashError(): bool
    {
        return in_array('error', session()->get('_flash.new', []), true);
    }

    /* ─────────────── alta ─────────────── */

    public function test_admin_can_create_user(): void
    {
        Livewire::test(Index::class)
            ->call('startCreate')
            ->set('create_name', 'Nuevo Usuario')
            ->set('create_email', 'nuevo@taap.test')
            ->set('create_password', 'password123')
            ->set('create_password_confirmation', 'password123')
            ->set('create_role', 'admin')
            ->set('create_max_agents', 5)
            ->set('create_language', 'eu')
            ->set('create_active', false)
            ->call('create')
            ->assertHasNoErrors();

        $user = User::where('email', 'nuevo@taap.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('Nuevo Usuario', $user->name);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertFalse($user->active);
        $this->assertSame('eu', $user->locale);
        $this->assertSame(5, $user->agent_quota);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_create_rejects_duplicate_email(): void
    {
        Livewire::test(Index::class)
            ->call('startCreate')
            ->set('create_name', 'Duplicado')
            ->set('create_email', 'admin@taap.test')
            ->set('create_password', 'password123')
            ->set('create_password_confirmation', 'password123')
            ->call('create')
            ->assertHasErrors(['create_email']);

        $this->assertSame(1, User::where('email', 'admin@taap.test')->count());
    }

    public function test_create_rejects_invalid_role(): void
    {
        Livewire::test(Index::class)
            ->call('startCreate')
            ->set('create_name', 'Rol Roto')
            ->set('create_email', 'raro@taap.test')
            ->set('create_password', 'password123')
            ->set('create_password_confirmation', 'password123')
            ->set('create_role', 'superuser')
            ->call('create')
            ->assertHasErrors(['create_role']);

        $this->assertNull(User::where('email', 'raro@taap.test')->first());
    }

    /* ─────────────── guardas anti-auto-bloqueo ─────────────── */

    public function test_admin_cannot_disable_self(): void
    {
        $this->mockManager->expects($this->never())->method('stop');

        Livewire::test(Index::class)
            ->call('startEdit', $this->admin->id)
            ->set('active', false)
            ->call('save');

        $this->assertTrue($this->admin->fresh()->active, 'la cuenta propia no debe deshabilitarse');
        $this->assertTrue($this->hasFlashError());
    }

    public function test_admin_cannot_demote_self(): void
    {
        Livewire::test(Index::class)
            ->call('startEdit', $this->admin->id)
            ->set('role', UserRole::User)
            ->call('save');

        $this->assertSame(UserRole::Admin, $this->admin->fresh()->role);
        $this->assertTrue($this->hasFlashError());
    }

    public function test_admin_cannot_delete_self(): void
    {
        Livewire::test(Index::class)
            ->call('delete', $this->admin->id);

        $this->assertNotNull(User::find($this->admin->id));
        $this->assertTrue($this->hasFlashError());
    }

    /* ─────────────── baja / reactivación ─────────────── */

    public function test_admin_can_disable_user_and_lifecycle_stops_agents_and_revokes_access(): void
    {
        $target = $this->createTarget();
        $this->createTargetAgent($target);
        $this->mockManager->expects($this->once())->method('stop');

        DB::table('sessions')->insert([
            'id' => 'sess-target',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => now()->unix(),
        ]);
        $target->createToken('api');

        Livewire::test(Index::class)
            ->call('startEdit', $target->id)
            ->set('active', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($target->fresh()->active);
        $this->assertSame([], DB::table('sessions')->where('user_id', $target->id)->pluck('id')->all());
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_admin_can_reactivate_user_and_agents_start(): void
    {
        $target = $this->createTarget(['active' => false]);
        $this->createTargetAgent($target);
        $this->mockManager->expects($this->once())->method('start');

        Livewire::test(Index::class)
            ->call('startEdit', $target->id)
            ->set('active', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($target->fresh()->active);
    }

    /* ─────────────── reseteo de contraseña ─────────────── */

    public function test_admin_can_reset_password_and_revokes_access(): void
    {
        $target = $this->createTarget();
        $target->createToken('api');

        Livewire::test(Index::class)
            ->call('startEdit', $target->id)
            ->set('reset_password', 'password123')
            ->set('reset_password_confirmation', 'password123')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('password123', $target->fresh()->password));
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_admin_cannot_reset_own_password_from_panel(): void
    {
        $oldPassword = $this->admin->password;

        Livewire::test(Index::class)
            ->call('startEdit', $this->admin->id)
            ->set('reset_password', 'password123')
            ->set('reset_password_confirmation', 'password123')
            ->call('resetPassword');

        $this->assertSame($oldPassword, $this->admin->fresh()->password);
        $this->assertTrue($this->hasFlashError());
    }

    public function test_reset_password_requires_confirmation(): void
    {
        $target = $this->createTarget();

        Livewire::test(Index::class)
            ->call('startEdit', $target->id)
            ->set('reset_password', 'password123')
            ->set('reset_password_confirmation', 'diferente123')
            ->call('resetPassword')
            ->assertHasErrors(['reset_password']);

        $this->assertFalse(Hash::check('password123', $target->fresh()->password));
    }

    /* ─────────────── borrado ─────────────── */

    public function test_admin_can_delete_user_with_agents_stopped(): void
    {
        $target = $this->createTarget();
        $this->createTargetAgent($target);
        $this->mockManager->expects($this->once())->method('stop');
        $target->createToken('api');

        Livewire::test(Index::class)
            ->call('delete', $target->id);

        $this->assertNull(User::find($target->id));
        $this->assertSame(0, Agent::where('owner_id', $target->id)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count());
    }

    public function test_delete_flash_user_session_message(): void
    {
        $target = $this->createTarget();

        Livewire::test(Index::class)
            ->call('delete', $target->id);

        $this->assertContains('success', session()->get('_flash.new', []));
    }
}

<?php

namespace Tests\Feature\Settings;

use App\Agents\AgentManager;
use App\Models\Agent;
use App\Models\User;
use App\Support\UserLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockManager = $this->createMock(AgentManager::class);
        $this->mockManager->method('start')->willReturnCallback(fn ($a) => $a);
        $this->mockManager->method('stop')->willReturnCallback(fn ($a) => $a);

        $this->app->instance(AgentManager::class, $this->mockManager);
        $this->lifecycle = app(UserLifecycle::class);
    }

    protected ?\PHPUnit\Framework\MockObject\MockObject $mockManager = null;

    protected function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['name' => 'Ciclo', 'email' => 'ciclo@taap.test'], $attrs));
    }

    protected function createRunningAgent(User $user): Agent
    {
        return Agent::factory()->running()->create(['owner_id' => $user->id]);
    }

    protected function createSessions(User $user, string ...$ids): void
    {
        foreach ($ids as $id) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => 'test',
                'last_activity' => now()->unix(),
            ]);
        }
    }

    protected function sessionIds(User $user): array
    {
        return DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all();
    }

    public function test_stop_and_revoke_stops_running_agents_and_revokes_access(): void
    {
        $user = $this->makeUser();
        $running = $this->createRunningAgent($user);
        $alsoRunning = $this->createRunningAgent($user);
        $this->mockManager->expects($this->exactly(2))->method('stop');

        $this->createSessions($user, 'sess-1', 'sess-2');
        $token = $user->createToken('token')->plainTextToken;

        $this->lifecycle->stopAndRevoke($user);

        $this->assertFalse($user->fresh()->active);
        $this->assertSame([], $this->sessionIds($user));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_stop_and_revoke_keeps_token_when_requested(): void
    {
        $user = $this->makeUser();
        $this->createRunningAgent($user);

        $token = $user->createToken('api');
        $tokenId = $token->accessToken->id ?? null;

        $this->mockManager->expects($this->once())->method('stop');

        $this->lifecycle->stopAndRevoke($user, keepTokenId: $tokenId);

        $this->assertNotNull($this->freshToken($user, $tokenId));
    }

    protected function freshToken(User $user, ?int $id): ?\Laravel\Sanctum\PersonalAccessToken
    {
        return $id === null ? null : $user->tokens()->find($id);
    }

    public function test_stop_and_revoke_ignores_already_stopped_agents(): void
    {
        $user = $this->makeUser();
        Agent::factory()->create(['owner_id' => $user->id, 'status' => 'stopped']);
        Agent::factory()->create(['owner_id' => $user->id, 'status' => 'offline']);

        $this->mockManager->expects($this->never())->method('stop');

        $this->lifecycle->stopAndRevoke($user);

        $this->assertFalse($user->fresh()->active);
    }

    public function test_reactivate_starts_agents_and_sets_active(): void
    {
        $user = $this->makeUser(['active' => false]);
        $agent = $this->createRunningAgent($user);

        // El lifecycle recarga el agente desde la BD: compara la llamada, no la instancia.
        $this->mockManager->expects($this->once())->method('start');

        $this->lifecycle->reactivate($user);

        $this->assertTrue($user->fresh()->active);
    }

    public function test_change_password_applies_new_password_and_revokes_sessions_and_tokens(): void
    {
        $user = $this->makeUser();
        $this->createSessions($user, 'sess-1', 'sess-2');
        $token = $user->createToken('api');
        $tokenId = $token->accessToken->id;

        $this->lifecycle->changePassword($user, 'nueva-clave-segura-123', keepTokenId: $tokenId, keepSessionId: 'sess-1');

        $this->assertSame('sess-1', $this->sessionIds($user)[0] ?? null);
        $this->assertSame($tokenId, $user->tokens()->first()?->id);

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('nueva-clave-segura-123', $user->fresh()->password));
    }

    public function test_delete_user_stops_agents_revokes_access_and_deletes(): void
    {
        $user = $this->makeUser();
        $this->createRunningAgent($user);
        $this->createSessions($user, 'sess-1');
        $user->createToken('api');

        $this->mockManager->expects($this->once())->method('stop');

        $this->lifecycle->deleteUser($user);

        $this->assertNull(User::find($user->id));
        // Al borrar, los tokens (y sesiones cargados tras delete) desaparecen.
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }
}

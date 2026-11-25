<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentManager;
use App\Enums\AgentRuntime;
use App\Enums\AgentStatus;
use App\Enums\FsMountMode;
use App\Livewire\Agents\Detail;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * Carpetas del FS compartido en el detalle del agente (shared-filesystem
 * 7.1): el propietario navega desde su home (y no puede salirse de ella);
 * el admin desde la raiz del arbol (paths ajenos se marcan "externa").
 * Guardar crea/actualiza `agent_fs_mounts` y el cambio dispara el flujo 6.5
 * (restart) con su aviso; la sesion se conserva.
 */
class DetailFsTest extends TestCase
{
    use RefreshDatabase;

    protected string $root;

    protected User $admin;

    protected User $ana;

    protected User $carlos;

    protected Agent $agent;

    protected MockObject $mockManager;

    /** Mock de AgentManager: las operaciones de ciclo de vida devuelven el agente. */
    protected function makeMockManager(): MockObject
    {
        $mock = $this->createMock(AgentManager::class);
        $mock->method('restart')->willReturnCallback(fn ($a) => $a);
        $mock->method('start')->willReturnCallback(fn ($a) => $a);
        $mock->method('stop')->willReturnCallback(fn ($a) => $a);
        $mock->method('terminate');

        return $mock;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // El mock devuelve el agente (no null): los metodos del manager lo
        // devuelven y el componente lo reasigna.
        $this->mockManager = $this->makeMockManager();
        $this->app->instance(AgentManager::class, $this->mockManager);
        Queue::fake();

        $this->root = sys_get_temp_dir().'/taap-fs-detail-'.uniqid();
        mkdir($this->root, 0755, true);
        config(['taap.fs.fs_base' => $this->root, 'taap.fs.fs_host_base' => $this->root]);

        $this->admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@taap.test', 'fs_slug' => 'administrador']);
        $this->ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@taap.test', 'fs_slug' => 'ana']);
        $this->carlos = User::factory()->create(['name' => 'Carlos', 'email' => 'carlos@taap.test', 'fs_slug' => 'carlos']);

        // Contenido conocido
        mkdir($this->root.'/ana/proyectos', 0755, true);
        mkdir($this->root.'/ana/informes', 0755, true);
        mkdir($this->root.'/carlos/datos', 0755, true);
        file_put_contents($this->root.'/ana/proyectos/n.txt', 'x');
        file_put_contents($this->root.'/carlos/datos/suyo.txt', 'y');

        $this->agent = Agent::factory()->create([
            'owner_id' => $this->ana->id,
            'name' => 'Asistente',
            'runtime' => AgentRuntime::Pi,
            'status' => AgentStatus::Idle,
        ]);

        app()->setLocale('es');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }

        parent::tearDown();
    }

    public function test_owner_picker_starts_at_home_and_admin_at_root(): void
    {
        $this->actingAs($this->ana);
        Livewire::test(Detail::class, ['agent' => $this->agent])
            ->assertSee('proyectos')
            ->assertSee('informes')
            ->assertDontSee('suyo');

        $this->actingAs($this->admin);
        $html = Livewire::test(Detail::class, ['agent' => $this->agent])->html();

        $this->assertStringContainsString('ana', $html, 'el admin ve las homes desde la raiz');
        $this->assertStringContainsString('carlos', $html);
    }

    public function test_owner_cannot_add_path_outside_home(): void
    {
        $this->actingAs($this->ana);
        Livewire::test(Detail::class, ['agent' => $this->agent])
            ->set('newFsPath', '../carlos/datos')
            ->set('newFsMode', FsMountMode::ReadOnly->value)
            ->call('addFsMount')
            ->assertSet('fsError', __('agents.fs_outside_home'));

        $this->assertCount(0, $this->agent->fsMounts()->get());
    }

    public function test_owner_adds_folder_from_home_creates_mount_and_restarts(): void
    {
        $manager = $this->mockManager;
        $manager->expects($this->once())->method('restart');

        $this->actingAs($this->ana);
        Livewire::test(Detail::class, ['agent' => $this->agent])
            ->set('newFsPath', 'proyectos')
            ->set('newFsMode', FsMountMode::ReadWrite->value)
            ->call('addFsMount')
            ->assertSet('fsError', null);

        $mounts = $this->agent->fsMounts()->get();
        $this->assertCount(1, $mounts);
        $this->assertSame('/ana/proyectos', $mounts[0]->path, 'canonico desde la raiz /fs');
        $this->assertSame(FsMountMode::ReadWrite, $mounts[0]->mode);

        $this->assertContains('warning', session()->get('_flash.new', []), 'el aviso de aplicacion flasho (6.5)');
    }

    public function test_admin_can_add_external_folder_marked_external(): void
    {
        $manager = $this->mockManager;
        $manager->expects($this->once())->method('restart');

        $this->actingAs($this->admin);
        $test = Livewire::test(Detail::class, ['agent' => $this->agent])
            ->set('newFsPath', '/carlos/datos')
            ->set('newFsMode', FsMountMode::ReadOnly->value)
            ->call('addFsMount')
            ->assertSee('/fs/carlos/datos');

        $test->assertSee('externa', escape: false);

        $this->assertSame('/carlos/datos', $this->agent->fsMounts()->first()->path);
    }

    public function test_mode_change_updates_mount_and_restarts(): void
    {
        $mount = $this->agent->fsMounts()->create(['path' => '/ana/proyectos', 'mode' => FsMountMode::ReadWrite->value]);

        $manager = $this->mockManager;
        $manager->expects($this->once())->method('restart');

        $this->actingAs($this->ana);
        Livewire::test(Detail::class, ['agent' => $this->agent])
            ->call('updateFsMode', (string) $mount->id, FsMountMode::ReadOnly->value)
            ->assertSee('Solo lectura');

        $this->assertSame(FsMountMode::ReadOnly, $mount->refresh()->mode);
        $this->assertContains('warning', session()->get('_flash.new', []), 'el aviso de aplicacion flasho (6.5)');
    }

    public function test_remove_mount_deletes_row_and_restarts(): void
    {
        $mount = $this->agent->fsMounts()->create(['path' => '/ana/informes', 'mode' => FsMountMode::ReadOnly->value]);

        $manager = $this->mockManager;
        $manager->expects($this->once())->method('restart');

        $this->actingAs($this->ana);
        Livewire::test(Detail::class, ['agent' => $this->agent])
            ->call('removeFsMount', (string) $mount->id)
            ->assertDontSee('/fs/ana/informes');

        $this->assertDatabaseMissing('agent_fs_mounts', ['id' => $mount->id]);
    }

    public function test_non_owner_cannot_see_detail(): void
    {
        $stranger = User::factory()->create(['fs_slug' => 'extra']);

        $this->actingAs($stranger);
        Livewire::test(Detail::class, ['agent' => $this->agent])
            ->assertStatus(404, 'no es ni admin ni propietario');
    }
}

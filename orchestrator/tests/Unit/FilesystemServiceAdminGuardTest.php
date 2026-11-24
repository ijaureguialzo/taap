<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\FilesystemException;
use App\Services\FilesystemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FS compartido (3.4): proteccion de los directorios de usuario en la vista
 * de admin — identificados con el nombre del propietario (badge), no
 * renombrables y borrado con confirmacion explicita.
 */
class FilesystemServiceAdminGuardTest extends TestCase
{
    use RefreshDatabase;

    protected string $root;

    protected FilesystemService $fs;

    protected User $admin;

    protected User $ana;

    protected User $carlos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/taap-fs-ax-'.uniqid();
        mkdir($this->root, 0755, true);
        $this->root = (string) realpath($this->root);
        mkdir($this->root.'/ana', 0755, true);
        mkdir($this->root.'/carlos', 0755, true);

        $this->fs = new FilesystemService($this->root);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@taap.test', 'password' => 'x', 'role' => UserRole::Admin, 'fs_slug' => 'administrador']);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@taap.test', 'password' => 'x', 'role' => UserRole::User, 'fs_slug' => 'ana']);
        $this->carlos = User::create(['name' => 'Carlos', 'email' => 'carlos@taap.test', 'password' => 'x', 'role' => UserRole::User, 'fs_slug' => 'carlos']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }

        parent::tearDown();
    }

    /* ---------- badge en el listado ---------- */

    public function test_list_shows_owner_for_user_dirs_only(): void
    {
        mkdir($this->root.'/proyectos', 0755, true);
        mkdir($this->root.'/proyectos/ana', 0755, true); // "ana" fuera del nivel 1: sin badge
        file_put_contents($this->root.'/leido.txt', 'x');

        $listing = $this->fs->list($this->admin, '/');
        $byName = array_column($listing['entries'], null, 'name');

        $this->assertSame('Ana', $byName['ana']['owner']);
        $this->assertSame('Carlos', $byName['carlos']['owner']);
        $this->assertNull($byName['proyectos']['owner']);
        $this->assertNull($byName['leido.txt']['owner']);
    }

    public function test_subuser_sees_no_owner_field(): void
    {
        mkdir($this->root.'/ana/ana', 0755, true); // su propia subcarpetita "ana"
        $listing = $this->fs->list($this->ana, '/');
        $byName = array_column($listing['entries'], null, 'name');

        $this->assertArrayNotHasKey('owner', $byName['ana']);
    }

    /* ---------- no renombrables ---------- */

    public function test_admin_cannot_rename_user_dir(): void
    {
        try {
            $this->fs->rename($this->admin, '/ana', 'bob');
            $this->fail('esperado 422');
        } catch (FilesystemException $e) {
            $this->assertSame(422, $e->status);
        }

        $this->assertDirectoryExists($this->root.'/ana');
        $this->assertDirectoryDoesNotExist($this->root.'/bob');
    }

    public function test_admin_can_rename_plain_dir(): void
    {
        mkdir($this->root.'/proyectos', 0755, true);
        $this->fs->rename($this->admin, '/proyectos', 'trabajos');
        $this->assertDirectoryExists($this->root.'/trabajos');
    }

    public function test_user_can_rename_dir_with_userslug_name_inside_home(): void
    {
        // El guard solo aplica a la raiz fisica vista por admin.
        mkdir($this->root.'/ana/carlos', 0755, true);
        $this->fs->rename($this->ana, '/carlos', 'x');
        $this->assertDirectoryExists($this->root.'/ana/x');
    }

    /* ---------- borrado con confirmacion ---------- */

    public function test_admin_delete_user_dir_requires_confirm(): void
    {
        mkdir($this->root.'/ana/docs', 0755, true);
        file_put_contents($this->root.'/ana/docs/b.txt', 'x');

        try {
            $this->fs->delete($this->admin, '/ana');
            $this->fail('esperado 409');
        } catch (FilesystemException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertDirectoryExists($this->root.'/ana'); // intacto

        $this->fs->delete($this->admin, '/ana', confirm: true);
        $this->assertDirectoryDoesNotExist($this->root.'/ana'); // borrado recursivo
    }

    public function test_admin_delete_plain_dir_no_confirm(): void
    {
        mkdir($this->root.'/proyectos', 0755, true);
        $this->fs->delete($this->admin, '/proyectos');
        $this->assertDirectoryDoesNotExist($this->root.'/proyectos');
    }

    public function test_admin_delete_user_file_no_confirm(): void
    {
        file_put_contents($this->root.'/ana/nota.txt', 'x');
        $this->fs->delete($this->admin, '/ana/nota.txt');
        $this->assertFileDoesNotExist($this->root.'/ana/nota.txt');
        $this->assertDirectoryExists($this->root.'/ana'); // la home sigue
    }
}

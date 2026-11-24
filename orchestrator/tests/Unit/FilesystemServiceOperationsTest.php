<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\FilesystemException;
use App\Services\FilesystemService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * FS compartido (3.2): operaciones del explorador — list (dirs primero),
 * mkdir (recursivo, nombre validado), rename (409 si el destino existe) y
 * delete (recursivo, symlinks sin seguir). Cubre la spec "Explorador web de
 * ficheros" (crear, renombrar, borrar, nombres inválidos, escape por
 * symlink) sobre un FS temporal.
 */
class FilesystemServiceOperationsTest extends TestCase
{
    protected string $root;

    protected FilesystemService $fs;

    protected User $ana;

    protected User $carlos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/taap-fs-op-'.uniqid();
        mkdir($this->root, 0755, true);
        $this->root = (string) realpath($this->root);
        mkdir($this->root.'/ana', 0755, true);
        mkdir($this->root.'/carlos', 0755, true);

        $this->fs = new FilesystemService($this->root);
        $this->ana = new User(['name' => 'Ana', 'email' => 'ana@taap.test', 'role' => UserRole::User, 'fs_slug' => 'ana']);
        $this->carlos = new User(['name' => 'Carlos', 'email' => 'carlos@taap.test', 'role' => UserRole::User, 'fs_slug' => 'carlos']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }

        parent::tearDown();
    }

    /* ---------- crear ---------- */

    public function test_mkdir_recursive_and_listed(): void
    {
        // Spec: "crea la carpeta datos/pruebas y sube fichero.txt dentro"
        $created = $this->fs->mkdir($this->ana, 'datos/pruebas');
        $this->assertSame('/datos/pruebas', $created);
        $this->assertDirectoryExists($this->root.'/ana/datos/pruebas');

        $listing = $this->fs->list($this->ana, '/datos');
        $this->assertSame(['pruebas'], array_column($listing['entries'], 'name'));
    }

    public function test_mkdir_existing_conflicts(): void
    {
        File::ensureDirectoryExists($this->root.'/ana/datos');
        $this->expectException(FilesystemException::class);
        $this->fs->mkdir($this->ana, 'datos');
    }

    public function test_mkdir_invalid_name_rejected(): void
    {
        $this->expectException(FilesystemException::class);
        $this->fs->mkdir($this->ana, "datos/x\0y");
    }

    public function test_mkdir_name_too_long_rejected(): void
    {
        $this->expectException(FilesystemException::class);
        $this->fs->mkdir($this->ana, 'datos/'.str_repeat('a', 256));
    }

    /* ---------- renombrar ---------- */

    public function test_rename_file(): void
    {
        file_put_contents($this->root.'/ana/f.txt', 'x');
        $this->fs->rename($this->ana, '/f.txt', 'g.txt');
        $this->assertFileDoesNotExist($this->root.'/ana/f.txt');
        $this->assertFileExists($this->root.'/ana/g.txt');
    }

    public function test_rename_dir_moves_contents(): void
    {
        File::ensureDirectoryExists($this->root.'/ana/old');
        file_put_contents($this->root.'/ana/old/sub.txt', 'x');
        $this->fs->rename($this->ana, '/old', 'new');
        $this->assertFileExists($this->root.'/ana/new/sub.txt');
    }

    public function test_rename_to_existing_conflicts(): void
    {
        file_put_contents($this->root.'/ana/a.txt', 'a');
        file_put_contents($this->root.'/ana/b.txt', 'b');
        try {
            $this->fs->rename($this->ana, '/a.txt', 'b.txt');
            $this->fail('expected 409');
        } catch (FilesystemException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertFileExists($this->root.'/ana/a.txt'); // intacto tras el 409
    }

    public function test_rename_missing_conflicts_with_404(): void
    {
        $this->expectException(FilesystemException::class);
        $this->fs->rename($this->ana, '/no-existe.txt', 'otro.txt');
    }

    public function test_rename_invalid_name_rejected(): void
    {
        file_put_contents($this->root.'/ana/f.txt', 'x');
        $this->expectException(FilesystemException::class);
        $this->fs->rename($this->ana, '/f.txt', "x\0y");
    }

    /* ---------- borrar ---------- */

    public function test_delete_file(): void
    {
        file_put_contents($this->root.'/ana/f.txt', 'x');
        $this->fs->delete($this->ana, '/f.txt');
        $this->assertFileDoesNotExist($this->root.'/ana/f.txt');
    }

    public function test_delete_recursive(): void
    {
        File::ensureDirectoryExists($this->root.'/ana/tree/a/b');
        file_put_contents($this->root.'/ana/tree/a/b/c.txt', 'x');
        $this->fs->delete($this->ana, '/tree');
        $this->assertDirectoryDoesNotExist($this->root.'/ana/tree');
    }

    public function test_delete_missing_404(): void
    {
        $this->expectException(FilesystemException::class);
        $this->fs->delete($this->ana, '/no-existe');
    }

    public function test_cannot_delete_own_root(): void
    {
        $this->expectException(FilesystemException::class);
        $this->fs->delete($this->ana, '/');
    }

    public function test_delete_does_not_follow_symlinks(): void
    {
        file_put_contents($this->root.'/carlos/dentro.txt', 'x');
        symlink($this->root.'/carlos', $this->root.'/ana/enlace');
        $this->fs->delete($this->ana, '/enlace');
        $this->assertFileExists($this->root.'/carlos/dentro.txt'); // el enlazado sigue vivo
    }

    /* ---------- listar ---------- */

    public function test_list_dirs_first_then_name(): void
    {
        file_put_contents($this->root.'/ana/bfile.txt', 'b');
        file_put_contents($this->root.'/ana/afile.txt', 'a');
        mkdir($this->root.'/ana/bdir');
        mkdir($this->root.'/ana/adir');

        $names = array_column($this->fs->list($this->ana, '/')['entries'], 'name');
        $this->assertSame(['adir', 'bdir', 'afile.txt', 'bfile.txt'], $names);
    }

    public function test_list_symlink_to_dir_shown_as_dir(): void
    {
        // Spec: el listado muestra symlink de directorio como carpeta.
        File::ensureDirectoryExists($this->root.'/ana/inner');
        symlink($this->root.'/ana/inner', $this->root.'/ana/enlace');

        $listing = $this->fs->list($this->ana, '/');
        $types = array_column($listing['entries'], 'type', 'name');
        $this->assertSame('dir', $types['enlace']);

        $inside = $this->fs->list($this->ana, '/enlace');
        $this->assertCount(0, $inside['entries']);
    }

    public function test_navigating_into_symlink_outside_base_404(): void
    {
        // Spec: "un symlink que apunta fuera de tu raíz → no puedes navegar por él".
        symlink($this->root.'/carlos', $this->root.'/ana/enlace');
        $this->expectException(FilesystemException::class);
        $this->fs->list($this->ana, '/enlace');
    }

    public function test_list_missing_404(): void
    {
        $this->expectException(FilesystemException::class);
        $this->fs->list($this->ana, '/no-existe');
    }
}

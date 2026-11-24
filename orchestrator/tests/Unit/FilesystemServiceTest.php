<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\FilesystemException;
use App\Services\FilesystemService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * FS compartido (3.1): raiz por rol + normalizacion canonica + contencion
 * por realpath. Cubre los casos de las specs: "Raiz de almacenamiento
 * fisico", "Directorio de usuario" (no sale de su raiz) y "Formato de
 * rutas Unix" (ruta no canonica). FS real en un directorio temporal; sin DB.
 */
class FilesystemServiceTest extends TestCase
{
    protected string $root;

    protected FilesystemService $fs;

    protected User $admin;

    protected User $ana;

    protected User $carlos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/taap-fs-svc-'.uniqid();
        mkdir($this->root, 0755, true);
        // En macOS /var es un symlink: realpath lo resuelve a /private/var.
        $this->root = realpath($this->root);
        mkdir($this->root.'/ana/proyectos', 0755, true);
        mkdir($this->root.'/carlos/datos', 0755, true);
        file_put_contents($this->root.'/carlos/datos/secret.txt', 'x');

        $this->fs = new FilesystemService($this->root);
        $this->admin = new User(['name' => 'Admin', 'email' => 'admin@taap.test', 'role' => UserRole::Admin, 'fs_slug' => 'admin']);
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

    /* ---------- spec "Formato de rutas Unix": ruta no canonica ---------- */

    #[DataProvider('nonCanonicalProvider')]
    public function test_normalize_canonicalizes(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->fs->normalize($input));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function nonCanonicalProvider(): array
    {
        return [
            'vacia' => ['', '/'],
            'relativa' => ['datos', '/datos'],
            'barra final' => ['datos/', '/datos'],
            'barras dobles' => ['//datos//x//', '/datos/x'],
            'punto' => ['./datos', '/datos'],
            'dote-dote que cancela' => ['/datos/../informes', '/informes'],
            'dote-dote al inicio' => ['/..', '/'],
            'dote-dote que se salta' => ['/a/../../..', '/'],
        ];
    }

    /* ---------- spec "Raiz de almacenamiento fisico" ---------- */

    public function test_admin_root_is_physical_root(): void
    {
        $this->assertSame($this->root, $this->fs->baseFor($this->admin));
        $this->assertSame($this->root, $this->fs->resolve($this->admin, '/'));
        $this->assertSame($this->root.'/carlos/datos/secret.txt', $this->fs->resolve($this->admin, '/carlos/datos/secret.txt'));
    }

    public function test_admin_sees_everything_but_not_outside(): void
    {
        $this->assertSame($this->root.'/ana/proyectos', $this->fs->resolve($this->admin, '/ana/proyectos'));
        $this->assertSame($this->root.'/carlos', $this->fs->resolve($this->admin, 'carlos/'));
    }

    /* ---------- spec "Directorio de usuario" ---------- */

    public function test_user_home_is_created_on_first_use(): void
    {
        $home = $this->fs->baseFor($this->ana);

        $this->assertSame($this->root.'/ana', $home);
        $this->assertDirectoryExists($home);
        // "/" del usuario = su home (spec: el explorador lo muestra como "/")
        $this->assertSame($home, $this->fs->resolve($this->ana, '/'));
    }

    public function test_user_cannot_leave_home_with_dotdot(): void
    {
        // Spec: "../otrouser" queda fuera de su directorio -> rechazado
        $this->expectException(FilesystemException::class);
        $this->fs->resolve($this->ana, '../carlos');
    }

    public function test_subdir_named_like_other_user_is_inside_home(): void
    {
        // Para ana, "/carlos" es un subdirectorio de SU home (dentro), no el
        // de Carlos (que se alcanzaria por "../carlos" -> 404, testeado arriba).
        mkdir($this->root.'/ana/carlos', 0755);

        $this->assertSame($this->root.'/ana/carlos', $this->fs->resolve($this->ana, '/carlos'));
    }

    public function test_symlink_leaving_home_is_rejected(): void
    {
        symlink($this->root.'/carlos', $this->root.'/ana/enlace');

        $this->expectException(FilesystemException::class);
        $this->fs->resolve($this->ana, '/enlace');
    }

    public function test_symlink_inside_home_resolves(): void
    {
        mkdir($this->root.'/ana/inner', 0755);
        symlink($this->root.'/ana/inner', $this->root.'/ana/enlace');

        $this->assertSame($this->root.'/ana/inner', $this->fs->resolve($this->ana, '/enlace'));
    }

    public function test_nonexistent_path_inside_root_resolves(): void
    {
        // Para mkdir: el path todavia no existe pero esta contenido
        $this->assertSame($this->root.'/ana/proyectos/nuevo', $this->fs->resolve($this->ana, '/proyectos/nuevo'));
    }

    /* ---------- path virtual (lo que ve el cliente) ---------- */

    public function test_virtual_path_is_relative_to_subject_root(): void
    {
        $this->assertSame('/proyectos', $this->fs->virtualPath($this->ana, 'proyectos/'));
        $this->assertSame('/', $this->fs->virtualPath($this->ana, '/'));
        $this->assertSame('/ana/proyectos', $this->fs->virtualPath($this->admin, 'ana/proyectos'));
    }

    public function test_user_without_slug_gets_conflict(): void
    {
        $user = new User(['name' => 'SinSlug', 'email' => 'x@taap.test', 'role' => UserRole::User, 'fs_slug' => null]);

        $this->expectException(FilesystemException::class);
        $this->fs->baseFor($user);
    }
}

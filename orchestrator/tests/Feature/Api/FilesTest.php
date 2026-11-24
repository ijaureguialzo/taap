<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * API v1 del FS compartido (shared-filesystem 4.1): la raiz se resuelve en el
 * servidor por rol (user = su home; admin = raiz fisica), un usuario no alcanza
 * el de otro (404) y los codigos de error son 404/409/413/422.
 */
class FilesTest extends TestCase
{
    use RefreshDatabase;

    protected string $root;

    protected User $admin;

    protected User $ana;

    protected User $carlos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/taap-fs-api-'.uniqid();
        mkdir($this->root, 0755, true);

        // El singleton FilesystemService se resuelve contra esta raiz.
        config(['taap.fs.fs_base' => $this->root]);

        $this->admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@taap.test', 'fs_slug' => 'administrador']);
        $this->ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@taap.test', 'fs_slug' => 'ana']);
        $this->carlos = User::factory()->create(['name' => 'Carlos', 'email' => 'carlos@taap.test', 'fs_slug' => 'carlos']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }

        parent::tearDown();
    }

    protected function asAnon(): void
    {
        $this->actingAs(null, 'sanctum');
    }

    /* ---------- listado por rol ---------- */

    public function test_user_lists_own_home_at_root(): void
    {
        file_put_contents($this->root.'/ana/mio.txt', 'x');
        file_put_contents($this->root.'/carlos/otro.txt', 'y');

        $this->actingAs($this->ana, 'sanctum')
            ->getJson('/api/v1/files?path=/')
            ->assertOk()
            ->assertJsonPath('path', '/')
            ->assertJsonFragment(['name' => 'mio.txt']);

        // No ve el area de Carlos.
        $body = $this->actingAs($this->ana, 'sanctum')->getJson('/api/v1/files?path=/')->json();
        $this->assertNotContains('otro.txt', array_column($body['entries'], 'name'));
    }

    public function test_admin_lists_physical_root(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/files?path=/')
            ->assertOk();

        $body = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/files?path=/')->json();
        $names = array_column($body['entries'], 'name');
        sort($names);
        $this->assertSame(['administrador', 'ana', 'carlos'], $names);

        // Badge: las homes de usuario identificadas por el propietario (3.4).
        $byName = array_column($body['entries'], null, 'name');
        $this->assertSame('Ana', $byName['ana']['owner']);
        $this->assertSame('Carlos', $byName['carlos']['owner']);
        $this->assertNull($byName['administrador']['owner']);
    }

    public function test_user_cannot_reach_other_user_404(): void
    {
        $this->actingAs($this->ana, 'sanctum')
            ->getJson('/api/v1/files?path=/../carlos')
            ->assertNotFound();
    }

    public function test_missing_dir_404(): void
    {
        $this->actingAs($this->ana, 'sanctum')
            ->getJson('/api/v1/files?path=/no-hay')
            ->assertNotFound();
    }

    public function test_unauthenticated_401(): void
    {
        $this->getJson('/api/v1/files?path=/')->assertUnauthorized();
    }

    /* ---------- crear / renombrar / borrar ---------- */

    public function test_create_folder_and_422_invalid(): void
    {
        $this->actingAs($this->ana, 'sanctum')
            ->postJson('/api/v1/files/folders', ['path' => 'datos/pruebas'])
            ->assertCreated()
            ->assertJsonPath('path', '/datos/pruebas');
        $this->assertDirectoryExists($this->root.'/ana/datos/pruebas');
    }

    public function test_create_folder_invalid_name_422(): void
    {
        $this->actingAs($this->ana, 'sanctum')
            ->postJson('/api/v1/files/folders', ['path' => "datos/x\0y"])
            ->assertStatus(422);
    }

    public function test_rename_and_conflict_409(): void
    {
        file_put_contents($this->root.'/ana/a.txt', 'a');
        file_put_contents($this->root.'/ana/b.txt', 'b');

        $this->actingAs($this->ana, 'sanctum')
            ->postJson('/api/v1/files/rename', ['path' => '/a.txt', 'newName' => 'c.txt'])
            ->assertOk()
            ->assertJsonPath('path', '/c.txt');
        $this->assertFileExists($this->root.'/ana/c.txt');

        $this->actingAs($this->ana, 'sanctum')
            ->postJson('/api/v1/files/rename', ['path' => '/c.txt', 'newName' => 'b.txt'])
            ->assertConflict();
    }

    public function test_admin_rename_user_dir_422(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/files/rename', ['path' => '/ana', 'newName' => 'bob'])
            ->assertStatus(422);
    }

    public function test_delete_file_and_user_dir_confirm(): void
    {
        file_put_contents($this->root.'/ana/f.txt', 'x');
        mkdir($this->root.'/carlos/docs', 0755, true);
        file_put_contents($this->root.'/carlos/docs/d.txt', 'y');

        $this->actingAs($this->ana, 'sanctum')
            ->deleteJson('/api/v1/files?path=/f.txt')
            ->assertOk();
        $this->assertFileDoesNotExist($this->root.'/ana/f.txt');

        // Home de usuario: sin confirm -> 409; con confirm -> borrado.
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/v1/files?path=/carlos')
            ->assertConflict();
        $this->assertDirectoryExists($this->root.'/carlos');

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/v1/files?path=/carlos&confirm=1')
            ->assertOk();
        $this->assertDirectoryDoesNotExist($this->root.'/carlos');
    }

    /* ---------- uploads / download ---------- */

    public function test_upload_and_download_roundtrip(): void
    {
        File::ensureDirectoryExists($this->root.'/ana/datos');

        $this->actingAs($this->ana, 'sanctum')
            ->post('/api/v1/files/uploads', [
                'path' => '/datos',
                'file' => new UploadedFile($this->tmpFile('hola'), 'fichero.txt', 'text/plain', null, true),
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'fichero.txt');

        $this->actingAs($this->ana, 'sanctum')
            ->get('/api/v1/files/download?path=/datos/fichero.txt')
            ->assertOk()
            ->assertDownload('fichero.txt');
        $this->assertSame('hola', file_get_contents($this->root.'/ana/datos/fichero.txt'));
    }

    public function test_upload_over_size_413(): void
    {
        config(['taap.fs.upload_max_size' => 5]);

        $this->actingAs($this->ana, 'sanctum')
            ->post('/api/v1/files/uploads', [
                'path' => '/',
                'file' => new UploadedFile($this->tmpFile(str_repeat('a', 100)), 'grande.bin', 'application/octet-stream', null, true),
            ])
            ->assertStatus(413);
    }

    public function test_download_folder_as_zip(): void
    {
        File::ensureDirectoryExists($this->root.'/ana/datos/pruebas');
        file_put_contents($this->root.'/ana/datos/pruebas/f.txt', 'hola');

        $this->actingAs($this->ana, 'sanctum')
            ->get('/api/v1/files/download?path=/datos')
            ->assertOk()
            ->assertDownload('datos.zip');
    }

    public function test_download_missing_404(): void
    {
        $this->actingAs($this->ana, 'sanctum')
            ->get('/api/v1/files/download?path=/no-hay')
            ->assertNotFound();
    }

    /* ---------- helpers ---------- */

    protected function tmpFile(string $content): string
    {
        $p = tempnam(sys_get_temp_dir(), 'taap-up-');
        file_put_contents($p, $content);

        return $p;
    }
}

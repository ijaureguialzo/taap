<?php

namespace Tests\Feature\Files;

use App\Livewire\Files\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Explorador web (shared-filesystem 5.2): cada accion sobre
 * FilesystemService — crear carpeta, subir (con tope), renombrar, borrar
 * (con confirmacion recursiva / home de usuario) y descargar (fichero o
 * .zip), mas los flujos de error.
 */
class ActionsTest extends TestCase
{
    use RefreshDatabase;

    protected string $root;

    protected User $admin;

    protected User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/taap-fs-act-'.uniqid();
        mkdir($this->root, 0755, true);
        config(['taap.fs.fs_base' => $this->root, 'taap.fs.fs_host_base' => $this->root]);

        $this->admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@taap.test', 'fs_slug' => 'administrador']);
        $this->ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@taap.test', 'fs_slug' => 'ana']);

        file_put_contents($this->root.'/ana/a.txt', 'a');
        file_put_contents($this->root.'/ana/b.txt', 'b');
        mkdir($this->root.'/ana/datos', 0755, true);
        file_put_contents($this->root.'/ana/datos/c.txt', 'c');

        app()->setLocale('es');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }
        Storage::disk('local')->deleteDirectory('livewire');

        parent::tearDown();
    }

    protected function asAna(): Testable
    {
        $this->actingAs($this->ana);

        return Livewire::test(Index::class);
    }

    protected function asAdmin(): Testable
    {
        $this->actingAs($this->admin);

        return Livewire::test(Index::class);
    }

    /* ---------- crear carpeta ---------- */

    public function test_create_folder_appears_in_listing(): void
    {
        $this->asAna()
            ->set('newFolder', 'proyectos')
            ->call('createFolder');

        $this->assertDirectoryExists($this->root.'/ana/proyectos');
        $this->asAna()->assertSee('proyectos');
    }

    public function test_create_nested_folder(): void
    {
        $this->asAna()->set('newFolder', 'datos/pruebas')->call('createFolder');
        $this->assertDirectoryExists($this->root.'/ana/datos/pruebas');
    }

    public function test_create_folder_invalid_name_shows_error(): void
    {
        $this->asAna()
            ->set('newFolder', "datos/x\0y")
            ->call('createFolder')
            ->assertSee('Nombre de entrada inválido');

        $this->assertDirectoryDoesNotExist($this->root.'/ana/datos/x\0y');
    }

    public function test_create_existing_folder_shows_conflict(): void
    {
        $this->asAna()
            ->set('newFolder', 'datos')
            ->call('createFolder')
            ->assertSee('Ya existe una entrada con ese nombre');
    }

    /* ---------- subir ---------- */

    public function test_upload_lands_in_current_dir(): void
    {
        $file = UploadedFile::fake()->create('subido.txt', 20, 'text/plain');

        $this->asAna()
            ->upload('uploadFile', [$file])
            ->call('doUpload');

        $this->assertFileExists($this->root.'/ana/subido.txt');
        $this->asAna()->assertSee('subido.txt');
    }

    public function test_upload_to_subdir_after_navigating(): void
    {
        $file = UploadedFile::fake()->create('deep.txt', 10, 'text/plain');

        $this->asAna()
            ->call('open', '/datos')
            ->upload('uploadFile', [$file])
            ->call('doUpload');

        $this->assertFileExists($this->root.'/ana/datos/deep.txt');
    }

    public function test_upload_over_size_shows_413_error(): void
    {
        config(['taap.fs.upload_max_size' => 1]);
        config(['taap.fs.upload_max_size' => 5]);
        $file = UploadedFile::fake()->createWithContent('grande.bin', str_repeat('x', 4096));

        $this->asAna()
            ->upload('uploadFile', [$file])
            ->call('doUpload')
            ->assertSee('supera el tamano maximo');

        $this->assertFileDoesNotExist($this->root.'/ana/grande.bin');
    }

    public function test_upload_without_file_shows_error(): void
    {
        $this->asAna()
            ->call('doUpload')
            ->assertSee('Ningún fichero pendiente');
    }

    public function test_upload_progress_tracked(): void
    {
        $component = $this->asAna();
        $component->call('onUploadProgress', 42);
        $this->assertSame(42, $component->instance()->uploadProgress);
    }

    /* ---------- renombrar ---------- */

    public function test_rename_flow(): void
    {
        $this->asAna()
            ->call('startRename', 'a.txt')
            ->assertSee('a.txt') // modal precargado
            ->set('renameName', 'renombrada.txt')
            ->call('saveRename');

        $this->assertFileDoesNotExist($this->root.'/ana/a.txt');
        $this->assertFileExists($this->root.'/ana/renombrada.txt');
    }

    public function test_rename_conflict_keeps_original(): void
    {
        $this->asAna()
            ->call('startRename', 'a.txt')
            ->set('renameName', 'b.txt')
            ->call('saveRename')
            ->assertSee('Ya existe una entrada con ese nombre en el destino');

        $this->assertFileExists($this->root.'/ana/a.txt');
        $this->assertFileExists($this->root.'/ana/b.txt');
    }

    public function test_admin_rename_user_home_rejected(): void
    {
        $this->asAdmin()
            ->call('startRename', 'ana')
            ->set('renameName', 'bob')
            ->call('saveRename')
            ->assertSee('no se pueden renombrar');

        $this->assertDirectoryExists($this->root.'/ana');
    }

    /* ---------- borrar ---------- */

    public function test_delete_file_is_direct(): void
    {
        $component = $this->asAna()->call('delete', 'a.txt');

        $this->assertFileDoesNotExist($this->root.'/ana/a.txt');
        $this->assertNull($component->deleteTarget); // sin modal
    }

    public function test_delete_folder_requires_confirmation(): void
    {
        $component = $this->asAna()->call('delete', 'datos');

        // Modal abierto, nada borrado todavia.
        $this->assertSame('/datos', $component->deleteTarget);
        $this->assertDirectoryExists($this->root.'/ana/datos');

        $component->call('confirmDelete');
        $this->assertDirectoryDoesNotExist($this->root.'/ana/datos'); // recursivo
    }

    public function test_delete_folder_cancellation(): void
    {
        $component = $this->asAna()
            ->call('delete', 'datos')
            ->call('cancelDelete');

        $this->assertNull($component->deleteTarget);
        $this->assertDirectoryExists($this->root.'/ana/datos');
    }

    public function test_admin_delete_user_home_requires_confirmation(): void
    {
        $component = $this->asAdmin()->call('delete', 'ana');

        $this->assertSame('/ana', $component->deleteTarget);
        $this->assertDirectoryExists($this->root.'/ana');

        $component->call('confirmDelete');
        $this->assertDirectoryDoesNotExist($this->root.'/ana');
    }

    public function test_admin_delete_own_home_requires_confirmation(): void
    {
        // La home propia del admin es una carpeta (y una home de usuario):
        // confirmacion explicita, como cualquier borrado recursivo.
        $component = $this->asAdmin()->call('delete', 'administrador');

        $this->assertSame('/administrador', $component->deleteTarget);
        $this->assertDirectoryExists($this->root.'/administrador');

        $component->call('confirmDelete');
        $this->assertDirectoryDoesNotExist($this->root.'/administrador');
    }

    public function test_delete_missing_shows_error(): void
    {
        $this->asAna()
            ->call('delete', 'no-existe')
            ->assertSee('La entrada no existe');
    }

    /* ---------- descargar ---------- */

    public function test_download_links_in_ui(): void
    {
        $html = $this->asAna()->html();

        $this->assertStringContainsString(route('files.download', ['path' => '/a.txt']), $html);
        $this->assertStringContainsString(route('files.download', ['path' => '/datos']), $html);
    }

    public function test_web_download_file(): void
    {
        $this->actingAs($this->ana)
            ->get('/files/download?path=/a.txt')
            ->assertOk()
            ->assertDownload('a.txt');

        $this->assertSame('a', file_get_contents($this->root.'/ana/a.txt')); // intacto
    }

    public function test_web_download_folder_as_zip(): void
    {
        $this->actingAs($this->ana)
            ->get('/files/download?path=/datos')
            ->assertOk()
            ->assertDownload('datos.zip')
            ->assertHeader('Content-Type', 'application/zip');
    }

    public function test_web_download_other_user_404(): void
    {
        $this->actingAs($this->ana)
            ->get('/files/download?path=/../administrador')
            ->assertNotFound();
    }

    public function test_web_download_missing_404(): void
    {
        $this->actingAs($this->ana)
            ->get('/files/download?path=/no-hay')
            ->assertNotFound();
    }
}

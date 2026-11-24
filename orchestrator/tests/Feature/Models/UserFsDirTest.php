<?php

namespace Tests\Feature\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FS compartido (2.4): directorio de usuario en el primer nivel de la raíz
 * física (`<fs_base>/<fs_slug>`), aseguro idempotente + backfill por comando.
 */
class UserFsDirTest extends TestCase
{
    use RefreshDatabase;

    protected string $fsBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fsBase = sys_get_temp_dir().'/taap-fs-test-'.Str::random(8);
        mkdir($this->fsBase, 0755, true);
        config(['taap.fs.fs_base' => $this->fsBase]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fsBase)) {
            foreach (glob($this->fsBase.'/*') ?: [] as $f) {
                is_dir($f) ? exec('rm -rf '.escapeshellarg($f)) : unlink($f);
            }
            rmdir($this->fsBase);
        }

        parent::tearDown();
    }

    public function test_ensure_fs_dir_creates_home_idempotently(): void
    {
        $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana@taap.test']);

        $dir = $user->ensureFsDir();

        $this->assertSame($this->fsBase.'/ana', $dir);
        $this->assertDirectoryExists($dir);
        // Idempotente: segunda llamada, mismo path, sin error
        $this->assertSame($dir, $user->ensureFsDir());
    }

    public function test_ensure_fs_dir_without_slug_returns_null(): void
    {
        $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana@taap.test']);
        DB::table('users')->where('id', $user->id)->update(['fs_slug' => null]);
        $user->refresh();

        $this->assertNull($user->ensureFsDir());
        $this->assertDirectoryDoesNotExist($this->fsBase.'/ana');
    }

    public function test_command_backfills_dirs_for_every_user_including_admin(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Administrador', 'email' => 'admin@taap.test']);
        $u1 = User::factory()->create(['name' => 'Ana', 'email' => 'a1@taap.test']);
        $u2 = User::factory()->create(['name' => 'Carlos', 'email' => 'a2@taap.test']);
        // Borramos un directorio para ver el backfill en pleno
        $this->artisan('taap:fs:ensure-user-dirs')->assertSuccessful();
        rmdir($this->fsBase.'/'.$u1->fs_slug);

        $this->artisan('taap:fs:ensure-user-dirs')->assertSuccessful()->expectsOutputToContain('directorios creados');

        $this->assertDirectoryExists($this->fsBase.'/'.$admin->fs_slug);
        $this->assertDirectoryExists($this->fsBase.'/'.$u1->fs_slug);
        $this->assertDirectoryExists($this->fsBase.'/'.$u2->fs_slug);
    }
}

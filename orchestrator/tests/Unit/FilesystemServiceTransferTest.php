<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\FilesystemException;
use App\Services\FilesystemService;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * FS compartido (3.3): transferencia — upload (streaming + rename atómico,
 * nombre saneado, tope de tamaño → 413), download (stream con nombre y
 * contenido) y zip (árbol completo con rutas relativas, topes → 413).
 * Cubre las specs "Explorador web de ficheros" y "Descarga de ficheros y
 * carpetas" sobre un FS temporal.
 */
class FilesystemServiceTransferTest extends TestCase
{
    protected string $root;

    protected FilesystemService $fs;

    protected User $ana;

    protected User $carlos;

    protected array $zipTemps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/taap-fs-tr-'.uniqid();
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
        foreach ($this->zipTemps as $zip) {
            @unlink($zip);
        }
        $this->zipTemps = [];

        if (is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }

        parent::tearDown();
    }

    protected function streamOf(string $content): mixed
    {
        $s = fopen('php://memory', 'r+');
        fwrite($s, $content);
        rewind($s);

        return $s;
    }

    protected function track(BinaryFileResponse $response): void
    {
        $this->zipTemps[] = $response->getFile()->getPathname();
    }

    /* ---------- upload ---------- */

    public function test_upload_creates_file(): void
    {
        $this->fs->mkdir($this->ana, 'datos');
        $meta = $this->fs->upload($this->ana, '/datos', 'fichero.txt', $this->streamOf('hola'));

        $this->assertSame('/datos/fichero.txt', $meta['path']);
        $this->assertSame('fichero.txt', $meta['name']);
        $this->assertSame('file', $meta['type']);
        $this->assertSame(4, $meta['size']);
        $this->assertSame('hola', file_get_contents($this->root.'/ana/datos/fichero.txt'));
    }

    public function test_upload_home_without_dir_prefix(): void
    {
        $meta = $this->fs->upload($this->ana, '/', 'leido.md', $this->streamOf('# x'));
        $this->assertSame('/leido.md', $meta['path']);
        $this->assertFileExists($this->root.'/ana/leido.md');
    }

    public function test_upload_overwrite_same_name(): void
    {
        $this->fs->upload($this->ana, '/', 'a.txt', $this->streamOf('uno'));
        $this->fs->upload($this->ana, '/', 'a.txt', $this->streamOf('dos'));
        $this->assertSame('dos', file_get_contents($this->root.'/ana/a.txt'));
    }

    public function test_upload_sanitizes_name(): void
    {
        // Spec: "nombre saneado en casos límite" (design D8).
        $meta = $this->fs->upload($this->ana, '/', '  /etc/fichero.txt  ', $this->streamOf('x'));
        $this->assertSame('fichero.txt', $meta['name']);
        $this->assertFileExists($this->root.'/ana/fichero.txt');

        $meta = $this->fs->upload($this->ana, '/', 'sub/dir/c.txt', $this->streamOf('x'));
        $this->assertSame('c.txt', $meta['name']);
    }

    public function test_upload_invalid_name_422(): void
    {
        try {
            $this->fs->upload($this->ana, '/', '.', $this->streamOf('x'));
            $this->fail('esperado 422');
        } catch (FilesystemException $e) {
            $this->assertSame(422, $e->status);
        }
        try {
            $this->fs->upload($this->ana, '/', str_repeat('a', 256).'.txt', $this->streamOf('x'));
            $this->fail('esperado 422');
        } catch (FilesystemException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function test_upload_missing_dir_404(): void
    {
        $this->expectException(FilesystemException::class);
        $this->fs->upload($this->ana, '/no-existe', 'a.txt', $this->streamOf('x'));
    }

    public function test_upload_over_size_413_and_cleaned(): void
    {
        $this->fs->mkdir($this->ana, 'datos');
        config(['taap.fs.upload_max_size' => 10]);

        try {
            $this->fs->upload($this->ana, '/datos', 'grande.bin', $this->streamOf(str_repeat('a', 100)));
            $this->fail('esperado 413');
        } catch (FilesystemException $e) {
            $this->assertSame(413, $e->status);
        }

        // Ni el fichero final ni temporales .upload-* quedan (spec: tope).
        $this->assertFileDoesNotExist($this->root.'/ana/datos/grande.bin');
        $this->assertCount(0, glob($this->root.'/ana/datos/.upload-*'));
    }

    public function test_upload_under_size_ok(): void
    {
        config(['taap.fs.upload_max_size' => 100]);
        $meta = $this->fs->upload($this->ana, '/', 'peq.bin', $this->streamOf(str_repeat('a', 100)));
        $this->assertSame(100, $meta['size']);
    }

    /* ---------- download ---------- */

    public function test_download_serves_content_and_name(): void
    {
        $this->fs->mkdir($this->ana, 'datos');
        $this->fs->upload($this->ana, '/datos', 'fichero.txt', $this->streamOf('contenido'));

        $response = $this->fs->download($this->ana, '/datos/fichero.txt');
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame('contenido', file_get_contents($response->getFile()->getPathname()));
        $this->assertStringContainsString('fichero.txt', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_download_missing_404(): void
    {
        $this->expectException(FilesystemException::class);
        $this->fs->download($this->ana, '/no-hay.txt');
    }

    public function test_download_dir_404(): void
    {
        $this->fs->mkdir($this->ana, 'datos');
        $this->expectException(FilesystemException::class);
        $this->fs->download($this->ana, '/datos');
    }

    public function test_download_symlink_outside_404(): void
    {
        file_put_contents($this->root.'/carlos/secreto.txt', 's');
        symlink($this->root.'/carlos/secreto.txt', $this->root.'/ana/enlace.txt');

        $this->expectException(FilesystemException::class);
        $this->fs->download($this->ana, '/enlace.txt');
    }

    public function test_download_cannot_cross_users(): void
    {
        // Ana no alcanza el area de Carlos aunque el path sea "suyo".
        $this->expectException(FilesystemException::class);
        $this->fs->download($this->ana, '/../carlos');
    }

    /* ---------- zip ---------- */

    public function test_zip_reproduces_tree(): void
    {
        // Spec: "datos.zip, que al descomprimirlo reproduce el árbol completo
        // de la carpeta con rutas relativas".
        $this->fs->mkdir($this->ana, 'datos/pruebas');
        $this->fs->upload($this->ana, '/datos/pruebas', 'fichero.txt', $this->streamOf('hola'));
        $this->fs->upload($this->ana, '/datos', 'nota.txt', $this->streamOf('n'));
        File::ensureDirectoryExists($this->root.'/ana/datos/vacio');
        // Enlace interno (se incluye como copia) y externo (se omite).
        symlink($this->root.'/ana/datos/nota.txt', $this->root.'/ana/datos/copia.txt');
        symlink($this->root.'/carlos', $this->root.'/ana/datos/ext');

        $response = $this->fs->zip($this->ana, '/datos');
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->track($response);
        $this->assertStringContainsString('datos.zip', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/zip', (string) $response->headers->get('Content-Type'));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->getFile()->getPathname()) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->statIndex($i)['name'] ?? false;
        }
        $zip->close();
        sort($names);
        $this->assertSame(['copia.txt', 'nota.txt', 'pruebas/', 'pruebas/fichero.txt', 'vacio/'], $names);
    }

    public function test_zip_extract_content_roundtrip(): void
    {
        $this->fs->mkdir($this->ana, 'datos/pruebas');
        $this->fs->upload($this->ana, '/datos/pruebas', 'fichero.txt', $this->streamOf('hola'));

        $response = $this->fs->zip($this->ana, '/datos');
        $this->track($response);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->getFile()->getPathname()) === true);
        $this->assertSame('hola', $zip->getFromName('pruebas/fichero.txt'));
        $zip->close();
    }

    public function test_zip_missing_404(): void
    {
        $this->expectException(FilesystemException::class);
        $this->fs->zip($this->ana, '/no-hay');
    }

    public function test_zip_file_404(): void
    {
        $this->fs->upload($this->ana, '/', 'f.txt', $this->streamOf('x'));
        $this->expectException(FilesystemException::class);
        $this->fs->zip($this->ana, '/f.txt');
    }

    public function test_zip_over_files_413(): void
    {
        $this->fs->upload($this->ana, '/', 'a.txt', $this->streamOf('a'));
        $this->fs->upload($this->ana, '/', 'b.txt', $this->streamOf('b'));
        config(['taap.fs.zip_max_files' => 1]);

        try {
            $this->fs->zip($this->ana, '/');
            $this->fail('esperado 413');
        } catch (FilesystemException $e) {
            $this->assertSame(413, $e->status);
        }
    }

    public function test_zip_over_size_413(): void
    {
        $this->fs->upload($this->ana, '/', 'grande.bin', $this->streamOf(str_repeat('a', 100)));
        config(['taap.fs.zip_max_size' => 10]);

        try {
            $this->fs->zip($this->ana, '/');
            $this->fail('esperado 413');
        } catch (FilesystemException $e) {
            $this->assertSame(413, $e->status);
        }
    }

    public function test_zip_under_limits_ok(): void
    {
        $this->fs->upload($this->ana, '/', 'a.txt', $this->streamOf('a'));
        config(['taap.fs.zip_max_files' => 5, 'taap.fs.zip_max_size' => 1000]);
        $response = $this->fs->zip($this->ana, '/');
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->track($response);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

/**
 * Contrato API v1 del FS compartido (shared-filesystem 4.2): las respuestas
 * (listado y metadatos de descarga/zip) se comparan EXACTAMENTE contra
 * fixtures JSON. Son la base de paridad de la app iOS: un cambio de forma en
 * la API rompe estos tests y obliga a revisar el contrato.
 *
 * Los mtime son deterministas (arbol tocado con timestamps fijos; tz=UTC).
 */
class FilesContractTest extends TestCase
{
    use RefreshDatabase;

    protected string $root;

    protected User $admin;

    protected User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/taap-fs-contract-'.uniqid();
        mkdir($this->root, 0755, true);
        config(['taap.fs.fs_base' => $this->root]);

        $this->admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@taap.test', 'fs_slug' => 'administrador']);
        $this->ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@taap.test', 'fs_slug' => 'ana']);

        // Arbol conocido de Ana.
        mkdir($this->root.'/ana/datos/pruebas', 0755, true);
        file_put_contents($this->root.'/ana/datos/afile.txt', 'hola');
        file_put_contents($this->root.'/ana/datos/bfile.txt', 'world!');
        file_put_contents($this->root.'/ana/leido.md', '# x');

        // mtime fijos (el orden importa: parents tras hijos).
        touch($this->root.'/ana/datos/pruebas', 1700000200);
        touch($this->root.'/ana/datos/afile.txt', 1700000300);
        touch($this->root.'/ana/datos/bfile.txt', 1700000400);
        touch($this->root.'/ana/datos', 1700000100);
        touch($this->root.'/ana/leido.md', 1700000500);
        touch($this->root.'/ana', 1700000600);
        touch($this->root.'/administrador', 1700000700);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }

        parent::tearDown();
    }

    /* ---------- helpers de contrato ---------- */

    protected function fixture(string $name): array
    {
        $path = __DIR__.'/../../Fixtures/api/files/'.$name;
        $data = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($data, "fixture {$name} invalido");

        return $data;
    }

    protected function assertContract(string $fixture, array $actual, array $placeholders = []): void
    {
        $expected = $this->fixture($fixture);
        foreach ($placeholders as $token => $value) {
            $expected = $this->applyPlaceholder($expected, $token, $value);
        }

        $this->assertEqualsCanonicalizing($expected, $actual, "contrato de {$fixture}");
    }

    /** @param array|mixed $node */
    protected function applyPlaceholder($node, string $token, mixed $value): mixed
    {
        if (! is_array($node)) {
            return $node === $token ? $value : $node;
        }

        foreach ($node as $k => $v) {
            $node[$k] = $this->applyPlaceholder($v, $token, $value);
        }

        return $node;
    }

    /* ---------- listado ---------- */

    public function test_list_dir_contract(): void
    {
        $this->actingAs($this->ana, 'sanctum')
            ->getJson('/api/v1/files?path=/datos')
            ->assertOk();

        $this->assertContract('list-dir.json', $this->actingAs($this->ana, 'sanctum')->getJson('/api/v1/files?path=/datos')->json());
    }

    public function test_list_user_root_contract(): void
    {
        $this->assertContract('list-user-root.json', $this->actingAs($this->ana, 'sanctum')->getJson('/api/v1/files?path=/')->json());
    }

    public function test_list_admin_root_contract(): void
    {
        // Spec: admin ve la raiz fisica, con badge de propietario en las
        // homes de usuario (3.4) y sin badge en la suya.
        $this->assertContract('list-admin-root.json', $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/files?path=/')->json());
    }

    /* ---------- mutaciones ---------- */

    public function test_create_folder_contract(): void
    {
        $response = $this->actingAs($this->ana, 'sanctum')
            ->postJson('/api/v1/files/folders', ['path' => '/datos/pruebas2'])
            ->assertCreated();

        $this->assertContract('folder-created.json', $response->json());
    }

    public function test_rename_contract(): void
    {
        $response = $this->actingAs($this->ana, 'sanctum')
            ->postJson('/api/v1/files/rename', ['path' => '/datos/afile.txt', 'newName' => 'renamed.txt'])
            ->assertOk();

        $this->assertContract('renamed.json', $response->json());
    }

    public function test_delete_contract(): void
    {
        $response = $this->actingAs($this->ana, 'sanctum')
            ->deleteJson('/api/v1/files?path=/datos/pruebas')
            ->assertOk();

        $this->assertContract('deleted.json', $response->json());
    }

    public function test_upload_contract(): void
    {
        $actual = $this->actingAs($this->ana, 'sanctum')->post('/api/v1/files/uploads', [
            'path' => '/datos',
            'file' => $this->uploadOf('subido.txt', 'hola'),
        ])->assertCreated()->json();

        // mtime real (no fijable); el resto del contrato, exacto.
        $this->assertContract('uploaded.json', $actual, ['__ISO8601__' => $actual['mtime']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $actual['mtime']);
    }

    /* ---------- descarga ---------- */

    public function test_download_file_contract(): void
    {
        $response = $this->actingAs($this->ana, 'sanctum')
            ->get('/api/v1/files/download?path=/datos/afile.txt')
            ->assertOk();

        // BinaryFileResponse: el contenido vive en el fichero, no en getContent().
        $this->assertContract('download-file.json', [
            'content-disposition' => (string) $response->headers->get('Content-Disposition'),
            'content-type' => (string) $response->headers->get('Content-Type'),
            'body' => (string) file_get_contents((string) $response->getFile()->getPathname()),
        ]);
        @unlink((string) $response->getFile()->getPathname());
    }

    public function test_download_folder_zip_contract(): void
    {
        $response = $this->actingAs($this->ana, 'sanctum')
            ->get('/api/v1/files/download?path=/datos')
            ->assertOk();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open((string) $response->getFile()->getPathname()) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->statIndex($i)['name'] ?? false;
        }
        $zip->close();
        @unlink($response->getFile()->getPathname());

        $this->assertContract('download-folder.json', [
            'content-disposition' => (string) $response->headers->get('Content-Disposition'),
            'content-type' => (string) $response->headers->get('Content-Type'),
            'zip_entries' => $names,
        ]);
    }

    /* ---------- errores ---------- */

    public function test_error_404_contract(): void
    {
        // No existe (pero esta dentro de la base): mensaje de "no es un directorio".
        $this->actingAs($this->ana, 'sanctum')
            ->getJson('/api/v1/files?path=/no-existe')
            ->assertNotFound()
            ->assertExactJson($this->fixture('error-404.json'));

        // Existe pero esta fuera de la base del sujeto (escape por ..): 404 de contencion.
        $this->actingAs($this->ana, 'sanctum')
            ->getJson('/api/v1/files?path=/../administrador')
            ->assertNotFound()
            ->assertExactJson($this->fixture('error-404-escape.json'));
    }

    public function test_error_409_contract(): void
    {
        $this->actingAs($this->ana, 'sanctum')
            ->postJson('/api/v1/files/folders', ['path' => '/datos'])
            ->assertConflict()
            ->assertExactJson($this->fixture('error-409.json'));
    }

    public function test_error_413_contract(): void
    {
        config(['taap.fs.upload_max_size' => 2]);

        $this->actingAs($this->ana, 'sanctum')
            ->post('/api/v1/files/uploads', [
                'path' => '/datos',
                'file' => $this->uploadOf('grande.bin', str_repeat('a', 10)),
            ])
            ->assertStatus(413)
            ->assertExactJson($this->fixture('error-413.json'));
    }

    public function test_error_422_contract(): void
    {
        $this->actingAs($this->ana, 'sanctum')
            ->postJson('/api/v1/files/folders', ['path' => "datos/x\0y"])
            ->assertStatus(422)
            ->assertExactJson($this->fixture('error-422.json'));
    }

    /* ---------- helpers ---------- */

    protected function uploadOf(string $name, string $content): UploadedFile
    {
        $p = tempnam(sys_get_temp_dir(), 'taap-contract-up-');
        file_put_contents($p, $content);

        return new UploadedFile($p, $name, 'text/plain', null, true);
    }
}

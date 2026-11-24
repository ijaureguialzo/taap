<?php

namespace Tests\Feature\Files;

use App\Livewire\Files\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Explorador web (shared-filesystem 5.1): la pagina /files renderiza para
 * todos los autenticados; el usuario ve su home como "/"; el admin ve el
 * arbol completo con la ubicacion fisica en solo lectura.
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected string $root;

    protected User $admin;

    protected User $ana;

    protected User $carlos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/taap-fs-web-'.uniqid();
        mkdir($this->root, 0755, true);
        config(['taap.fs.fs_base' => $this->root, 'taap.fs.fs_host_base' => $this->root]);

        $this->admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@taap.test', 'fs_slug' => 'administrador']);
        $this->ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@taap.test', 'fs_slug' => 'ana']);
        $this->carlos = User::factory()->create(['name' => 'Carlos', 'email' => 'carlos@taap.test', 'fs_slug' => 'carlos']);

        // Contenido conocido.
        mkdir($this->root.'/ana/datos', 0755, true);
        file_put_contents($this->root.'/ana/datos/f.txt', 'hola');
        file_put_contents($this->root.'/ana/leido.md', '# x');
        file_put_contents($this->root.'/carlos/suyo.txt', 'y');

        app()->setLocale('es');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf '.escapeshellarg($this->root));
        }

        parent::tearDown();
    }

    /* ---------- render y navegación ---------- */

    public function test_page_renders_and_navbar_entry(): void
    {
        $this->actingAs($this->ana)
            ->get('/files')
            ->assertOk()
            ->assertSee('Archivos'); // titulo + entrada de la navbar
    }

    public function test_user_sees_own_home_as_root(): void
    {
        $this->actingAs($this->ana);

        $component = Livewire::test(Index::class);
        $component->assertSee('leido.md');
        $component->assertSee('datos');
        $this->assertSame('/', $component->listingPath);

        // No alcanza el area de Carlos.
        $html = $component->html();
        $this->assertStringNotContainsString('suyo.txt', $html);
        $this->assertStringNotContainsString('Carlos', $html);
    }

    public function test_user_cannot_navigate_to_other_home(): void
    {
        $this->actingAs($this->ana);

        $component = Livewire::test(Index::class);
        $component->call('open', '/../carlos');
        $component->assertSee('Ruta fuera del area permitida o inexistente');
    }

    public function test_navigation_into_subdir_and_breadcrumb(): void
    {
        $this->actingAs($this->ana);

        $component = Livewire::test(Index::class)
            ->call('open', '/datos');

        $component->assertSee('f.txt');
        $component->assertSee('Inicio'); // miga de raiz
        $this->assertSame('/datos', $component->listingPath);

        // Volver a la raiz por la miga.
        $component->call('open', '/');
        $this->assertSame('/', $component->listingPath);
        $component->assertSee('leido.md');
    }

    public function test_missing_dir_shows_error(): void
    {
        $this->actingAs($this->ana);

        $component = Livewire::test(Index::class)->call('open', '/no-hay');
        $component->assertSee('La ruta no existe o no es un directorio');
    }

    public function test_empty_home_shows_placeholder(): void
    {
        $fresh = User::factory()->create(['name' => 'Vacia', 'email' => 'vacia@taap.test', 'fs_slug' => 'vacia']);
        $this->actingAs($fresh);

        Livewire::test(Index::class)->assertSee('Este directorio está vacío.');
    }

    /* ---------- vista de admin ---------- */

    public function test_admin_sees_full_tree_with_physical_root(): void
    {
        $this->actingAs($this->admin);

        $component = Livewire::test(Index::class);

        // Arbol completo desde la raiz fisica.
        $html = $component->html();
        $this->assertStringContainsString('ana', $html);
        $this->assertStringContainsString('carlos', $html);
        $this->assertStringContainsString('administrador', $html);

        // Ubicacion fisica en solo lectura (TAAP_FS_ROOT).
        $component->assertSee('Ubicación física:');
        $component->assertSee($this->root);

        // Badge de propietario (3.4) en las homes de usuario; no en la suya.
        $byName = [];
        foreach ($component->entries as $e) {
            $byName[$e['name']] = $e['owner'] ?? null;
        }
        $this->assertSame('Ana', $byName['ana']);
        $this->assertSame('Carlos', $byName['carlos']);
        $this->assertNull($byName['administrador']);

        // El admin puede entrar en la home de un usuario.
        $component->call('open', '/ana');
        $component->assertSee('leido.md');
        $this->assertSame('/ana', $component->listingPath);
    }

    public function test_user_does_not_see_physical_root(): void
    {
        $this->actingAs($this->ana);

        Livewire::test(Index::class)
            ->assertDontSee('Ubicación física:')
            ->assertDontSee('carlos');
    }
}

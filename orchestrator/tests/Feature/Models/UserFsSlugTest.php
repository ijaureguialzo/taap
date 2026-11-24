<?php

namespace Tests\Feature\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FS compartido (2.1): `users.fs_slug` — slug estable por usuario. Se
 * asigna una vez en la creación (desambiguado `ana`, `ana-2`, `ana-3`),
 * el backfill de la migración cubre los existentes y permanece estable
 * ante cambios del nombre de visualización.
 */
class UserFsSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_assigns_disambiguated_unique_slugs(): void
    {
        $u1 = User::factory()->create(['name' => 'Ana', 'email' => 'ana1@taap.test']);
        $u2 = User::factory()->create(['name' => 'Ana', 'email' => 'ana2@taap.test']);
        $u3 = User::factory()->create(['name' => 'Ana', 'email' => 'ana3@taap.test']);

        $this->assertSame('ana', $u1->fs_slug);
        $this->assertSame('ana-2', $u2->fs_slug);
        $this->assertSame('ana-3', $u3->fs_slug);

        $this->assertCount(3, User::pluck('fs_slug')->unique());
    }

    public function test_backfill_assigns_unique_slugs_to_users_without_one(): void
    {
        $u1 = User::factory()->create(['name' => 'Ana', 'email' => 'a1@taap.test']);
        $u2 = User::factory()->create(['name' => 'ana', 'email' => 'a2@taap.test']);
        $u3 = User::factory()->create(['name' => 'Carlos', 'email' => 'a3@taap.test']);

        // Simular usuarios existentes antes de la funcionalidad: sin slug.
        User::query()->update(['fs_slug' => null]);

        $updated = User::backfillFsSlugs();

        $this->assertSame(3, $updated);
        $u1->refresh();
        $u2->refresh();
        $u3->refresh();
        // El slug deriva del nombre ACTUAL, desambiguado en orden de id:
        // el mismo resultado que si se hubieran creado con la feature.
        $this->assertSame('ana', $u1->fs_slug);
        $this->assertSame('ana-2', $u2->fs_slug);
        $this->assertSame('carlos', $u3->fs_slug);

        $this->assertCount(3, User::pluck('fs_slug')->unique());
        $this->assertNull(User::whereNull('fs_slug')->first());
    }

    public function test_slug_is_stable_when_display_name_changes(): void
    {
        $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana@taap.test']);
        $slug = $user->fs_slug;

        $user->update(['name' => 'Ana María García López']);

        $this->assertSame($slug, $user->fresh()->fs_slug);
    }

    public function test_slugify_name_falls_back_to_email(): void
    {
        $this->assertSame('ana-maria', User::slugifyName('Ana María'));
        $this->assertSame('janedoe', User::slugifyName('!!!', 'janedoe@taap.test'));
        $this->assertSame('user', User::slugifyName('   ', null));
    }

    public function test_home_path_is_canonical_slug(): void
    {
        $user = User::factory()->create(['name' => 'Ana García', 'email' => 'a@taap.test']);

        $this->assertSame('/ana-garcia', $user->fsHomePath());
    }

    public function test_migration_up_and_down(): void
    {
        $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana@taap.test']);
        $this->assertTrue(Schema::hasColumn('users', 'fs_slug'));
        $this->assertNotNull($user->fs_slug);

        $migration = include database_path('migrations/2026_11_24_100000_add_fs_slug_to_users_table.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('users', 'fs_slug'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('users', 'fs_slug'));
        $this->assertNotNull($user->fresh()->fs_slug);
    }
}

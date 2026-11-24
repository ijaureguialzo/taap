<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * FS compartido (2.4, design D4): asegura idempotente el directorio de
 * cada usuario en el primer nivel de la raíz física (`<fs_base>/<fs_slug>`):
 * backfill del despliegue (tras la migración de slug, cambios de raíz) y
 * red de seguridad periódica. El "primer uso" normal lo cubre el
 * FilesystemService al resolver el home; este comando cubre el resto.
 */
class TaapFsEnsureUserDirs extends Command
{
    protected $signature = 'taap:fs:ensure-user-dirs';

    protected $description = 'Segura el directorio de cada usuario en la raíz del FS compartido (backfill)';

    public function handle(): int
    {
        $base = rtrim((string) config('taap.fs.fs_base', '/data/fs'), '/');
        $total = 0;
        $created = 0;
        $withoutSlug = 0;

        User::query()->orderBy('id')->each(function (User $user) use ($base, &$total, &$created, &$withoutSlug) {
            $total++;
            if (! filled($user->fs_slug)) {
                $withoutSlug++;
                Log::warning("fs: usuario {$user->id} sin fs_slug: no hay directorio que asegurar");

                return;
            }

            $dir = $base.'/'.$user->fs_slug;
            $existed = is_dir($dir);
            $user->ensureFsDir();
            if (! $existed) {
                $created++;
            }
        });

        $this->info("FS {$base}: {$total} usuarios, {$created} directorios creados, {$withoutSlug} sin slug");

        return self::SUCCESS;
    }
}

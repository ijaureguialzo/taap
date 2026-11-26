<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * taap:backup:restore — restaura desde un bundle fechado.
 * Verificación de integridad (sha256) ANTES de cualquier escritura;
 * requiere --yes (borra y sobrescribe datos existentes).
 */
class TaapBackupRestoreCommand extends Command
{
    protected $signature = 'taap:backup:restore
        {bundle : Directorio del bundle (con manifest.json)}
        {--yes : Confirma que el bundle destruye los datos existentes}';

    protected $description = 'Restaura un bundle fechado (DB + FS root + datos de agentes) verificando hashes';

    public function handle(): int
    {
        $bundleDir = rtrim((string) $this->argument('bundle'), '/');

        if (! is_dir($bundleDir) || ! is_file($bundleDir.'/manifest.json')) {
            $this->error("Bundle no válido: {$bundleDir} (falta manifest.json)");

            return self::FAILURE;
        }

        $manifest = json_decode(file_get_contents($bundleDir.'/manifest.json'), true);
        if (! is_array($manifest) || empty($manifest['parts'])) {
            $this->error('manifest.json inválido');

            return self::FAILURE;
        }

        // 1) Verificar integridad ANTES de escribir.
        foreach ($manifest['parts'] as $name => $part) {
            $file = $bundleDir.'/'.$part['file'];

            if (! is_file($file)) {
                $this->error("Falta componente en el bundle: {$part['file']}");

                return self::FAILURE;
            }

            $size = filesize($file);
            if ($size !== (int) $part['size_bytes']) {
                $this->error("Hash/tamaño inválido en {$part['file']} (tamaño {$size} != {$part['size_bytes']})");

                return self::FAILURE;
            }

            $sha = hash_file('sha256', $file);
            if (! hash_equals($part['sha256'], $sha)) {
                $this->error("Hash sha256 inválido en {$part['file']} — bundle corrupto");

                return self::FAILURE;
            }

            $this->info("✓ verificado {$name} ({$part['file']})");
        }

        if (! $this->option('yes')) {
            $this->error('Restauración DESTRUCTIVA: hay que confirmarla con --yes');

            return self::FAILURE;
        }

        $this->info('Restaurando DB…');
        $this->restoreDb($bundleDir.'/'.$manifest['parts']['db']['file']);

        $this->info('Restaurando FS root…');
        $this->restoreFilesystem($bundleDir.'/'.$manifest['parts']['fs_root']['file'], $this->fsRoot());

        $this->info('Restaurando datos de agentes…');
        $this->restoreFilesystem($bundleDir.'/'.$manifest['parts']['agent_data']['file'], $this->agentDataDir());

        $this->info("Restauración completa desde {$bundleDir}");

        return self::SUCCESS;
    }

    protected function fsRoot(): string
    {
        return rtrim((string) Config::string('taap.fs.fs_host_base', '/var/lib/taap/fs'), '/');
    }

    protected function agentDataDir(): string
    {
        return rtrim((string) Config::string('taap.agents.data_host_base', '/var/lib/taap/agents'), '/');
    }

    protected function restoreDb(string $dbArchive): void
    {
        $connection = Config::string('database.default', 'sqlite');

        if ($connection === 'sqlite') {
            $db = Config::string('database.connections.sqlite.database', 'database/database.sqlite');
            $db = str_starts_with($db, '/') ? $db : base_path($db);

            // gunzip + copia sobre el archivo de la DB.
            $tmp = $db.'.tmp';
            shell_exec('gunzip -c '.escapeshellarg($dbArchive).' > '.escapeshellarg($tmp));
            if (! is_file($tmp)) {
                throw new RuntimeException('No se pudo descomprimir el dump de sqlite');
            }
            rename($tmp, $db);

            return;
        }

        // Postgres: drop + create + pg_restore vía docker compose.
        $dbName = Config::string('database.connections.pgsql.database', 'taap');
        $dbUser = Config::string('database.connections.pgsql.username', 'taap');

        $repoRoot = dirname(base_path());
        $compose = $repoRoot.'/deploy/compose.yaml';

        $cmd = sprintf(
            'docker compose -f %s exec -T postgres psql -U %s -d postgres -c %s -c %s',
            escapeshellarg($compose),
            escapeshellarg($dbUser),
            escapeshellarg('DROP DATABASE IF EXISTS "'.$dbName.'";'),
            escapeshellarg('CREATE DATABASE "'.$dbName.'";')
        );
        exec($cmd, $out, $rc);
        if ($rc !== 0) {
            throw new RuntimeException('No se pudo (re)crear la base de datos (rc='.$rc.')');
        }

        $cmd = sprintf(
            'docker compose -f %s exec -T postgres pg_restore -U %s -d %s < %s',
            escapeshellarg($compose),
            escapeshellarg($dbUser),
            escapeshellarg($dbName),
            escapeshellarg($dbArchive)
        );
        exec($cmd, $out, $rc);
        if ($rc !== 0) {
            throw new RuntimeException('pg_restore falló (rc='.$rc.')');
        }
    }

    /** Borra el contenido del destino y restaura el tar desde el bundle. */
    protected function restoreFilesystem(string $archive, string $target): void
    {
        $target = rtrim($target, '/');

        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        // Wipe del contenido (conservando el directorio padre).
        foreach (glob($target.'/*') ?: [] as $item) {
            $this->removePath($item);
        }

        $cmd = sprintf('tar -xzf %s -C %s', escapeshellarg($archive), escapeshellarg($target));
        exec($cmd, $out, $rc);

        if ($rc !== 0) {
            throw new RuntimeException('No se pudo extraer '.basename($archive).' en '.$target.' (rc='.$rc.')');
        }
    }

    protected function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $item) {
            if ($item->isFile() || $item->isLink()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }

        rmdir($path);
    }
}

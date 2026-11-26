<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * taap:backup — bundle fechado con los datos de TAAP:
 *   db.sql.gz      dump de Postgres (o copia de sqlite)
 *   fs-root.tar.gz copia de la raíz fs (TAAP_FS_HOST base)
 *   agent-data.tar.gz copia de los datos persistentes de los agentes
 *   manifest.json  sha256 y tamaño por parte
 * Excluye secretos: .env nunca entra en el bundle (documentado en
 * docs/deployment.md).
 */
class TaapBackupCommand extends Command
{
    protected $signature = 'taap:backup
        {--out= : Directorio del bundle (por defecto: backups/<timestamp>)}
        {--db-dump= : Archivo de dump existente a usar como parte de la BD (testing)}';

    protected $description = 'Genera un bundle fechado con DB + FS root + datos de agentes (verifica hashes en el manifest)';

    public function handle(): int
    {
        $outDir = $this->option('out') ?: $this->defaultBundleDir();
        $outDir = rtrim($outDir, '/');

        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $this->info("Bundle: {$outDir}");

        $dbFile = $outDir.'/db.sql.gz';
        $this->writeDbDump($dbFile);
        $this->info('  ✓ db.sql.gz');

        $fsFile = $outDir.'/fs-root.tar.gz';
        $this->writeTar($this->fsRoot(), $fsFile);
        $this->info('  ✓ fs-root.tar.gz');

        $agentsFile = $outDir.'/agent-data.tar.gz';
        $this->writeTar($this->agentDataDir(), $agentsFile);
        $this->info('  ✓ agent-data.tar.gz');

        $manifest = [
            'created_at' => now()->toIso8601String(),
            'taap_version' => $this->taapVersion(),
            'parts' => [],
        ];

        foreach (['db' => $dbFile, 'fs_root' => $fsFile, 'agent_data' => $agentsFile] as $name => $file) {
            $stat = stat($file);
            $manifest['parts'][$name] = [
                'file' => basename($file),
                'size_bytes' => $stat['size'],
                'sha256' => hash_file('sha256', $file),
            ];
        }

        file_put_contents($outDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info("Bundle completo: {$outDir}");

        return self::SUCCESS;
    }

    protected function defaultBundleDir(): string
    {
        $dir = rtrim(Config::string('taap.backup.dir', 'backups'), '/');

        if ($dir === '') {
            $dir = 'backups';
        }

        return $dir.'/'.date('Y-m-d-His');
    }

    protected function fsRoot(): string
    {
        return rtrim((string) Config::string('taap.fs.fs_host_base', '/var/lib/taap/fs'), '/');
    }

    protected function agentDataDir(): string
    {
        return rtrim((string) Config::string('taap.agents.data_host_base', '/var/lib/taap/agents'), '/');
    }

    protected function taapVersion(): string
    {
        return '1.2';
    }

    /** Dump de la BD: pg_dump (postgres) o copia del archivo (sqlite). */
    protected function writeDbDump(string $dest): void
    {
        $provided = $this->option('db-dump');
        if ($provided) {
            if (! is_file($provided)) {
                throw new RuntimeException("--db-dump: archivo no encontrado: {$provided}");
            }

            // Siempre comprime para mantener la forma del bundle.
            shell_exec('gzip -c '.escapeshellarg($provided).' > '.escapeshellarg($dest));

            return;
        }

        $connection = Config::string('database.default', 'sqlite');

        if ($connection === 'sqlite') {
            $db = Config::string('database.connections.sqlite.database', 'database/database.sqlite');
            $db = str_starts_with($db, '/') ? $db : base_path($db);

            if (is_file($db)) {
                shell_exec('gzip -c '.escapeshellarg($db).' > '.escapeshellarg($dest));

                return;
            }

            throw new RuntimeException('DB sqlite no encontrada: '.$db);
        }

        // Postgres vía docker compose (exec en el contenedor postgres).
        $dbName = Config::string('database.connections.pgsql.database', 'taap');
        $dbUser = Config::string('database.connections.pgsql.username', 'taap');

        $repoRoot = dirname(base_path()); // el repo == dirname(orchestrator)
        $tmp = $dest.'.tmp';
        $cmd = sprintf(
            'docker compose -f %s exec -T postgres pg_dump -U %s -d %s -Fc > %s',
            escapeshellarg($repoRoot.'/deploy/compose.yaml'),
            escapeshellarg($dbUser),
            escapeshellarg($dbName),
            escapeshellarg($tmp)
        );
        exec($cmd, $out, $rc);

        if ($rc !== 0 || ! is_file($tmp)) {
            @unlink($tmp);
            throw new RuntimeException('pg_dump falló (rc='.$rc.'). Usa --db-dump=<archivo> para validar el bundle.');
        }

        rename($tmp, $dest);
    }

    /** tar -czf $dest -C <source> . (archivo vacío -> tar vacío) */
    protected function writeTar(string $source, string $dest): void
    {
        if (! is_dir($source)) {
            // Carpeta inexistente: bundle vacío de ese componente.
            $source = sys_get_temp_dir().'/taap-backup-empty-'.getmypid();
            if (! is_dir($source)) {
                mkdir($source, 0755, true);
            }
        }

        $cmd = sprintf(
            'tar -czf %s -C %s .',
            escapeshellarg($dest),
            escapeshellarg($source)
        );

        exec($cmd, $out, $rc);

        if ($rc !== 0 || ! is_file($dest)) {
            throw new RuntimeException('No se pudo crear '.basename($dest).' (rc='.$rc.', ruta='.$source.')');
        }
    }
}

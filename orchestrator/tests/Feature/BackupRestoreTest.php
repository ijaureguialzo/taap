<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * backup-restore: bundle fechado (DB + FS root + datos de agentes) con
 * verificación de hashes ANTES de escribir; restore destructivo con --yes.
 */
class BackupRestoreTest extends TestCase
{
    protected string $tempRoot;

    protected string $bundleDir;

    protected string $fsRoot;

    protected string $agentData;

    protected string $dbFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().'/taap-backup-test-'.getmypid();
        $this->bundleDir = $this->tempRoot.'/bundle';
        $this->fsRoot = $this->tempRoot.'/fs';
        $this->agentData = $this->tempRoot.'/agents';
        $this->dbFile = $this->tempRoot.'/test.sqlite';

        if (is_dir($this->tempRoot)) {
            $this->removePath($this->tempRoot);
        }

        mkdir($this->fsRoot.'/user-a', 0755, true);
        mkdir($this->agentData.'/agent-1', 0755, true);
        file_put_contents($this->fsRoot.'/user-a/data.txt', 'hola-fs');
        file_put_contents($this->agentData.'/agent-1/workspace.txt', 'hola-agents');

        file_put_contents($this->dbFile, 'fake-db-content');

        config([
            'taap.fs.fs_host_base' => $this->fsRoot,
            'taap.agents.data_host_base' => $this->agentData,
            'taap.backup.dir' => $this->tempRoot,
            'database.connections.sqlite.database' => $this->dbFile,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempRoot)) {
            $this->removePath($this->tempRoot);
        }

        parent::tearDown();
    }

    public function test_backup_creates_bundle_with_manifest_hashes(): void
    {
        $this->artisan('taap:backup', ['--out' => $this->bundleDir, '--db-dump' => $this->dbFile])
            ->expectsOutputToContain('Bundle completo')
            ->assertSuccessful();

        $this->assertFileExists($this->bundleDir.'/manifest.json');
        $this->assertFileExists($this->bundleDir.'/db.sql.gz');
        $this->assertFileExists($this->bundleDir.'/fs-root.tar.gz');
        $this->assertFileExists($this->bundleDir.'/agent-data.tar.gz');

        $manifest = json_decode(file_get_contents($this->bundleDir.'/manifest.json'), true);

        $this->assertArrayHasKey('db', $manifest['parts']);
        $this->assertArrayHasKey('fs_root', $manifest['parts']);
        $this->assertArrayHasKey('agent_data', $manifest['parts']);

        foreach ($manifest['parts'] as $part) {
            $file = $this->bundleDir.'/'.$part['file'];

            $this->assertSame($part['size_bytes'], filesize($file));
            $this->assertSame($part['sha256'], hash_file('sha256', $file));
        }
    }

    public function test_restore_requires_yes_confirmation(): void
    {
        $this->artisan('taap:backup', ['--out' => $this->bundleDir, '--db-dump' => $this->dbFile])->assertSuccessful();

        $this->artisan('taap:backup:restore', ['bundle' => $this->bundleDir])
            ->assertFailed();

        // El fs debe seguir intacto: sin --yes no se escribe nada.
        $this->assertFileExists($this->fsRoot.'/user-a/data.txt');
    }

    public function test_restore_aborts_on_corrupt_hash_before_writing(): void
    {
        $this->artisan('taap:backup', ['--out' => $this->bundleDir, '--db-dump' => $this->dbFile])->assertSuccessful();

        // Corromper una parte del bundle (el hash no coincide).
        file_put_contents($this->bundleDir.'/fs-root.tar.gz', 'corrupted');

        $this->artisan('taap:backup:restore', ['bundle' => $this->bundleDir, '--yes' => true])
            ->assertFailed();

        $this->assertFileExists($this->fsRoot.'/user-a/data.txt');
    }

    public function test_restore_roundtrip_restores_fs_and_agent_data(): void
    {
        $this->artisan('taap:backup', ['--out' => $this->bundleDir, '--db-dump' => $this->dbFile])->assertSuccessful();

        // Modificar el estado (borrar archivos + añadir uno nuevo).
        unlink($this->fsRoot.'/user-a/data.txt');
        unlink($this->agentData.'/agent-1/workspace.txt');
        file_put_contents($this->fsRoot.'/user-a/new.txt', 'nuevo');

        $this->artisan('taap:backup:restore', ['bundle' => $this->bundleDir, '--yes' => true])
            ->assertSuccessful();

        // El fs queda exactamente como en el bundle.
        $this->assertFileExists($this->fsRoot.'/user-a/data.txt');
        $this->assertFileDoesNotExist($this->fsRoot.'/user-a/new.txt');
        $this->assertSame('hola-fs', file_get_contents($this->fsRoot.'/user-a/data.txt'));
        $this->assertFileExists($this->agentData.'/agent-1/workspace.txt');
        $this->assertSame('hola-agents', file_get_contents($this->agentData.'/agent-1/workspace.txt'));
    }

    private function removePath(string $path): void
    {
        if (! is_dir($path)) {
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

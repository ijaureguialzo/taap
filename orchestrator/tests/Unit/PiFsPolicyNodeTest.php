<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\RequiresDocker;
use Tests\TestCase;

/**
 * La politica de auto-aprobacion del FS del runtime Pi (shared-filesystem
 * 6.4) testada contra el FICHERO TS real: node 24 (type stripping) dentro
 * de la imagen taap/pi:local importa taap-fs-policy.ts y evalua una tabla
 * de casos. Sin montados la politica es "todo al dialog" (fsPolicy null).
 *
 * Se SALTA sin Docker o sin la imagen (CI con servicio Docker la ejecuta).
 */
class PiFsPolicyNodeTest extends TestCase
{
    use RequiresDocker;

    protected function cases(): array
    {
        $rw = ['/fs/ana/proyectos'];
        $ro = ['/fs/ana/informes'];

        return [
            ['echo hola > /fs/ana/proyectos/notas.txt', $rw, $ro, 'auto'], // escritura en rw
            ['echo hola > /fs/ana/informes/notas.txt', $rw, $ro, null], // escritura en ro -> dialog
            ['cat /fs/ana/informes/seed.txt', $rw, $ro, 'auto'], // lectura en ro
            ['cat /fs/ana/proyectos/a.txt /fs/ana/informes/b.txt', $rw, $ro, 'auto'], // lectura mixta
            ['ls /fs/ana/proyectos', $rw, $ro, 'auto'],
            ['cp /fs/ana/proyectos/a /tmp/b', $rw, $ro, null], // path fuera -> dialog
            ['ls /workspace /etc/passwd', $rw, $ro, null], // sin paths de /fs -> dialog
            ['echo hola', $rw, $ro, null], // sin paths -> dialog (flow actual)
            ['rm -rf /fs/ana/proyectos/x', $rw, $ro, 'auto'], // verbo de escritura en rw
            ['rm -rf /fs/ana/informes/x', $rw, $ro, null], // verbo de escritura en ro
            ['mkdir /fs/ana/proyectos/sub', $rw, $ro, 'auto'],
            ["sed -i 's/a/b/' /fs/ana/proyectos/f.txt", $rw, $ro, 'auto'],
            ["sed -i 's/a/b/' /fs/ana/informes/f.txt", $rw, $ro, null],
            ['grep -r TODO /fs/ana/informes/docs', $rw, $ro, 'auto'], // lectura en ro (glob)
            ['echo hola > /fs/ana/proyectos/a 2>&1', $rw, $ro, 'auto'], // stdout en rw
            ['ls /fs/ana/proyectos 2>&1', $rw, $ro, 'auto'], // 2>&1 es dup de fd, no escritura a fichero
            ['ls /fs/ana/proyectos', [], [], null], // sin montados: nada es "dentro"
            ['cat "/fs/ana/informes/sub dir/x.txt"', $rw, $ro, 'auto'], // path con espacios (comillas)
            ['rm -rf "/fs/ana/mis docs/tmp"', ['/fs/ana/mis docs'], $ro, 'auto'], // rw con espacios
        ];
    }

    public function test_fs_policy_real_ts_matches_expected(): void
    {
        $this->skipUnlessDocker(['taap/pi:local']);

        $tmp = sys_get_temp_dir().'/taap-fspolicy-'.uniqid('', false);
        File::ensureDirectoryExists($tmp);
        try {
            File::copy(
                base_path('resources/agents/pi/taap-fs-policy.ts'),
                $tmp.'/taap-fs-policy.ts',
            );
            file_put_contents($tmp.'/cases.json', json_encode([
                'cases' => array_map(
                    fn (array $c) => ['cmd' => $c[0], 'rw' => $c[1], 'ro' => $c[2], 'expect' => $c[3]],
                    $this->cases(),
                ),
            ], JSON_PRETTY_PRINT));

            $script = <<<'JS'
                import * as fs from 'node:fs';
                const m = await import('/x/taap-fs-policy.ts');
                const { cases } = JSON.parse(fs.readFileSync('/x/cases.json', 'utf8'));
                const out = cases.map((c) => [c.cmd, m.fsPolicy(c.cmd, c.rw, c.ro)]);
                fs.writeFileSync('/x/result.json', JSON.stringify(out));
                JS;

            $p = new SymfonyProcess([
                'docker', 'run', '--rm',
                '-v', $tmp.':/x',
                '--entrypoint', 'node',
                'taap/pi:local',
                '--input-type=module', '-e', $script,
            ]);
            $p->setTimeout(90);
            // FUSE (fakeowner): dar un respiro a la propagacion host->contenedor
            // antes de arrancar (el contenedor veria el cases.json viejo).
            usleep(1_000_000);
            $p->run();
            $this->assertTrue($p->isSuccessful(), 'node: '.$p->getErrorOutput());

            // La lectura de result.json es el otro lado del FUSE (contenedor
            // ->host): reintenta hasta que el fichero este completo.
            $expected = array_map(fn (array $c) => [$c[0], $c[3]], $this->cases());
            $results = null;
            $deadline = microtime(true) + 15;
            while (microtime(true) < $deadline) {
                $raw = @file_get_contents($tmp.'/result.json');
                $decoded = $raw === false ? null : json_decode((string) $raw, true);
                if (is_array($decoded) && count($decoded) === count($expected)) {
                    $results = $decoded;
                    break;
                }
                usleep(500_000);
            }
            $this->assertIsArray($results, 'result.json no llego completo (FUSE); '.@file_get_contents($tmp.'/result.json'));

            foreach ($expected as $i => [$cmd, $expect]) {
                $this->assertArrayHasKey($i, $results, "sin fila para: {$cmd}");
                $this->assertArrayHasKey(1, $results[$i], "sin resultado para: {$cmd}");
                $this->assertSame(
                    $expect,
                    $results[$i][1],
                    "politica para: {$cmd}",
                );
            }
        } finally {
            File::deleteDirectory($tmp);
        }
    }
}

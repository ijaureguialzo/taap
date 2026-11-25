<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // FS compartido: los tests que crean usuarios (hook `created` ->
        // ensureFsDir) no deben tocar /data/fs real. El host_base tambien:
        // el esqueleto del agente (ensureFsSkeleton) lo usa para los
        // mounts de arena/carpeta y un valor por defecto de produccion
        // (/var/lib/taap) no existe en la maquina de desarrollo.
        $fsBase = sys_get_temp_dir().'/taap-fs-tests';
        if (! is_dir($fsBase)) {
            mkdir($fsBase, 0755, true);
        }
        config(['taap.fs.fs_base' => $fsBase, 'taap.fs.fs_host_base' => $fsBase]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

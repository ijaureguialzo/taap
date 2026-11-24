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
        // ensureFsDir) no deben tocar /data/fs real.
        $fsBase = sys_get_temp_dir().'/taap-fs-tests';
        if (! is_dir($fsBase)) {
            mkdir($fsBase, 0755, true);
        }
        config(['taap.fs.fs_base' => $fsBase]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

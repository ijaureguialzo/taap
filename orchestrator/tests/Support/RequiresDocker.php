<?php

namespace Tests\Support;

/**
 * Salta el test si no hay demonio Docker o si falta alguna imagen.
 * La suite de integracion (grupo 7) se ejecuta en CI con Docker; local
 * depende de `make images`.
 */
trait RequiresDocker
{
    protected function skipUnlessDocker(array $images = []): void
    {
        if (! $this->dockerAvailable()) {
            $this->markTestSkipped('No hay daemon Docker alcanzable');
        }

        foreach ($images as $image) {
            if (! $this->dockerImageExists($image)) {
                $this->markTestSkipped("Falta la imagen {$image} (make images)");
            }
        }
    }

    protected function dockerAvailable(): bool
    {
        exec('docker version --format "{{.Server.Version}}" 2>/dev/null', $out, $code);

        return $code === 0 && trim(implode('', $out)) !== '';
    }

    protected function dockerImageExists(string $image): bool
    {
        exec('docker image inspect '.$image.' >/dev/null 2>&1', $out, $code);

        return $code === 0;
    }
}

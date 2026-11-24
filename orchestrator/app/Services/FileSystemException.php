<?php

namespace App\Services;

/**
 * Error del FS compartido con su código HTTP (design D6): los controllers
 * de la API v1 y la fachada Livewire lo mapean directamente a la respuesta
 * (404 fuera de la raíz/inexistente, 409 conflicto, 413 tope, 422 nombre
 * inválido, 500 raíz no disponible).
 */
class FileSystemException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }
}

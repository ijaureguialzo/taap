<?php

namespace App\Enums;

/**
 * Modo de acceso de una carpeta del FS compartido en un agente
 * (shared-filesystem, design D4). En el contenedor se traduce en:
 * `disabled` = sin montaje, `read_only` = bind `:ro`, `read_write` = bind rw.
 */
enum FsMountMode: string
{
    case Disabled = 'disabled';
    case ReadOnly = 'read_only';
    case ReadWrite = 'read_write';
}

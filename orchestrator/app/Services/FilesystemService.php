<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * FS compartido (shared-filesystem, design D6): el NUCLEO unico de logica
 * de archivos. Lo consumen la web (Livewire) y la API v1 como fachadas
 * delgadas; un solo modelo de coordenadas (design D3):
 *
 *   - Raiz por sujeto (D1): admin = raiz fisica; usuario = <raiz>/<fs_slug>;
 *     agente = sus paths (vivo en el contenedor, no aqui).
 *   - Path canonico (D3): barra inicial, sin barra final, sin ./.. ni
 *     barras dobles. Es lo que se guarda, se muestra y se responde.
 *
 * El path que llega en una peticion se interpreta SIEMPRE relativo a la
 * raiz del sujeto (el "/" virtual de la spec): la contencion final es por
 * `realpath` (design D6), asi un `..` que sube por encima del home del
 * usuario (o un symlink que salga de la raiz) queda fuera -> 404.
 */
class FilesystemService
{
    /** Raiz fisica (via del proceso: dentro del contenedor del orquestador). */
    protected string $base;

    public function __construct(?string $base = null)
    {
        $this->base = rtrim((string) ($base ?? config('taap.fs.fs_base', '/data/fs')), '/');
    }

    /**
     * Raiz fisica (la que ve el proceso).
     */
    public function base(): string
    {
        return $this->base;
    }

    /* ================= raiz por sujeto (D1) ================= */

    /**
     * Base sobre la que opera $user: admin = raiz fisica; usuario = su
     * `<fs_slug>` (asegurado idempotente: el directorio se crea en el
     * primer uso — spec "Usuario nuevo obtiene su directorio").
     *
     * @throws FileSystemException 500 raiz no disponible; 409 usuario sin slug
     */
    public function baseFor(User $user): string
    {
        $rootReal = realpath($this->base);
        if ($rootReal === false) {
            throw new FileSystemException(500, 'Raiz del sistema de archivos no disponible');
        }

        if ($user->isAdmin()) {
            return $rootReal;
        }

        if (! filled($user->fs_slug)) {
            throw new FileSystemException(409, 'El usuario no tiene directorio asignado');
        }

        $home = $rootReal.'/'.$user->fs_slug;
        File::ensureDirectoryExists($home);

        return $home;
    }

    /* ================= normalizacion (spec Formato Unix) ================= */

    /**
     * Normaliza un path ABSOLUTO del sistema: colapsa barras dobles, elimina
     * `.` y resuelve `..` (puede salir por encima de la raiz: es la
     * contencion la que rechaza). Devuelve la forma canonica.
     */
    public function normalize(string $path): string
    {
        $path = trim($path);
        $path = $path === '' ? '/' : $path;
        if ($path[0] !== '/') {
            $path = '/'.$path;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * Forma virtual (la que ve el cliente) de un path respecto a la raiz de
     * $user: canonico, relativo a su raiz (`/` = su raiz; admin = raiz
     * fisica). Acepta el path tal (relativo o absoluto, con `..`).
     */
    public function virtualPath(User $user, string $path): string
    {
        $base = $this->baseFor($user);
        $absolute = $this->normalize($base.'/'.$this->joinRaw($path));

        return $this->relativeTo($base, $absolute);
    }

    /**
     * Une la raiz con el path tal del cliente (gestiona barra inicial/final).
     */
    protected function joinRaw(string $path): string
    {
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/'.ltrim($path, '/');
    }

    /**
     * `absolute` relativo a `base` (canonico, con `/` inicial; `/` si es la
     * propia base). `absolute` debe ser canonico.
     */
    protected function relativeTo(string $base, string $absolute): string
    {
        if ($absolute === $base) {
            return '/';
        }
        if (str_starts_with($absolute, $base.'/')) {
            return substr($absolute, strlen($base));
        }

        // Fuera de la raiz: se devuelve tal cual (el cliente lo ve "externo").
        return $absolute;
    }

    /* ================= contencion (D6) ================= */

    /**
     * Resuelve el path pedido por $user (su raiz + el path tal) y devuelve
     * el path FISICO absoluto y contenido:
     *
     *  - normaliza (barra inicial, sin `..`/`.`/duplicadas);
     *  - `realpath` en el ancestro existente mas profundo: un symlink que
     *    apunte fuera de la raiz del sujeto hace fallar la contencion (404);
     *  - la cola inexistente es segura por construccion (canonica: sin `..`
     *    y sin symlinks, porque no existe).
     *
     * Devuelve el path fisico (realpath si existe; si no, el que faltaria).
     *
     * @throws FileSystemException 404 fuera de la raiz permitida
     */
    public function resolve(User $user, string $path): string
    {
        $base = $this->baseFor($user);
        $candidate = $this->normalize($base.$this->joinRaw($path));

        $real = $this->contained($base, $candidate);
        if ($real === null) {
            throw new FileSystemException(404, 'Ruta fuera del area permitida o inexistente');
        }

        return $real;
    }

    /**
     * Contencion por realpath (design D6): `candidate` (canonico, dentro o
     * fuera de `base`) es contenido si su ancestro existente mas profundo
     * resuelve a `base` o debajo. Devuelve el path fisico o `null`.
     */
    protected function contained(string $base, string $candidate): ?string
    {
        $probe = $candidate;
        $tail = '';

        while (true) {
            $real = realpath($probe);
            if ($real !== false) {
                break;
            }
            $parent = dirname($probe);
            if ($parent === $probe) {
                return null; // subio hasta / y nada existe: fuera/inexistente
            }
            $tail = '/'.basename($probe).$tail;
            $probe = $parent;
        }

        if ($real !== $base && ! str_starts_with($real, $base.'/')) {
            return null; // un ancestro existente (symlink) sale de la raiz
        }

        return $real.$tail;
    }

    /* ================= operaciones (3.2/3.3) ================= */
    // (list/mkdir/rename/delete/upload/download/zip: proximas tareas)
}

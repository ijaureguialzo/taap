<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
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
     * Une la raiz con el path tal del cliente (gestiona barra inicial/final;
     * sin trim: los espacios dentro de los segmentos son datos, y la
     * validacion de nombres decide si son legales).
     */
    protected function joinRaw(string $path): string
    {
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
     * el path FISICO absoluto contenido:
     *
     *  - normaliza (barra inicial, sin `..`/`.`/duplicadas);
     *  - los ancestores se resuelven por `realpath`: un symlink en un
     *    parent que apunte fuera de la raiz del sujeto -> 404 (design D6);
     *  - el ULTIMO componente NO se sigue por defecto (los symlinks que
     *    salen de la base se PUDEN borrar/renombrar: se mueve el enlace,
     *    no su destino); con `follow` se sigue y re-contiene (navegacion,
     *    descarga, zip: un enlace que salga -> 404); mkdir/rename/delete lo
     *    hacen con `follow: false` (el enlace en si esta dentro).
     *  - la cola inexistente es segura por construccion (canonica: sin `..`
     *    y sin symlinks, porque no existe).
     *
     * Devuelve el path fisico (realpath con `follow`; si no, el propio de la
     * entrada, symlink o entidad).
     *
     * @throws FileSystemException 404 fuera de la raiz/inexistente; 422 nombre invalido
     */
    public function resolve(User $user, string $path, bool $follow = true): string
    {
        $base = $this->baseFor($user);
        $candidate = $this->normalize($base.$this->joinRaw($path));

        // Los bytes NUL/caracteres de control hacen que file_exists/realpath
        // lancen ValueError; se tratan como nombre invalido antes de tocar el disco.
        if (preg_match('/[\p{C}]/u', $candidate)) {
            throw new FileSystemException(422, 'Nombre de entrada inválido');
        }

        $real = $this->contained($base, $candidate, $follow);
        if ($real === null) {
            throw new FileSystemException(404, 'Ruta fuera del area permitida o inexistente');
        }

        return $real;
    }

    /**
     * Contencion (design D6): `candidate` (canonico) es contenido si su
     * ancestro existente mas profundo resuelve a `base` o debajo, y — con
     * `follow` — su ultimo componente (puede ser symlink) resuelve dentro.
     * Devuelve el path fisico o `null`.
     */
    protected function contained(string $base, string $candidate, bool $follow): ?string
    {
        if ($candidate === $base) {
            return $base;
        }

        $parent = dirname($candidate);
        $leaf = basename($candidate);

        // Ancestores: el existente mas profundo debe ser la base o estar debajo.
        $probe = $parent;
        $ancestorTail = '';
        while (true) {
            $real = realpath($probe);
            if ($real !== false) {
                break;
            }
            $up = dirname($probe);
            if ($up === $probe) {
                return null; // subio hasta / sin nada: fuera
            }
            $ancestorTail = '/'.basename($probe).$ancestorTail;
            $probe = $up;
        }
        if ($real !== $base && ! str_starts_with($real, $base.'/')) {
            return null; // un ancestor (symlink) sale de la raiz
        }

        $physical = $real.$ancestorTail.($leaf === '' ? '' : '/'.$leaf);

        // Inexistente: seguro (la cola ya es canonica: sin .. ni symlinks).
        if (! file_exists($physical) && ! is_link($physical)) {
            return $physical;
        }

        // Existent: se sigue el ultimo componente (symlink o entidad) y se
        // re-contiene el destino.
        $real = $follow ? realpath($physical) : false;
        if ($follow && $real !== false && $real !== $base && ! str_starts_with($real, $base.'/')) {
            return null; // el enlace apunta fuera de la raiz del sujeto
        }

        return $follow ? $real : $physical;
    }

    /* ================= operaciones (3.2) ================= */

    /**
     * Lista el contenido de un directorio (dirs primero, nombre ascenso
     * alfabético): name/type/size/mtime (ISO 8601). Los symlinks se LISTAN
     * (dir si apuntan a dir) pero no se siguen: navegar a uno que salga de
     * la raiz del sujeto -> 404 (resolve, design D6).
     *
     * @return array{path: string, entries: list<array{name: string, type: string, size: int, mtime: string}>}
     *
     * @throws FileSystemException 404 inexistente/no directorio/fuera
     */
    public function list(User $user, string $path): array
    {
        $dir = $this->resolve($user, $path, follow: true);
        if (! is_dir($dir)) {
            throw new FileSystemException(404, 'La ruta no existe o no es un directorio');
        }

        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $full = $dir.'/'.$name;
            // is_dir sigue symlinks: un enlace a directorio se muestra como dir
            $isDir = is_dir($full);
            $stat = @stat($full);
            $entries[] = [
                'name' => $name,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $isDir ? 0 : (int) ($stat['size'] ?? 0),
                'mtime' => Carbon::createFromTimestamp((int) ($stat['mtime'] ?? time()))->toIso8601String(),
            ];
        }

        usort($entries, function (array $a, array $b): int {
            $dirDiff = ($a['type'] === 'dir' ? 0 : 1) - ($b['type'] === 'dir' ? 0 : 1);

            return $dirDiff !== 0 ? $dirDiff : strcasecmp($a['name'], $b['name']);
        });

        return [
            'path' => $this->virtualPath($user, $path),
            'entries' => $entries,
        ];
    }

    /**
     * Crea un directorio (recursivo: `datos/pruebas` crea los parents)
     * validando el nombre. Devuelve el path virtual canónico creado.
     *
     * @throws FileSystemException 409 ya existe; 422 nombre inválido
     */
    public function mkdir(User $user, string $path): string
    {
        $dir = $this->resolve($user, $path, follow: false);
        $this->assertValidPath($dir);

        if (file_exists($dir) || is_link($dir)) {
            throw new FileSystemException(409, 'Ya existe una entrada con ese nombre');
        }

        if (! File::makeDirectory($dir, 0755, true) && ! is_dir($dir)) {
            throw new FileSystemException(500, 'No se pudo crear el directorio');
        }

        return $this->virtualPath($user, $path);
    }

    /**
     * Renombra una entrada (fichero o directorio) dentro de su directorio
     * padre. `newName` es solo el nombre (no path). Symlinks: se mueve el
     * enlace, no el destino.
     *
     * @throws FileSystemException 404 no existe; 409 el destino ya existe;
     *                             422 nombre inválido
     */
    public function rename(User $user, string $path, string $newName): string
    {
        $source = $this->resolve($user, $path, follow: false);
        if (! file_exists($source) && ! is_link($source)) {
            throw new FileSystemException(404, 'La entrada no existe');
        }

        $newName = $this->assertSingleName($newName);
        $parent = dirname($source);
        $target = $parent.'/'.$newName;

        if (file_exists($target) || is_link($target)) {
            throw new FileSystemException(409, 'Ya existe una entrada con ese nombre en el destino');
        }

        if (! @rename($source, $target)) {
            throw new FileSystemException(500, 'No se pudo renombrar la entrada');
        }

        return $this->virtualPath($user, $this->joinRaw(dirname($this->virtualPath($user, $path))).'/'.$newName);
    }

    /**
     * Borra una entrada (fichero o directorio recursivo). Los symlinks se
     * eliminan sin seguirlos. La raiz del propio sujeto ("/") no se puede
     * borrar.
     *
     * @throws FileSystemException 404 no existe; 409 es la raiz
     */
    public function delete(User $user, string $path): void
    {
        $target = $this->resolve($user, $path, follow: false);
        if (! file_exists($target) && ! is_link($target)) {
            throw new FileSystemException(404, 'La entrada no existe');
        }
        if ($target === $this->baseFor($user)) {
            throw new FileSystemException(409, 'No se puede borrar la raiz del area');
        }

        $this->deleteRecursive($target);
    }

    /**
     * Borrado recursivo sin seguir symlinks (un enlace se elimina, nunca su
     * destino — design D6: los symlinks que salen de la base no se siguen).
     */
    protected function deleteRecursive(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (! @unlink($path)) {
                throw new FileSystemException(500, "No se pudo borrar {$path}");
            }

            return;
        }

        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $this->deleteRecursive($path.'/'.$name);
            }
            if (! @rmdir($path)) {
                throw new FileSystemException(500, "No se pudo borrar el directorio {$path}");
            }

            return;
        }

        throw new FileSystemException(404, "La entrada ya no existe: {$path}");
    }

    /* ================= validacion de nombres (spec: nombre invalido) ================= */

    /**
     * Valida los segmentos de un path CANONICO absoluto: sin vacios/./.. ,
     * sin caracteres de control ni `/`\0 (imposible por construccion), sin
     * espacios al inicio/final y <= 255 (limite del sistema).
     *
     * @throws FileSystemException 422
     */
    protected function assertValidPath(string $absolutePath): void
    {
        foreach (explode('/', ltrim($absolutePath, '/')) as $segment) {
            $this->assertSingleName($segment, asSegment: true);
        }
    }

    /**
     * Valida un NOMBRE de entrada (una sola pieza): no contiene `/`, no es
     * `.`/`..` ni vacío, sin control chars, sin espacios en los bordes y
     * <= 255 caracteres. Devuelve el nombre.
     *
     * @throws FileSystemException 422
     */
    protected function assertSingleName(string $name, bool $asSegment = false): string
    {
        $name = $asSegment ? $name : trim($name);

        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/')) {
            throw new FileSystemException(422, 'Nombre de entrada inválido');
        }
        if (strlen($name) > 255) {
            throw new FileSystemException(422, 'Nombre demasiado largo');
        }
        if (preg_match('/[\p{C}]/u', $name)) {
            throw new FileSystemException(422, 'Nombre contiene caracteres inválidos');
        }
        if ($name !== trim($name)) {
            throw new FileSystemException(422, 'Nombre con espacios en los bordes');
        }

        return $name;
    }

    /* ================= operaciones (3.3) ================= */
    // (upload/download/zip: proxima tarea)
}

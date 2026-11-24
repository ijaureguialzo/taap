<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FilesystemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * API v1 del FS compartido (shared-filesystem 4.1): fachada delgada sobre
 * `FilesystemService`. La raiz sobre la que opera la peticion se resuelve en
 * el servidor segun el rol del usuario autenticado (design D3): un usuario no
 * admin siempre opera sobre su `<fs_slug>` y nunca elige raiz.
 *
 * Errores: `FileSystemException` lleva su codigo HTTP (404/409/413/422/500)
 * y el renderer global de bootstrap/app.php lo convierte en `{"error": ...}`.
 */
class FileController extends Controller
{
    public function __construct(protected FilesystemService $fs) {}

    /**
     * GET /api/v1/files?path=/
     * Listado de un directorio (dirs primero; en admin, badge `owner`).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $this->fs->list($request->user(), (string) $request->query('path', '/'));

        return response()->json($data);
    }

    /**
     * POST /api/v1/files/folders  {"path": "/datos/pruebas"}
     * Crea un directorio (recursivo). 201 con la ruta canónica.
     */
    public function storeFolder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
        ]);

        $path = $this->fs->mkdir($request->user(), $data['path']);

        return response()->json(['path' => $path], 201);
    }

    /**
     * POST /api/v1/files/rename  {"path": "/f.txt", "newName": "g.txt"}
     * Renombra dentro de su directorio padre.
     */
    public function rename(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'newName' => ['required', 'string', 'max:255'],
        ]);

        $path = $this->fs->rename($request->user(), $data['path'], $data['newName']);

        return response()->json(['path' => $path]);
    }

    /**
     * DELETE /api/v1/files?path=/x[&confirm=1]
     * Borra (recursivo en directorios). `confirm` es obligatorio para borrar
     * la home de un usuario desde la vista de admin.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'confirm' => ['boolean'],
        ]);

        $this->fs->delete($request->user(), $data['path'], (bool) ($data['confirm'] ?? false));

        return response()->json(['deleted' => $this->fs->virtualPath($request->user(), $data['path'])]);
    }

    /**
     * POST /api/v1/files/uploads  multipart: {"path": "/datos", "file": ...}
     * Sube un fichero (multipart). 201 con los metadatos del fichero.
     */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'file' => ['required', 'file'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $meta = $this->fs->upload($request->user(), $data['path'], (string) $file->getClientOriginalName(), fopen($file->getPathname(), 'rb'));

        return response()->json($meta, 201);
    }

    /**
     * GET /api/v1/files/download?path=/x
     * Fichero -> stream con su nombre; directorio -> .zip del árbol.
     *
     * @return BinaryFileResponse|JsonResponse
     */
    public function download(Request $request)
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
        ]);

        $user = $request->user();
        $physical = $this->fs->resolve($user, $data['path'], follow: true);

        if (is_dir($physical)) {
            return $this->fs->zip($user, $data['path']);
        }

        return $this->fs->download($user, $data['path']);
    }
}

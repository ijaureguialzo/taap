<?php

namespace App\Livewire\Files;

use App\Services\FileSystemException;
use App\Services\FilesystemService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * Explorador web de ficheros (shared-filesystem 5.1): un componente de
 * navegacion; la raiz se resuelve en el servidor por rol (design D3):
 * usuario = su <fs_slug> (su home es "/"); admin = raiz fisica, con la
 * ubicacion fisica en solo lectura (TAAP_FS_ROOT).
 */
class Index extends Component
{
    use WithFileUploads;

    /** @var ?string path virtual pedido (query string de la URL) */
    public ?string $path = null;

    /* --- acciones (5.2): crear / subir / renombrar / borrar --- */

    public string $newFolder = '';

    /** @var TemporaryUploadedFile|string|null subida temporal del input (firmada) */
    public $uploadFile = null;

    public ?string $renameTarget = null;

    public string $renameName = '';

    public ?string $deleteTarget = null;

    /** @var int % de progreso de la subida actual (0-100) */
    public int $uploadProgress = 0;

    public ?string $error = null;

    /** @var string path virtual canónico del listado actual */
    public string $listingPath = '/';

    /** @var list<array{name: string, type: string, size: int, mtime: string, target: string, owner: ?string}> */
    public array $entries = [];

    /** @var ?string raiz fisica (solo admin; visualización en solo lectura) */
    public ?string $physicalRoot = null;

    /** @var list<array{label: string, path: string}> migas de pan */
    public array $breadcrumbs = [];

    public function mount(): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            $this->physicalRoot = rtrim((string) config('taap.fs.fs_host_base', '/var/lib/taap/fs'), '/');
        }

        $this->open($this->path ?? '/');
    }

    /**
     * Navega a un path virtual: re-lista y limpia/pones el error.
     */
    public function open(string $path): void
    {
        try {
            $listing = app(FilesystemService::class)->list(auth()->user(), $path);
            $this->listingPath = $listing['path'];
            $this->entries = collect($listing['entries'])
                ->map(fn (array $e) => $e + ['target' => $this->targetOf($e['name'])])
                ->all();
            $this->error = null;
        } catch (FileSystemException $e) {
            $this->error = $e->getMessage();
        }

        $this->refreshBreadcrumbs();
    }

    protected function refreshBreadcrumbs(): void
    {
        $crumbs = [['label' => __('files.root'), 'path' => '/']];
        $acc = '';

        foreach (array_filter(explode('/', $this->listingPath)) as $part) {
            $acc .= '/'.$part;
            $crumbs[] = ['label' => (string) $part, 'path' => $acc];
        }

        $this->breadcrumbs = $crumbs;
    }

    /**
     * Crea un directorio (recursivo si trae barra). Spec: "crea la carpeta
     * datos/pruebas".
     */
    public function createFolder(): void
    {
        $this->validate(['newFolder' => ['required', 'string', 'max:2048']]);

        try {
            app(FilesystemService::class)->mkdir(auth()->user(), $this->newFolder);
            $this->error = null;
            $this->newFolder = '';
            session()->flash('success', __('files.created'));
            $this->open($this->listingPath);
        } catch (FileSystemException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * Sube el fichero temporal a la carpeta actual (streaming + rename
     * atómico en el servicio; 413 tope).
     */
    public function doUpload(): void
    {
        $source = null;
        $name = null;

        // Forma real (request/synth): TemporaryUploadedFile; en tests o forma
        // deshidratada: la path firmada como string.
        if ($this->uploadFile instanceof TemporaryUploadedFile) {
            $source = (string) $this->uploadFile->getPathname();
            $name = $this->uploadFile->getClientOriginalName();
        } elseif (is_string($this->uploadFile) && $this->uploadFile !== '' && is_file($this->uploadFile)) {
            $source = $this->uploadFile;
            $name = basename($this->uploadFile);
        }

        if ($source === null || ! is_file($source)) {
            $this->error = __('files.no_file');
            $this->uploadFile = null;

            return;
        }

        $stream = @fopen($source, 'rb');
        if ($stream === false) {
            $this->error = __('files.no_file');

            return;
        }

        try {
            app(FilesystemService::class)->upload(auth()->user(), $this->listingPath, $name, $stream);
            $this->error = null;
            session()->flash('success', __('files.uploaded'));
            $this->open($this->listingPath);
        } catch (FileSystemException $e) {
            $this->error = $e->getMessage();
        } finally {
            fclose($stream);
        }

        $temporary = $this->uploadFile instanceof TemporaryUploadedFile;
        $this->uploadFile = null;
        if ($temporary) {
            @unlink($source);
        }
    }

    public function startRename(string $name): void
    {
        $this->renameTarget = $this->targetOf($name);
        $this->renameName = $name;
    }

    public function cancelRename(): void
    {
        $this->renameTarget = null;
        $this->renameName = '';
    }

    public function saveRename(): void
    {
        $this->validate(['renameName' => ['required', 'string', 'max:255']]);

        $target = $this->renameTarget;
        $newName = $this->renameName;
        $this->renameTarget = null;
        $this->renameName = '';

        if (! $target) {
            return;
        }

        try {
            app(FilesystemService::class)->rename(auth()->user(), $target, $newName);
            $this->error = null;
            session()->flash('success', __('files.renamed'));
            $this->open(dirname($target) ?: '/');
        } catch (FileSystemException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * Borra una entrada: fichero (o entrada no protegida) directo; carpeta
     * (recursivo) o home de usuario en la vista de admin, tras confirmación
     * explicita (confirmDelete).
     */
    public function delete(string $name): void
    {
        $target = $this->targetOf($name);

        try {
            $physical = app(FilesystemService::class)->resolve(auth()->user(), $target, follow: false);
        } catch (FileSystemException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $recursive = is_dir($physical) && ! is_link($physical);
        if ($recursive || app(FilesystemService::class)->isUserHome(auth()->user(), $target)) {
            $this->deleteTarget = $target;

            return;
        }

        $this->performDelete($target);
    }

    public function cancelDelete(): void
    {
        $this->deleteTarget = null;
    }

    public function confirmDelete(): void
    {
        $target = $this->deleteTarget;
        $this->deleteTarget = null;

        if ($target) {
            $this->performDelete($target, confirm: true);
        }
    }

    protected function performDelete(string $target, bool $confirm = false): void
    {
        try {
            app(FilesystemService::class)->delete(auth()->user(), $target, confirm: $confirm);
            $this->error = null;
            session()->flash('success', __('files.deleted'));
            $this->open(dirname($target) ?: '/');
        } catch (FileSystemException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function onUploadProgress(int $progress): void
    {
        $this->uploadProgress = max(0, min(100, $progress));
    }

    public function onUploadError(): void
    {
        $this->error = __('files.upload_error');
        $this->uploadProgress = 0;
    }

    /** @return string URL de descarga (fichero o .zip) para una entrada */
    public function downloadUrl(string $name): string
    {
        return route('files.download', ['path' => $this->targetOf($name)]);
    }

    protected function targetOf(string $name): string
    {
        return $this->listingPath === '/' ? '/'.$name : $this->listingPath.'/'.$name;
    }

    public function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $n = (float) $bytes;
        $i = 0;

        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return $i === 0 ? $bytes.' B' : number_format($n, $n < 10 ? 1 : 0).' '.$units[$i];
    }

    public function render(): View
    {
        return view('livewire.files.index');
    }
}

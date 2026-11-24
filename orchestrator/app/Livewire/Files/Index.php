<?php

namespace App\Livewire\Files;

use App\Services\FileSystemException;
use App\Services\FilesystemService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Explorador web de ficheros (shared-filesystem 5.1): un componente de
 * navegacion; la raiz se resuelve en el servidor por rol (design D3):
 * usuario = su <fs_slug> (su home es "/"); admin = raiz fisica, con la
 * ubicacion fisica en solo lectura (TAAP_FS_ROOT).
 */
class Index extends Component
{
    /** @var ?string path virtual pedido (query string de la URL) */
    public ?string $path = null;

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

<div class="files-index">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><x-icon name="folder2" class="me-2" />{{ __('files.title') }}</h1>
    </div>

    @if ($physicalRoot)
        <div class="alert alert-light border small mb-3 py-2">
            <x-icon name="hdd" class="me-1" />
            {{ __('files.physical_root', ['path' => '<code>'.e($physicalRoot).'</code>']) }}
        </div>
    @endif

    <nav aria-label="{{ __('files.title') }}" class="mb-3">
        <ol class="breadcrumb mb-1">
            @foreach ($breadcrumbs as $i => $crumb)
                <li class="breadcrumb-item @if ($i === count($breadcrumbs) - 1)active @endif">
                    @if ($i < count($breadcrumbs) - 1)
                        <a class="breadcrumb-item" href="#" wire:click="open(@js($crumb['path']))">{{ $crumb['label'] }}</a>
                    @else
                        {{ $crumb['label'] }}
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>

    @if ($error)
        <div class="alert alert-danger py-2">{{ $error }}</div>
    @endif

    <table class="table table-hover align-middle">
        <thead>
            <tr class="table-light">
                <th>{{ __('files.name') }}</th>
                <th>{{ __('files.type') }}</th>
                <th class="text-end">{{ __('files.size') }}</th>
                <th>{{ __('files.modified') }}</th>
                @if (auth()->user()->isAdmin())
                    <th>{{ __('files.owner') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $e)
                <tr>
                    <td>
                        <a class="text-decoration-none" href="#" wire:click="open(@js($e['target']))">
                            <x-icon name="{{ $e['type'] === 'dir' ? 'folder' : 'file-earmark-text' }}" class="me-1" />
                            {{ $e['name'] }}
                        </a>
                    </td>
                    <td>
                        <span class="badge text-bg-{{ $e['type'] === 'dir' ? 'primary' : 'secondary' }}">
                            {{ $e['type'] === 'dir' ? __('files.type.dir') : __('files.type.file') }}
                        </span>
                    </td>
                    <td class="text-end text-muted">{{ $e['type'] === 'file' ? $this->humanSize((int) $e['size']) : '' }}</td>
                    <td class="text-muted">{{ \Illuminate\Support\Carbon::parse($e['mtime'])->format('Y-m-d H:i') }}</td>
                    @if (auth()->user()->isAdmin())
                        <td>
                            @if (! empty($e['owner']))
                                <span class="badge text-bg-info"><x-icon name="person" class="me-1" />{{ $e['owner'] }}</span>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td class="text-muted" colspan="{{ auth()->user()->isAdmin() ? 5 : 4 }}">{{ __('files.empty') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

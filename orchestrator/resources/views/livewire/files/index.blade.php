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

    {{-- Barra de acciones (5.2) --}}
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <div class="input-group input-group-sm" style="max-width: 260px;">
            <input type="text" wire:model="newFolder" class="form-control" placeholder="{{ __('files.new_folder_ph') }}">
            <button class="btn btn-outline-primary" wire:click="createFolder" wire:loading.attr="disabled">
                <x-icon name="folder-plus" class="me-1" wire:loading.class="d-none" />
                <span wire:loading wire:target="createFolder" class="spinner-border spinner-border-sm me-1" role="status"></span>
                {{ __('files.create_folder') }}
            </button>
        </div>
        <div class="input-group input-group-sm" style="max-width: 320px;">
            <input type="file" wire:model="uploadFile" class="form-control"
                   x-on:livewire-upload-progress="onUploadProgress($event.detail.progress)"
                   x-on:livewire-upload-error="onUploadError()">
            <button class="btn btn-outline-primary" wire:click="doUpload" wire:disabled="$uploadFile === null" wire:loading.attr="disabled" wire:target="doUpload">
                <x-icon name="cloud-arrow-up" class="me-1" wire:loading.class="d-none" />
                <span wire:loading wire:target="doUpload" class="spinner-border spinner-border-sm me-1" role="status"></span>
                {{ __('files.upload') }}
            </button>
        </div>
    </div>

    @if ($error)
        <div class="alert alert-danger py-2">{{ $error }}</div>
    @endif

    <div class="table-responsive">
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
                    <th class="text-end">{{ __('files.actions') }}</th>
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
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary" wire:click="startRename(@js($e['name']))" title="{{ __('files.rename') }}">
                                <x-icon name="pencil" />
                            </button>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ $this->downloadUrl($e['name']) }}" title="{{ $e['type'] === 'dir' ? __('files.download_zip') : __('files.download') }}">
                                <x-icon name="{{ $e['type'] === 'dir' ? 'file-earmark-zip' : 'download' }}" />
                            </a>
                            <button class="btn btn-sm btn-outline-danger" wire:click="delete(@js($e['name']))" title="{{ __('files.delete') }}">
                                <x-icon name="trash" />
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="text-muted" colspan="{{ auth()->user()->isAdmin() ? 6 : 5 }}">{{ __('files.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modal: renombrar --}}
    @if ($renameTarget !== null)
        <div class="modal d-block" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content" wire:key="rename-{{ $renameTarget }}">
                    <form wire:submit="saveRename">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('files.rename') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" wire:click="cancelRename"></button>
                        </div>
                        <div class="modal-body">
                            <input type="text" wire:model="renameName" class="form-control" autofocus>
                            @error('renameName')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" wire:click="cancelRename" data-bs-dismiss="modal">{{ __('actions.cancel') }}</button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('actions.save') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal: confirmacion de borrado (recursivo / home de usuario) --}}
    @if ($deleteTarget !== null)
        <div class="modal d-block" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content" wire:key="delete-{{ $deleteTarget }}">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('files.confirm_delete_title') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" wire:click="cancelDelete"></button>
                    </div>
                    <div class="modal-body">
                        {{ str_replace(':target', '<code>'.e($deleteTarget).'</code>', __('files.confirm_delete_msg')) }}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" wire:click="cancelDelete" data-bs-dismiss="modal">{{ __('actions.cancel') }}</button>
                        <button type="button" class="btn btn-danger" wire:click="confirmDelete" wire:loading.attr="disabled">
                            <span wire:loading wire:target="confirmDelete" class="spinner-border spinner-border-sm me-1" role="status"></span>
                            {{ __('files.yes') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

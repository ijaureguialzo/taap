<div class="mcp-index">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ __('mcp.title') }}</h1>
        @unless ($creating)
            <button class="btn btn-primary btn-sm" wire:click="startCreate"><x-icon name="plus-lg" class="me-1" />{{ __('mcp.new') }}</button>
        @endunless
    </div>

    @if ($creating)
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('mcp.name') }} *</label>
                        <input type="text" wire:model="name" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('mcp.type') }}</label>
                        <select wire:model="type" class="form-select form-select-sm">
                            <option value="http">http</option>
                            <option value="stdio">stdio</option>
                        </select>
                    </div>
                    @if ($type === 'http')
                        <div class="col-md-6">
                            <label class="form-label small">{{ __('mcp.url') }} *</label>
                            <input type="url" wire:model="url" class="form-control form-control-sm" required>
                        </div>
                    @else
                        <div class="col-md-6">
                            <label class="form-label small">{{ __('mcp.command') }} *</label>
                            <div class="input-group input-group-sm">
                                <input type="text" wire:model="command" class="form-control" required>
                                <input type="text" wire:model="args" class="form-control"
                                       style="max-width: 220px" placeholder="{{ __('mcp.args') }}">
                            </div>
                        </div>
                    @endif
                </div>
                <div class="form-check form-switch mt-3 mb-3">
                    <input class="form-check-input" type="checkbox" wire:model="enabled" id="mcp-enabled">
                    <label class="form-check-label small" for="mcp-enabled">{{ __('mcp.enabled') }}</label>
                </div>
                <div>
                    <button class="btn btn-primary btn-sm" wire:click="save"><x-icon name="check-lg" class="me-1" />{{ __('actions.save') }}</button>
                    <button class="btn btn-outline-secondary btn-sm" wire:click="cancel"><x-icon name="x-lg" class="me-1" />{{ __('actions.cancel') }}</button>
                </div>
            </div>
        </div>
    @endif

    @if ($servers->isEmpty())
        <div class="card"><div class="card-body text-center text-muted py-5"><x-icon name="plug" size="lg" class="d-block mx-auto mb-2" />{{ __('mcp.empty') }}</div></div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ __('mcp.name') }}</th>
                            <th>{{ __('mcp.type') }}</th>
                            <th>{{ __('mcp.url') }}</th>
                            <th class="text-center">agentes</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $s)
                            <tr class="{{ $s->enabled ? '' : 'table-secondary' }}">
                                <td class="fw-semibold">{{ $s->name }}</td>
                                <td><span class="badge bg-secondary">{{ $s->type }}</span></td>
                                <td class="small text-truncate" style="max-width: 320px">
                                    {{ $s->type === 'http' ? $s->url : $s->command }}
                                </td>
                                <td class="text-center">{{ $s->agents_count }}</td>
                                <td class="text-end">
                                    <button class="btn btn-outline-secondary btn-sm" wire:click="startEdit({{ $s->id }})" aria-label="{{ __('actions.edit') }}"><x-icon name="pencil" /></button>
                                    <button class="btn btn-outline-secondary btn-sm" wire:click="toggle({{ $s->id }})"
                                            title="{{ __('mcp.enabled') }}" aria-label="{{ __('mcp.enabled') }}">
                                        <x-icon name="power" class="{{ $s->enabled ? 'text-success' : 'text-secondary opacity-50' }}" />
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $s->id }})"
                                            data-confirm="{{ __('mcp.delete') }}?" aria-label="{{ __('mcp.delete') }}?"><x-icon name="trash3" /></button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <script>
        document.querySelectorAll('.mcp-index [data-confirm]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                if (!window.confirm(btn.dataset.confirm)) e.preventDefault();
            });
        });
    </script>
</div>

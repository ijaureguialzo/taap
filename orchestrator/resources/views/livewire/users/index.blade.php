<div class="users-index">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ __('users.title') }}</h1>
    </div>

    @if ($edit_id)
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('users.role') }}</label>
                        <select wire:model="role" class="form-select form-select-sm">
                            <option value="user">user</option>
                            <option value="admin">admin</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('users.max_agents') }}</label>
                        <input type="number" wire:model="max_agents" class="form-control form-control-sm" min="0">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('users.language') }}</label>
                        <select wire:model="language" class="form-select form-select-sm">
                            <option value="es">es</option>
                            <option value="en">en</option>
                            <option value="eu">eu</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" wire:model="active" id="u-active">
                            <label class="form-check-label" for="u-active">{{ __('users.active') }}</label>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary btn-sm" wire:click="save">{{ __('actions.save') }}</button>
                    <button class="btn btn-outline-secondary btn-sm" wire:click="cancel">{{ __('actions.cancel') }}</button>
                </div>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('users.name') }}</th>
                        <th>{{ __('users.email') }}</th>
                        <th>{{ __('users.role') }}</th>
                        <th class="text-center">{{ __('users.active') }}</th>
                        <th class="text-center">{{ __('users.max_agents') }}</th>
                        <th class="text-center">{{ __('users.agents_running') }}</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $u)
                        <tr class="{{ $u->active ? '' : 'table-secondary' }}">
                            <td class="fw-semibold">{{ $u->name }}</td>
                            <td class="small">{{ $u->email }}</td>
                            <td><span class="badge bg-{{ $u->role === \App\Enums\UserRole::Admin ? 'warning' : 'secondary' }}">{{ $u->role->value }}</span></td>
                            <td class="text-center">{{ $u->active ? '✓' : '✗' }}</td>
                            <td class="text-center">{{ $u->agentQuota() }}</td>
                            <td class="text-center">{{ $u->runningAgentsCount() }}</td>
                            <td class="text-end">
                                <button class="btn btn-outline-secondary btn-sm" wire:click="startEdit({{ $u->id }})">✎</button>
                                <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $u->id }})"
                                        data-confirm="{{ __('users.delete') }}?">🗑</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $users->links() }}
    </div>

    <script>
        document.querySelectorAll('.users-index [data-confirm]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                if (!window.confirm(btn.dataset.confirm)) e.preventDefault();
            });
        });
    </script>
</div>

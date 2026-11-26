<div class="users-index">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ __('users.title') }}</h1>
        <button class="btn btn-primary btn-sm" wire:click="startCreate">
            <x-icon name="person-plus-fill" class="me-1" />{{ __('users.new') }}
        </button>
    </div>

    @if ($creating)
        <div class="card mb-4 border-success">
            <div class="card-body">
                <h2 class="h6 mb-3">{{ __('users.create_title') }}</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small" for="create-name">{{ __('users.name') }}</label>
                        <input type="text" id="create-name" wire:model="create_name" class="form-control form-control-sm @error('create_name') is-invalid @enderror">
                        @error('create_name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="create-email">{{ __('users.email') }}</label>
                        <input type="email" id="create-email" wire:model="create_email" class="form-control form-control-sm @error('create_email') is-invalid @enderror">
                        @error('create_email')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small" for="create-role">{{ __('users.role') }}</label>
                        <select id="create-role" wire:model="create_role" class="form-select form-select-sm @error('create_role') is-invalid @enderror">
                            <option value="user">user</option>
                            <option value="admin">admin</option>
                        </select>
                        @error('create_role')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" wire:model="create_active" id="create-active">
                            <label class="form-check-label" for="create-active">{{ __('users.active') }}</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="create-pass">{{ __('users.password_new') }}</label>
                        <input type="password" id="create-pass" wire:model="create_password" class="form-control form-control-sm @error('create_password') is-invalid @enderror" autocomplete="new-password">
                        @error('create_password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="create-pass-confirm">{{ __('users.password_new_confirmation') }}</label>
                        <input type="password" id="create-pass-confirm" wire:model="create_password_confirmation" class="form-control form-control-sm" autocomplete="new-password">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="create-quota">{{ __('users.max_agents') }}</label>
                        <input type="number" id="create-quota" wire:model="create_max_agents" class="form-control form-control-sm @error('create_max_agents') is-invalid @enderror" min="0" max="100">
                        @error('create_max_agents')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="create-lang">{{ __('users.language') }}</label>
                        <select id="create-lang" wire:model="create_language" class="form-select form-select-sm">
                            <option value="es">es</option>
                            <option value="en">en</option>
                            <option value="eu">eu</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-success btn-sm" wire:click="create"><x-icon name="check-lg" class="me-1" />{{ __('users.create') }}</button>
                    <button class="btn btn-outline-secondary btn-sm" wire:click="cancel"><x-icon name="x-lg" class="me-1" />{{ __('actions.cancel') }}</button>
                </div>
            </div>
        </div>
    @endif

    @if ($edit_id)
        <div class="card mb-4 border-primary">
            <div class="card-body">
                <h2 class="h6 mb-3">{{ __('users.edit_title') }}</h2>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small" for="u-role">{{ __('users.role') }}</label>
                        <select id="u-role" wire:model="role" class="form-select form-select-sm @error('role') is-invalid @enderror">
                            <option value="user">user</option>
                            <option value="admin">admin</option>
                        </select>
                        @error('role')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="u-max-agents">{{ __('users.max_agents') }}</label>
                        <input type="number" id="u-max-agents" wire:model="max_agents" class="form-control form-control-sm @error('max_agents') is-invalid @enderror" min="0" max="100">
                        @error('max_agents')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="u-language">{{ __('users.language') }}</label>
                        <select id="u-language" wire:model="language" class="form-select form-select-sm">
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
                    <button class="btn btn-primary btn-sm" wire:click="save"><x-icon name="check-lg" class="me-1" />{{ __('actions.save') }}</button>
                    <button class="btn btn-outline-secondary btn-sm" wire:click="cancel"><x-icon name="x-lg" class="me-1" />{{ __('actions.cancel') }}</button>
                </div>
            </div>
            <div class="card-body border-top">
                <h3 class="h6 mb-2">{{ __('users.password_reset_title') }}</h3>
                <p class="small text-muted mb-3">{{ __('users.password_reset_hint') }}</p>
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small" for="reset-pass">{{ __('users.password_new') }}</label>
                        <input type="password" id="reset-pass" wire:model="reset_password" class="form-control form-control-sm @error('reset_password') is-invalid @enderror" autocomplete="new-password">
                        @error('reset_password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="reset-pass-confirm">{{ __('users.password_new_confirmation') }}</label>
                        <input type="password" id="reset-pass-confirm" wire:model="reset_password_confirmation" class="form-control form-control-sm" autocomplete="new-password">
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-outline-warning btn-sm w-100" wire:click="resetPassword"><x-icon name="key" class="me-1" />{{ __('users.password_reset_button') }}</button>
                    </div>
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
                                <button class="btn btn-outline-secondary btn-sm" wire:click="startEdit({{ $u->id }})" aria-label="{{ __('actions.edit') }}"><x-icon name="pencil" /></button>
                                <button class="btn btn-outline-danger btn-sm" wire:click="delete({{ $u->id }})"
                                        data-confirm="{{ __('users.delete_confirm', ['name' => $u->name, 'agents' => $u->agents_count]) }}" aria-label="{{ __('users.delete') }}"><x-icon name="trash3" /></button>
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

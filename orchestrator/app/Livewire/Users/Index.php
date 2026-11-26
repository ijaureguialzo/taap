<?php

namespace App\Livewire\Users;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\UserLifecycle;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    /* edición de un usuario existente */
    public ?int $edit_id = null;

    public ?UserRole $role = null;

    public ?bool $active = null;

    public ?int $max_agents = null;

    public string $language = 'es';

    /* reseteo de contraseña (del usuario en edición) */
    public string $reset_password = '';

    public string $reset_password_confirmation = '';

    /* alta de un usuario nuevo */
    public bool $creating = false;

    public string $create_name = '';

    public string $create_email = '';

    public string $create_password = '';

    public string $create_password_confirmation = '';

    public string $create_role = 'user';

    public ?int $create_max_agents = null;

    public string $create_language = 'es';

    public bool $create_active = true;

    public function startCreate(): void
    {
        $this->cancel();
        $this->reset([
            'create_name',
            'create_email',
            'create_password',
            'create_password_confirmation',
        ]);
        $this->create_role = 'user';
        $this->create_max_agents = null;
        $this->create_language = 'es';
        $this->create_active = true;
        $this->creating = true;
    }

    public function startEdit(int $id): void
    {
        $u = User::findOrFail($id);
        $this->cancel();
        $this->edit_id = $id;
        $this->role = $u->role;
        $this->active = $u->active;
        $this->max_agents = $u->agent_quota;
        $this->language = (string) $u->locale;
    }

    public function cancel(): void
    {
        $this->edit_id = null;
        $this->creating = false;
        $this->reset(['reset_password', 'reset_password_confirmation']);
    }

    public function save(): void
    {
        $this->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
            'max_agents' => ['nullable', 'integer', 'min:0', 'max:100'],
            'language' => ['required', Rule::in(['es', 'en', 'eu'])],
        ]);

        $user = User::findOrFail($this->edit_id);
        $current = auth()->user();

        // Guardas anti-auto-bloqueo: el admin no puede deshabilitarse ni
        // degradarse a sí mismo (la spec users-and-roles lo exige).
        if ($user->id === $current->id && $this->active === false) {
            session()->flash('error', __('users.error_self_disable'));

            return;
        }
        if ($user->id === $current->id && $this->role === UserRole::User) {
            session()->flash('error', __('users.error_self_demote'));

            return;
        }

        $wasActive = $user->active;
        $disconnecting = $wasActive && ! $this->active;
        $reactivating = ! $wasActive && $this->active;

        DB::transaction(function () use ($user, $current, $disconnecting, $reactivating) {
            if ($disconnecting) {
                $this->lifecycle()->stopAndRevoke($user);
            } elseif ($reactivating) {
                $this->lifecycle()->reactivate($user);
            }

            $user->forceFill([
                'role' => $this->role,
                'active' => $this->active,
                'agent_quota' => $this->max_agents,
                'locale' => $this->language,
            ])->save();

            $auditor = app(AuditLogger::class);
            if ($disconnecting) {
                $auditor->log($current, 'user.disabled', 'user', (string) $user->id, ['email' => $user->email]);
            } elseif ($reactivating) {
                $auditor->log($current, 'user.reactivated', 'user', (string) $user->id, ['email' => $user->email]);
            } else {
                $auditor->log($current, 'user.updated', 'user', (string) $user->id, ['email' => $user->email, 'role' => $this->role->value]);
            }
        });

        $this->cancel();
        session()->flash('success', __('actions.save'));
    }

    public function resetPassword(): void
    {
        $this->validate([
            'reset_password' => ['required', 'string', Password::min(8), 'confirmed'],
        ]);

        $user = User::findOrFail($this->edit_id);

        // El cambio de la propia contraseña (con o sin perfil) ya existe en
        // /profile; el reseteo admin no puede revocar la sesión con la que
        // se ejecuta el cambio.
        if ($user->id === auth()->id()) {
            session()->flash('error', __('users.error_reset_self'));

            return;
        }

        $current = auth()->user();

        DB::transaction(function () use ($user, $current) {
            $this->lifecycle()->changePassword($user, $this->reset_password);

            app(AuditLogger::class)->log($current, 'user.password_reset', 'user', (string) $user->id, ['email' => $user->email]);
        });

        $this->reset(['reset_password', 'reset_password_confirmation']);
        session()->flash('success', __('users.password_reset_done'));
    }

    public function create(): void
    {
        $this->validate([
            'create_name' => ['required', 'string', 'max:100'],
            'create_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'create_password' => ['required', 'confirmed', Password::min(8)],
            'create_role' => ['required', Rule::in(['user', 'admin'])],
            'create_max_agents' => ['nullable', 'integer', 'min:0', 'max:100'],
            'create_language' => ['required', Rule::in(['es', 'en', 'eu'])],
        ]);

        $current = auth()->user();

        DB::transaction(function () use ($current) {
            $user = User::create([
                'name' => $this->create_name,
                'email' => $this->create_email,
                'password' => $this->create_password,
                'role' => $this->create_role,
                'active' => $this->create_active,
                'locale' => $this->create_language,
                'agent_quota' => $this->create_max_agents,
            ]);

            app(AuditLogger::class)->log($current, 'user.created', 'user', (string) $user->id, ['email' => $user->email, 'role' => $this->create_role]);
        });

        $this->cancel();
        session()->flash('success', __('users.created'));
    }

    public function delete(int $id): void
    {
        if ($id === auth()->id()) {
            session()->flash('error', __('users.error_self_delete'));

            return;
        }

        $user = User::findOrFail($id);
        $current = auth()->user();

        DB::transaction(function () use ($user, $current) {
            $this->lifecycle()->deleteUser($user);
            app(AuditLogger::class)->log($current, 'user.deleted', 'user', (string) $user->id, ['email' => $user->email]);
        });

        $this->cancel();
        session()->flash('success', __('users.deleted'));
    }

    protected function lifecycle(): UserLifecycle
    {
        return app(UserLifecycle::class);
    }

    public function render(): View
    {
        $users = User::withCount('agents')
            ->latest('id')
            ->paginate(20)
            ->appends(request()->except('page'));

        return view('livewire.users.index', [
            'users' => $users,
        ]);
    }
}

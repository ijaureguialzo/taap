<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\UserLifecycle;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $users = User::orderBy('name')->get();

        return response()->json([
            'users' => $users->map(fn (User $u) => $this->userPayload($u)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:user,admin'],
            'active' => ['sometimes', 'boolean'],
            'agent_quota' => ['nullable', 'integer', 'min:0', 'max:100'],
            'locale' => ['nullable', 'in:'.implode(',', config('taap.locales'))],
        ]);

        $current = $request->user();

        $user = DB::transaction(function () use ($data, $current, &$user) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => $data['role'],
                'active' => $data['active'] ?? true,
                'agent_quota' => $data['agent_quota'] ?? null,
                'locale' => $data['locale'] ?? app()->getLocale(),
            ]);

            app(AuditLogger::class)->log($current, 'user.created', 'user', (string) $user->id, ['email' => $user->email, 'role' => $data['role']]);

            return $user;
        });

        return response()->json(['user' => $this->userPayload($user)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'role' => ['required', 'in:user,admin'],
            'active' => ['sometimes', 'boolean'],
            'agent_quota' => ['nullable', 'integer', 'min:0', 'max:100'],
            'locale' => ['nullable', 'in:'.implode(',', config('taap.locales'))],
        ]);

        $current = $request->user();

        // Guardias anti-auto-bloqueo (web y API): el admin no puede
        // deshabilitarse ni degradarse a sí mismo.
        if ($user->id === $current->id) {
            if (array_key_exists('active', $data) && $data['active'] === false) {
                abort(422, 'No puedes deshabilitar tu propia cuenta');
            }
            if ($data['role'] === 'user') {
                abort(422, 'No puedes degradarte a ti mismo');
            }
        }

        $wasActive = $user->active;
        $disconnecting = $user->active && ($data['active'] ?? true) === false;
        $reactivating = ! $user->active && ($data['active'] ?? false) === true;

        DB::transaction(function () use ($user, $current, $disconnecting, $reactivating, $data) {
            if ($disconnecting) {
                app(UserLifecycle::class)->stopAndRevoke($user);
            } elseif ($reactivating) {
                app(UserLifecycle::class)->reactivate($user);
            }

            $user->forceFill([
                'role' => $data['role'],
                'active' => $data['active'] ?? $user->active,
                'agent_quota' => $data['agent_quota'] ?? $user->agent_quota,
                'locale' => $data['locale'] ?? $user->locale,
            ])->save();

            $auditor = app(AuditLogger::class);
            if ($disconnecting) {
                $auditor->log($current, 'user.disabled', 'user', (string) $user->id, ['email' => $user->email]);
            } elseif ($reactivating) {
                $auditor->log($current, 'user.reactivated', 'user', (string) $user->id, ['email' => $user->email]);
            } else {
                $auditor->log($current, 'user.updated', 'user', (string) $user->id, ['email' => $user->email, 'role' => $data['role']]);
            }
        });

        return response()->json(['user' => $this->userPayload($user->fresh())]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->requireAdmin($request);

        if ($user->id === $request->user()->id) {
            abort(422, 'No puedes eliminar tu propia cuenta');
        }

        $current = $request->user();

        DB::transaction(function () use ($user, $current) {
            app(UserLifecycle::class)->deleteUser($user);
            app(AuditLogger::class)->log($current, 'user.deleted', 'user', (string) $user->id, ['email' => $user->email]);
        });

        return response()->json(['ok' => true]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->requireAdmin($request);

        if ($user->id === $request->user()->id) {
            abort(422, 'Usa tu perfil para cambiar tu propia contraseña');
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $current = $request->user();

        DB::transaction(function () use ($user, $current, $data) {
            app(UserLifecycle::class)->changePassword($user, $data['password']);
            app(AuditLogger::class)->log($current, 'user.password_reset', 'user', (string) $user->id, ['email' => $user->email]);
        });

        return response()->json(['ok' => true]);
    }

    protected function requireAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'active' => (bool) $user->active,
            'locale' => $user->locale,
            'agent_quota' => $user->agent_quota,
            'agents' => $user->agents()->count(),
            'running_agents' => $user->agents()->whereNotIn('status', ['stopped', 'offline'])->count(),
        ];
    }
}

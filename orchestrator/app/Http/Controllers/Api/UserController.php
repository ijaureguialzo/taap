<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $users = User::orderBy('name')->get();

        return response()->json([
            'users' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->value,
                'locale' => $u->locale,
                'agent_quota' => $u->agent_quota,
                'agents' => $u->agents()->count(),
                'running_agents' => $u->agents()->whereNotIn('status', ['stopped', 'offline'])->count(),
            ]),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'role' => ['required', 'in:user,admin'],
            'agent_quota' => ['nullable', 'integer', 'min:0', 'max:100'],
            'locale' => ['nullable', 'in:'.implode(',', config('taap.locales'))],
        ]);

        if (($data['role'] ?? null) === 'user' && $user->id === $request->user()->id && ! $request->user()->isAdmin()) {
            abort(422, 'No puedes demotivarte a ti mismo');
        }

        $user->update($data);

        return response()->json(['ok' => true]);
    }

    protected function requireAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }
}

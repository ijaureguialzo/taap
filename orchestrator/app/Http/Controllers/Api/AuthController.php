<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = \App\Models\User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.fails')],
            ]);
        }

        // Regenera el token actual (login recurrente del cliente)
        $user->currentAccessToken()?->delete();
        $token = $user->createToken('taap')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    /** Tokens de API del usuario (para la app iOS). */
    public function tokens(Request $request)
    {
        return response()->json([
            'tokens' => $request->user()->tokens()->orderByDesc('created_at')
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'last_used_at' => $t->last_used_at?->toIso8601String(),
                    'created_at' => $t->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function createToken(Request $request)
    {
        $request->validate(['name' => ['required', 'string', 'max:64']]);
        $token = $request->user()->createToken($request->input('name'))->plainTextToken;

        return response()->json(['token' => $token], 201);
    }

    public function deleteToken(Request $request, int $tokenId)
    {
        $request->user()->tokens()->whereKey($tokenId)->delete();

        return response()->json(['ok' => true]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['required', 'in:' . implode(',', config('taap.locales'))],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();
        $user->name = $data['name'];
        $user->locale = $data['locale'];
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return response()->json(['user' => $this->userPayload($user->refresh())]);
    }

    protected function userPayload(\App\Models\User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'locale' => $user->locale,
            'agent_quota' => $user->agent_quota,
        ];
    }
}

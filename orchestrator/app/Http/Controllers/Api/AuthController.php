<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\UserLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.fails')],
            ]);
        }

        // Regenera el token actual si hubiera (login recurrente del
        // cliente); en un usuario traido de la BD no hay access token.
        $user->currentAccessToken()?->delete();
        $token = $user->createToken('taap')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    /** Tokens de API del usuario (para la app iOS). */
    public function tokens(Request $request): JsonResponse
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

    public function createToken(Request $request): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:64']]);
        $token = $request->user()->createToken($request->input('name'))->plainTextToken;

        return response()->json(['token' => $token], 201);
    }

    public function deleteToken(Request $request, int $tokenId): JsonResponse
    {
        $request->user()->tokens()->whereKey($tokenId)->delete();

        return response()->json(['ok' => true]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['required', 'in:'.implode(',', config('taap.locales'))],
        ];

        if (filled($request->input('password'))) {
            // Cambio de contraseña: contraseña actual obligatoria (spec
            // users-and-roles) y revocación total excepto el token actual.
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
            $rules['current_password'] = ['required', 'string', 'current_password'];
        }

        $data = $request->validate($rules);

        $user = $request->user();
        $user->name = $data['name'];
        $user->locale = $data['locale'];
        $user->save();

        if (filled($data['password'] ?? null)) {
            app(UserLifecycle::class)->changePassword(
                $user,
                $data['password'],
                keepTokenId: $user->currentAccessToken()?->id,
            );
        }

        return response()->json(['user' => $this->userPayload($user->refresh())]);
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
            'locale' => $user->locale,
            'agent_quota' => $user->agent_quota,
        ];
    }
}

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

        if (! $user || ! $user->active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.fails')],
            ]);
        }

        // 2FA (Fortify): el reto lo resuelve el endpoint de challenge; no se
        // emite token hasta que el código se verifique.
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'requires_two_factor' => true,
                'challenge' => $this->challengeToken($user),
            ]);
        }

        // Regenera el token actual si hubiera (login recurrente del
        // cliente); en un usuario traido de la BD no hay access token.
        $user->currentAccessToken()?->delete();
        $token = $user->createToken('taap')->plainTextToken;

        return response()->json([
            'requires_two_factor' => false,
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Challenge 2FA stateless (token firmado por HMAC). No requiere sesión.
     */
    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:512'],
            'code' => ['nullable', 'string', 'max:64'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $this->userForChallenge((string) $data['challenge']);

        if (! $user) {
            return response()->json(['message' => 'Reto 2FA inválido o expirado'], 401);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            // El 2FA dejó de estar habilitado entre el login y el challenge.
            return response()->json(['message' => 'El 2FA no está habilitado para este usuario'], 422);
        }

        $provider = app(\Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider::class);
        $secret = \Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        if (filled($data['recovery_code'] ?? null)) {
            $recoveryCode = collect($user->recoveryCodes())->first(function ($code) use ($data) {
                return hash_equals($code, $data['recovery_code']);
            });

            if (! $recoveryCode) {
                return response()->json(['message' => 'Código de recuperación inválido'], 422);
            }

            $user->replaceRecoveryCode($recoveryCode);
        } elseif (filled($data['code'] ?? null) && $provider->verify($secret, $data['code'])) {
            // Código TOTP válido.
        } else {
            return response()->json(['message' => 'Código 2FA inválido'], 422);
        }

        $user->currentAccessToken()?->delete();
        $token = $user->createToken('taap')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Estado 2FA del usuario autenticado.
     */
    public function twoFactorStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => (bool) ($user->two_factor_secret && $user->two_factor_confirmed_at),
            'setup_pending' => (bool) ($user->two_factor_secret && ! $user->two_factor_confirmed_at),
        ]);
    }

    /**
     * Habilita 2FA (genera secret + recovery codes). Devuelve el secret y los
     * recovery codes UNA SOLA VEZ — no hay segundo endpoint que los muestre.
     */
    public function enableTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            return response()->json([
                'enabled' => true,
                'message' => 'El 2FA ya está habilitado',
            ], 409);
        }

        app(\Laravel\Fortify\Actions\EnableTwoFactorAuthentication::class)($user);
        $user->refresh();
        $secret = \Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        return response()->json([
            'enabled' => false,
            'setup_pending' => true,
            'secret' => $secret,
            'recovery_codes' => $user->recoveryCodes(),
        ]);
    }

    /**
     * Confirma el setup 2FA (POST con código TOTP válido).
     */
    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);

        if (! $user->two_factor_secret) {
            return response()->json(['message' => 'Habilita 2FA antes de confirmarlo'], 422);
        }

        try {
            app(\Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication::class)($user, $data['code']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Código 2FA inválido'], 422);
        }

        return response()->json(['enabled' => true]);
    }

    /**
     * Deshabilita 2FA.
     */
    public function disableTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->two_factor_secret) {
            return response()->json(['enabled' => false]);
        }

        app(\Laravel\Fortify\Actions\DisableTwoFactorAuthentication::class)($user);

        return response()->json(['enabled' => false]);
    }

    /**
     * Lista passkeys del usuario (sin secretos).
     */
    public function passkeys(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'passkeys' => $user->passkeys()->get()->map(fn ($k) => [
                'id' => $k->id,
                'name' => $k->name,
                'last_used_at' => $k->last_used_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Elimina un passkey del usuario autenticado.
     */
    public function deletePasskey(Request $request, int $passkeyId): JsonResponse
    {
        $user = $request->user();
        $passkey = $user->passkeys()->whereKey($passkeyId)->first();

        if (! $passkey) {
            return response()->json(['message' => 'Passkey no encontrado'], 404);
        }

        app(\Laravel\Passkeys\Actions\DeletePasskey::class)($user, $passkey);

        return response()->json(['ok' => true]);
    }

    /**
     * Token de challenge firmado (HMAC) para el flujo 2FA stateless.
     */
    protected function challengeToken(User $user): string
    {
        $expires = now()->addMinutes(5)->getTimestamp();
        $payload = $user->getKey().'.'.$expires;
        $signature = hash_hmac('sha256', $payload, config('app.key'));

        return base64_encode($payload.'.'.$signature);
    }

    protected function userForChallenge(string $challenge): ?User
    {
        try {
            $decoded = base64_decode($challenge, true);

            if ($decoded === false) {
                return null;
            }

            $parts = explode('.', $decoded);

            if (count($parts) !== 3) {
                return null;
            }

            [$userId, $expires, $signature] = $parts;

            if (time() > (int) $expires) {
                return null;
            }

            $payload = $userId.'.'.$expires;

            if (! hash_equals(hash_hmac('sha256', $payload, config('app.key')), $signature)) {
                return null;
            }

            return User::whereKey($userId)->where('active', true)->first();
        } catch (\Throwable) {
            return null;
        }
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

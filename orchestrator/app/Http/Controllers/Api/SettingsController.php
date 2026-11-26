<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        return response()->json([
            'registration_enabled' => app(Settings::class)->isRegistrationEnabled(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'registration_enabled' => ['required', 'boolean'],
        ]);

        app(Settings::class)->setRegistrationEnabled((bool) $data['registration_enabled']);

        return response()->json([
            'registration_enabled' => (bool) $data['registration_enabled'],
        ]);
    }

    protected function requireAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }
}

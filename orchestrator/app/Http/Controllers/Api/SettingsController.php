<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $settings = app(Settings::class);

        return response()->json([
            'registration_enabled' => $settings->isRegistrationEnabled(),
            'failure_webhook_url' => $settings->failureWebhookUrl(),
            'failure_webhook_configured' => (bool) $settings->failureWebhookUrl(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'registration_enabled' => ['sometimes', 'boolean'],
            'failure_webhook_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'failure_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $current = $request->user();

        DB::transaction(function () use ($data, $current) {
            $settings = app(Settings::class);

            if (array_key_exists('registration_enabled', $data)) {
                $settings->setRegistrationEnabled((bool) $data['registration_enabled']);
                app(AuditLogger::class)->log($current, 'registration.toggled', 'settings', null, ['enabled' => (bool) $data['registration_enabled']]);
            }

            if (array_key_exists('failure_webhook_url', $data)) {
                $settings->setFailureWebhookUrl($data['failure_webhook_url']);
                app(AuditLogger::class)->log($current, 'webhook.configured', 'settings', null, ['url' => $data['failure_webhook_url']]);
            }

            if (array_key_exists('failure_webhook_secret', $data)) {
                $settings->setFailureWebhookSecret($data['failure_webhook_secret']);
                // El secreto NUNCA va al log de auditoría.
                app(AuditLogger::class)->log($current, 'webhook.secret_updated', 'settings', null, ['secret_set' => filled($data['failure_webhook_secret'])]);
            }
        });

        return response()->json([
            'registration_enabled' => app(Settings::class)->isRegistrationEnabled(),
            'failure_webhook_url' => app(Settings::class)->failureWebhookUrl(),
            'failure_webhook_configured' => (bool) app(Settings::class)->failureWebhookUrl(),
        ]);
    }

    protected function requireAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }
}

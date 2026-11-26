<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Configuración persistida en la tabla `settings` (clave -> valor).
 * La fuente de verdad es la BD; los defaults aquí resuelven las claves
 * que aun no existen (p. ej. un despliegue anterior a la migración),
 * de modo que el sistema nunca depende de una fila presente.
 */
class Settings
{
    /** Valores por defecto para claves aún no persistidas. */
    protected static array $defaults = [
        'registration_enabled' => 'true',
    ];

    public function get(string $key): ?string
    {
        $value = DB::table('settings')->where('key', $key)->value('value');

        if ($value === null) {
            return self::$defaults[$key] ?? null;
        }

        return $value;
    }

    public function set(string $key, ?string $value): void
    {
        if ($value === null) {
            DB::table('settings')->where('key', $key)->delete();

            return;
        }

        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()]
        );
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $raw = $this->get($key);

        if ($raw === null) {
            return $default;
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }

    public function isRegistrationEnabled(): bool
    {
        return $this->boolean('registration_enabled', true);
    }

    public function setRegistrationEnabled(bool $enabled): void
    {
        $this->set('registration_enabled', $enabled ? 'true' : 'false');
    }

    /** Webhook saliente de fallo de agente (agent-failure-notifications). */
    public function failureWebhookUrl(): ?string
    {
        $url = $this->get('failure_webhook_url');

        return filled($url) ? $url : null;
    }

    public function setFailureWebhookUrl(?string $url): void
    {
        $this->set('failure_webhook_url', filled($url) ? trim((string) $url) : null);
    }

    public function failureWebhookSecret(): ?string
    {
        $raw = $this->get('failure_webhook_secret');

        if (! filled($raw)) {
            return null;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setFailureWebhookSecret(?string $secret): void
    {
        if (! filled($secret)) {
            $this->set('failure_webhook_secret', null);

            return;
        }

        $this->set('failure_webhook_secret', Crypt::encryptString((string) $secret));
    }

    public function storeLastNotification(string $agentId, string $status, ?int $httpCode, ?string $error): void
    {
        $this->set('failure_notification_last_'.$agentId, json_encode([
            'status' => $status,
            'http_code' => $httpCode,
            'error' => $error,
            'at' => now()->toIso8601String(),
        ]));
    }

    /** @return array{status: string, http_code: ?int, error: ?string, at: ?string}|null */
    public function lastNotification(string $agentId): ?array
    {
        $raw = $this->get('failure_notification_last_'.$agentId);

        if (! filled($raw)) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}

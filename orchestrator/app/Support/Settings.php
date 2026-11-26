<?php

namespace App\Support;

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
}

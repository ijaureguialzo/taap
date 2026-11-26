# Proposal: 2FA y passkeys

## Why
TAAP autentica con email + contraseña local y expone cuentas admin/usuario con un panel de gestión (usuarios, proveedores, modelos, MCP, agentes). La autenticación actual es el único eslabón sin segundo factor. El scaffolding de 2FA ya está presente pero sin cablear: columnas `two_factor_secret` / `two_factor_recovery_codes` / `two_factor_confirmed_at` en la tabla de usuarios, tabla `passkeys`, rate limiter de passkeys (`passkeys`, 10/min) y la vista `pages/auth/two-factor-challenge`. Además, `config/fortify.php` lo pospuso deliberadamente ("v1 (design D10): solo login + registro... sin 2FA/passkeys") y `FortifyServiceProvider` nunca registra esos features. Este cambio activa esa superficie oculta.

## What Changes
- Registrar los features de 2FA (TOTP) y passkeys (WebAuthn) en Fortify y en `FortifyServiceProvider`, manteniendo el login manual de `LoginController` con el challenge de 2FA tras la contraseña correcta.
- Perfil (web y iOS): habilitar/deshabilitar 2FA con recovery codes, y registrar/eliminar passkeys.
- Login por passkey sobre Web (sin tocar la app iOS nativa en esta iteración).
- El enforcement obligatorio de 2FA para admins queda como decisión de diseño, no se compromete en esta fase.

**Out of scope:** WebAuthn/passkeys nativos en la app iOS (requieren la lógica de credenciales en Swift); enforcement de 2FA por rol (design); envío de email (no hay infra SMTP; los recovery codes se muestran una sola vez, como en Fortify).

## Capabilities

### New Capabilities
*(ninguna)*

### Modified Capabilities
- `users-and-roles`: la autenticación local pasa a incluir 2FA por usuario (TOTP y passkeys), gestionado desde el perfil. La nota "solo login" del design D10 se refina a 2FA habilitable.

## Impact
- `orchestrator/config/fortify.php` (quitar el pospuesto D10), `FortifyServiceProvider`, modelo `User` (two_factor_*), `Livewire/Profile`, rutas web/auth y API v1 de perfil.
- iOS: challenge de 2FA en el flujo de login y panel de perfil (gestión de factores); passkeys nativas fuera de esta iteración.
- Sin migraciones nuevas: las columnas y la tabla `passkeys` ya existen.
- Tests de auth: login con 2FA, recovery codes, challenge en la API.

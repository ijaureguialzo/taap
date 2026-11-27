# Design: 2FA y passkeys

## Context
Ver proposal.md — Why. Estado actual: Fortify solo registra `registration`; las columnas `two_factor_secret`/`two_factor_recovery_codes`/`two_factor_confirmed_at` y la tabla `passkeys` ya existen (migraciones de Laravel), el rate limiter `passkeys` (10/min) y la vista `pages/auth/two-factor-challenge` ya están en el repo, pero `FortifyServiceProvider` nunca las registra y `LoginController` es manual (email + password, sin challenge). Sin SMTP (los recovery codes se muestran una sola vez en la UI, como siempre en Fortify).

## Goals / Non-Goals
**Goals:**
- 2FA TOTP habilitable por usuario (web + API v1 + iOS login), gestión completa en el perfil.
- Login por passkey sobre Web (WebAuthn).

**Non-Goals:**
- Enforcement obligatorio de 2FA para admins (decisión diferida a otra fase; ver Risks).
- Passkeys nativos en SwiftUI (iOS): la app iOS mantiene login challenge TOTP vía API; passkeys web-only en esta iteración.
- Email/reset de contraseña (sin SMTP — no forma parte del diseño).

## Decisions

1. **Fortify features: `Features::twoFactorAuthentication()` y `Features::passkeys()`** en `config/fortify.php`, registrados en `FortifyServiceProvider` (extender el `configureActions` existente). Rationale: Fortify ya trae el flujo completo (QR code, confirmación, recovery codes de un solo uso, rate limiters, encrypted-at-rest secrets). Alternatives: implementar TOTP a mano (rechazado: duplicaría superficie sin ganancia) — y cuidado con el API exacto de la versión instalada, se verifica contra el package durante la implementación.
2. **Login manual reutilizado, con el challenge de Fortify**: `LoginController` conserva su flujo (validation manual, `Auth::login`), pero tras la contraseña comprobada: si el usuario tiene 2FA habilitado, inicia sesión en modo "desafío" (usuario en sesión `login.*` — el mismo patrón de Fortify) y redirige a `/two-factor-challenge` en vez de a `agents/index`. Fortify expone el endpoint del reto; web y API reutilizan la misma lógica y rate limiting (`login`/`two-factor`).
3. **API v1 de login**: responde 200 con `{ requires_two_factor: true, csrf_token }` cuando el desafío aplica; tras el desafío correcto, el token Sanctum se emite igual que hoy. La app iOS detecta la señal y presenta el challenge sheet (código TOTP).
4. **Enforcement no obligatorio**: 2FA es opt-in por usuario. Rationale: un push sin per-usuario 2FA bloquearía admins existentes; el diseño queda listo para que una futura fase lo exija para admins sin rediseñar (control de red en el mismo punto).
5. **Recovery codes**: los que genera Fortify (10, de un solo uso), visibles una sola vez al habilitar TOTP; al regenerar, invalida el set anterior.
6. **Passkeys**: registrar/listar/eliminar vía endpoints Fortify (`passkeys/register`, `passkeys/destroy`, `passkeys` list) + paridad en API v1 (`profile`) — identidad WebAuthn (Credential Management API); iOS nativo fuera de alcance.
7. **iOS**: el flujo de login (`LoginView`, `ApiClient`) muestra un sheet de challenge cuando la API responde `requires_two_factor`; TOTP es consistente con la API (código 6 dígitos).

## Risks / Trade-offs
- [Fortify no sabe que el login la está llamando desde un controlador manual] → mitigado: el challenge se comparte con la misma sesión/redirects de Fortify; la lógica crítica (validación TOTP, single-use de codes) vive en Fortify, no en el controlador.
- [iOS vieja versión contra un login que ahora responde 2FA] → aceptable: es un login nuevo; el error 2FA es explícito y la app se actualiza con el servicio.
- [Usuarios antiguos sin 2FA] → sin enforce: login sin reto (el 2FA es opt-in); la sesión existente no se invalida.
- [Passkeys en Web dependen de WebAuthn en el navegador] → explicitar interfaz con fallback al reto TOTP; iOS nativo se declara fuera de alcance (es un spec).

## Migration Plan
- Sin migraciones de datos (columnas y tablas existentes).
- Rollout: feature flag-less — Fortify feature inactiva hasta el código mergeed; el despliegue aplica el merge (las ventanas de login cambian solo para usuarios con 2FA).
- Rollback: volver a `config/fortify.php` inicial y revertir los endpoints (no hay migración de datos que revertir).

## Open Questions
Ninguna diferible: todo lo abierto afecta al alcance, y está resuelto (2FA opt-in; enforcement admins diferido conscientemente).

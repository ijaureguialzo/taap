# Design

## Context

Ver proposal.md para el porqué. Estado actual:

- Panel de usuarios (`App\Livewire\Users\Index`): lista con editor inline (rol/activo/cupo/idioma) y delete. Sin crear, sin resetear contraseña, sin guardia anti-auto-bloqueo. El delete es hard (no hay `SoftDeletes` en `User`) y cascatea agentes vía FK.
- API usuarios (`Api\UserController`): `index` + `update` (rol/cuota/idioma) con `requireAdmin`. Sin crear, sin delete, sin `active`, sin resetear. `Api\AuthController@updateProfile`: nombre/idioma/contraseña sin exigir contraseña actual.
- Registro: rutas custom (`routes/web.php`) en grupo `guest`, welcome hardcodea el link de registro; nada persistente ni variable.
- Perfil: ruta plana `pages/profile/language` + POST `/profile/language` (sin Livewire). `routes/settings.php` es código muerto de Breeze (no se carga en `bootstrap/app.php`; referencia componentes inexistentes).
- Sesión y tokens: sesiones en base de datos (`config/session.php`, `driver: database`), Sanctum tokena la app iOS. Ninguno se valida contra el estado de `active` más allá del login (rechaza inactivo); las sesiones ya abiertas de un usuario dado de baja siguen vivas.
- Agentes: `AgentManager::stop/start` (idempotentes); `AgentStatus::isRunning()` = no offline ni stopped. Baja de agente = set status a `stopped`.

## Goals / Non-Goals

**Goals:**
- Gestión admin completa en web y API (crear, editar, borrar, resetear contraseña, baja/reactivación con sesiones/tokens/agentes, toggle de registro).
- Perfil completo de usuario en web (nombre, email readonly, idioma, contraseña con actual).
- Toggle persistido en runtime, reflejo en home y `/register`, con superficie de API admin.

**Non-Goals:**
- Cambiar email (solo lectura). Foto de perfil. 2FA/passkeys/recuperación por email. Cambio de propiedad de agentes. Hardening adicional de delete (soft delete / retención): fuera de alcance. iOS: sin cambios (no usa `/auth/profile`, `/auth/tokens` ni `/users` hoy).

## Decisions

### 1. Toggle de registro: tabla `settings` key/value
`settings(key PK, value, timestamps)`, seed `registration_enabled = 'true'`. Es la única fuente de verdad para conmutaciones en runtime: sobrevive reinicios, no se necesita re-deploy. Lecturas servidas por un servicio pequeño (`App\Support\Settings`) con fallback al valor por defecto del esquema (`true`). Alternativa rechazada: env var (requiere edición + reinicio), config en archivo (no editable en caliente), jalón en la tabla users/agents (no es una propiedad de usuario).

### 2. Ciclo de vida de usuario en un servicio único (`App\Support\UserLifecycle`)
Un servicio para que web y API compartan exactamente la misma semántica, en vez de duplicar logica de sesiones/agentes en cada superficie. Métodos:
- `stopAndRevoke(User $user, ?int $keepTokenId = null)` — detiene agentes no-stopped/offline (`AgentManager::stop`), borra sesiones web (`sessions` donde `user_id`), borra tokens Sanctum (excepto `keepTokenId`), marca `active = false`.
- `reactivate(User $user)` — marca `active = true` y arranca agentes (`AgentManager::start`).
- `changePassword(User $user, string $newPassword, ?int $keepTokenId = null, ?string $keepSessionId = null)` — asigna contraseña y revoca sesiones/tokens del usuario conservando `keep*` (no revoca la sesión con la que se ejecuta el cambio).
- `deleteUser(User $user)` — `stopAndRevoke` (sin keep) + delete.
Web llama con `keepTokenId=null`, `keepSessionId` derivado de la sesión actual; API con `keepTokenId=$request->user()->currentAccessToken()->id`.

Alternativa rechazada: invocar desde controladores/Livewire a mano (inconsistencia web/API); eventos + listeners (indirection innecesaria para un servicio de un objeto).

### 3. Guardas anti-auto-bloqueo (web y API)
Al editar (role/active), borrar o resetear contraseña propia: si el target es el admin autenticado y la acción es deshabilitarse, degradarse o borrarse → 422 con mensaje claro y sin cambios. Guarda en el Livewire component (web) y en ambos controladores (API). Nunca implementado hoy; es el punto de falla que más se introduce con la paridad.

### 4. Perfil web: Livewire component `App\Livewire\Profile`
Página `/profile` (Livewire, `livewire/profile/index`) reproduciendo el patrón del resto de la app: nombre (editable), email (readonly), locale (select), sección de contraseña (contraseña actual + nueva + confirmación, validadas con `Password::default()` + `currentPasswordRules` del concern existente). Ruta `profile.edit`; `/profile/language` se elimina y los tests `LanguageTest` se actualizan. El dropdown del navbar enlaza al perfil.
Alternativa rechazada: formulario Blade plano (reutilizando el idioma de hoy) — no crece bien y duplica la lógica de validación; Livewire es el patrón del proyecto.

### 5. Toggle de registro: tarjeta en la página de usuarios del admin
Segunda sección del `Users/Index` (misma página): tarjeta con switch (`registration_enabled`) + guardar. Nuevo Livewire component `Admin\RegistrationToggle` incluido ahí para que la lógica sea única y testeable sin acoplar visualmente el panel. `welcome.blade.php` lee el setting y oculta el link; `/register` (GET y POST) pasa por un middleware `EnsureRegistrationEnabled` → redirect a home con mensaje claro. La home SHALL seguir enlazando login siempre.

### 6. API admin: `UserController` con paridad completa
- `GET /users` (ya existe) — añade `active` y tipo de agente running.
- `POST /users` — crear (nombre/email/password/rol/activo/cupo/idioma). Email único; rol enum.
- `PUT /users/{user}` — amplía con `active` (bool) y guarda el resto.
- `DELETE /users/{user}` — borra con `deleteUser` lifecycle.
- `POST /users/{user}/password` — resetea contraseña con revocación total.
- `GET /settings` / `PUT /settings` — `{ registration_enabled: bool }`, `adminOnly`, same as card.
- Perfil: `PUT /auth/profile` — añade `current_password` requerido cuando `password` viene; revoca sesiones/tokens excepto el token actual. **BREAKING** (superficie; iOS no llama esta ruta hoy).
Responses: 201/200 con el user payload ya existente; 422 con errores de validación; 403 no-admin; 422 guardas anti-auto-bloqueo.

Alternativa rechazada: endpoint de toggle por esquina (`PUT /users/registration`) — agrega rutas; settings es genérico y futuro-proof.

### 7. Home / registro cerrado
`/register` redirige a home (no muestra página de error). Mensaje vía sesión flash, que ya pinta el layout. No requiere layout nuevo.

### 8. Limpieza
Borrar `routes/settings.php` (muerto). Borrar `profile/language` + vista + tests obsoletos. Sin mover nada más de Breeze (confirmPassword, etc. quedan intactos).

## Risks / Trade-offs

- [Agentes detenidos manualmente por el usuario y reactivación del usuario] → reactivar arranca TODOS los agentes del usuario, incluidos los que el usuario detuvo a mano. v1: documentado; seguimiento con marcador por-agente (`stopped_by_disable`) si el comportamiento molesta. La spec no distingue; la implementación documenta la asunción.
- [`settings` sin caché (query por request)] → bajo volumen (home, register, panel); si crece, añadir caché corto.
- [Borrado hard con cascade] → se conserva (no hay `SoftDeletes` en User); se hace `stopAndRevoke` antes del delete y se avisa en la confirmación de que borrar el usuario borra sus agentes vía FK.
- [`current_password` en API de perfil] → iOS no llama esta ruta hoy; si algún día lo hace, necesita enviar la contraseña actual (documentado en el design de la implementación).
- [Keep-session-on-self-change] → si el usuario cambia su contraseña, la sesión abre queda activa (así no se desloguea a sí mismo); las demás sesiones y tokens reciben revocación. Es el comportamiento estándar de "cambio de contraseña".
- [Toggle de registro en DB vs .env] → el estado vive en BD; si alguien prefiere el default por defecto en config, el fallback del servicio lo cubre.

## Migration Plan

1. Migración `settings` + seed `registration_enabled=true` (default conservador; sin toggles activados por accidente).
2. Servicio `UserLifecycle` + `Settings`.
3. Web (perfil, panel, toggle) y API.
4. Tests.
Despliegue: `migrate` antes de subir el código (orden: migración → código). Rollback: borrar las rutas nuevas y el componente (el toggle vuelve a ser visible con el seeder y el default).

## Open Questions

- Ninguna bloqueante. El marcador por-agente para "detenido por baja vs detenido a mano" queda documentado como seguimiento, no como bloqueo de este cambio.

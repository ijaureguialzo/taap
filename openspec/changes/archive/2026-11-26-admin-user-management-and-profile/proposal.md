# Proposal

## Why

La gestión de usuarios está a medias entre la spec y la realidad: el panel admin puede editar rol/activo/cupo/idioma y borrar, pero **no puede crear usuarios ni resetear contraseñas** — y la spec `users-and-roles` ya las exige ("crear, deshabilitar, reactivar, cambiar el rol y borrar" + "el admin SHALL poder restablecer la contraseña de cualquier usuario"). Además, desactivar un usuario hoy **no invalida sus sesiones/tokens ni para sus agentes**, aunque la spec manda exactamente eso.

Falta además todo el lado del usuario: no hay página de perfil en web (solo un formulario de idioma), y no hay toggle de registro — la home siempre enlaza al registro y `/register` siempre está abierto. La spec `users-and-roles` describe la intención; el código no la concreta ni la respeta en sus detalles.

## What Changes

- **Admin (web)**: crear usuarios (nombre, email, rol, cupo, idioma, activo); editar (rol, activo, cupo, idioma; email de solo lectura); resetear contraseña de cualquier usuario (que revoca sesiones y tokens del usuario afectado); borrar (con la sesión/tokens revocados y agentes parados antes del delete).
- **Borrado/baja/reactivación**: desactivar invalida de inmediato sesión web, sesiones y tokens Sanctum, y detiene todos los agentes del usuario; reactivar reanuda los agentes. Click: esta es la parte de la spec que hoy no existe en ninguna superficie.
- **Guardias anti-auto-bloqueo**: el admin no puede deshabilitarse, degradarse ni borrarse a sí mismo (ya existe el guard de borrar self; se extiende).
- **Registro por toggle**: nuevo setting persistido en BD, conmutado desde una tarjeta en la página de usuarios del admin. La home y la página de login ocultan el link de registro cuando está cerrado, y `/register` es rechazado (redirect a home con mensaje).
- **Perfil completo de usuario (web)**: nueva página `/profile` (nombre, email de solo lectura, idioma, cambiar contraseña requiriendo la contraseña actual) que reemplaza `/profile/language`; dropdown del navbar enlaza al perfil.
- **Paridad web/API**: lo que el panel admin hace, la API lo hace — crear (`POST /users`), editar (`PUT /users/{user}` con `active`), borrar (`DELETE /users/{user}`), resetear contraseña (`POST /users/{user}/password`), toggle de registro (`GET/PUT /settings`), baja/reactivación con sesiones/tokens/agentes — y el perfil (`PUT /auth/profile`) ahora exige la contraseña actual al cambiarla.
- **Limpieza**: eliminar `routes/settings.php` (código muerto de Breeze; referencias a pantallas que no existen).

**BREAKING** (superficie, no contrato de negocio): el cambio de contraseña en `PUT /auth/profile` exige `current_password` — la app iOS no usa esa ruta hoy, así que nadie se rompe; se documenta en el design.

## Capabilities

### New Capabilities
<!-- Ninguna. -->

### Modified Capabilities
- `users-and-roles`: la spec ya describe gran parte de esto (crear, deshabilitar con sesiones/agentes, resetear contraseña) — se implementa, no se reescribe. Cambian: (a) nueva exigencia — usuario edita su perfil completo en web (nombre, idioma, contraseña con actual; email de solo lectura); (b) nueva — toggle de registro con la home, la página de login y `/register` afectados; (c) nueva — paridad API completa de gestión de usuarios (crear/editar/borrar/resetear/toggle); (d) modificación — cambio de contraseña propio exige la contraseña actual en web y API; (e) nueva — guardián anti-auto-bloqueo para el admin.

## Impact

- Código afectado: `orchestrator/app/Livewire/Users/Index.php` y vista, nuevo `orchestrator/app/Livewire/Profile.php` (+ vista), `app/Http/Controllers/Api/UserController.php`, `app/Http/Controllers/Api/AuthController.php`, `app/Models/User.php`, `routes/web.php` + `routes/api.php`, `welcome.blade.php`, `lang/*`, tests, migración nueva (`settings`).
- Sin nuevas dependencias; sin cambios en agentes, runtimes ni iOS (la app iOS no usa `/auth/profile`, `/auth/tokens` ni `/users` hoy).
- Tests a actualizar: `LanguageTest`, `RegistrationTest`; tests nuevos para gestión admin, perfil, toggle y API.

# Tasks

## 1. Database & services

- [x] 1.1 Create migration `settings` (key PK, value, timestamps) + seed `registration_enabled=true`; verify `php artisan migrate` runs clean on a fresh DB
- [x] 1.2 Add `App\Support\Settings` (get/set con fallback a default) + unit test (default, stored value, persist); verify `php artisan test --filter=SettingsTest`
- [x] 1.3 Add `App\Support\UserLifecycle` lifecycle service (`stopAndRevoke`, `reactivate`, `changePassword` con keep*, `deleteUser`) + tests covering agentes detenidos/reanudados, sesiones y tokens revocados, keepTokenId/keepSessionId; verify `php artisan test --filter=UserLifecycleTest`

## 2. Web — gestión admin (panel de usuarios)

- [x] 2.1 Add "Nuevo usuario" form + validation (nombre/email/rol/activo/cupo/idioma/contraseña) + create action using lifecycle; verify tests: happy path, email duplicado, rol inválido
- [x] 2.2 Extend edit form: anti-auto-bloqueo guards (no deshabilitar/degradar/borrar self) + guardas visibles en 422; verify tests for all three guards
- [x] 2.3 Disable/reactivate through the edit form uses `UserLifecycle` (sesiones+tokens+agentes) + reactivación arranca agentes; verify tests (disable → sessions/tokens/agents, reactivate → started)
- [x] 2.4 Reset password action in the edit card (nueva + confirmación) con revocación total; verify tests
- [x] 2.5 Delete through `UserLifecycle` (agentes detenidos, sesiones/tokens revocados, hard delete con cascade avisado); verify tests

## 3. Web — toggle de registro

- [x] 3.1 Registration toggle card in users page (`Admin\RegistrationToggle` Livewire, switch + guardar); verify test: toggle persists in DB
- [x] 3.2 Welcome hides the register link when disabled; `/register` GET/POST gated via middleware → redirect a home con mensaje; verify tests
- [x] 3.3 Registration remains open by default (home + register visible); verify test

## 4. Web — perfil completo

- [x] 4.1 Add `Livewire/Profile` page `/profile` (nombre, email readonly, idioma, contraseña con actual, revocación con keep*) + navbar link; verify tests: edit name/locale, password wrong-actual rejected, password change applied, email readonly
- [x] 4.2 Remove `/profile/language` (route, vista, tests LanguageTest) → replaced by profile tests; verify `php artisan test` no contiene LanguageTest y la página existe

## 5. API — paridad completa de gestión de usuarios

- [x] 5.1 `POST /users` (crear: nombre/email/password/rol/activo/cupo/idioma) y guardas; verify tests: 201 happy path, 422 invalid, email duplicado
- [x] 5.2 `PUT /users/{user}` ampliado con `active` (bool) + lifecycle en baja/reactivación; verify tests: active true→false (revoca sesiones/tokens, para agentes) y true→... restore
- [x] 5.3 `DELETE /users/{user}` con lifecycle + guards; verify tests: delete revoca + detiene agentes, self → 422
- [x] 5.4 `POST /users/{user}/password` (resetea + revoca sesiones/tokens); verify tests: 200 + revocación, validación 422
- [x] 5.5 `GET /settings` + `PUT /settings` (admin, `{ registration_enabled }`); verify tests: 200 admin, 403 no-admin, toggle delata home/register
- [x] 5.6 `PUT /auth/profile`: contraseña con `current_password` obligatorio + revocación con keep token actual; verify tests

## 6. Limpieza & documentación

- [x] 6.1 Delete dead `routes/settings.php` (Breeze) + confirm no references (`grep` + `php artisan route:list`)
- [x] 6.2 Extend `lang/es|en|eu` (profile, create user, password reset, registration toggle); verify `php artisan test` passing + strings render
- [x] 6.3 Run the full suite (`php artisan test`) and `php artisan route:list`; verify green and no stale/dead route references (language page, settings.php)

## Workflow follow-up

- Apply changes in dependency order; create Conventional Commits per the project's apply guidance (`tipo(alcance): descripcion` + LLM co-authoring trailer).
- Archive after review.

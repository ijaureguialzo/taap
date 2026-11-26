# Tasks

## 1. Server — Fortify config

- [x] 1.1 Habilitar `Features::twoFactorAuthentication()` y `Features::passkeys()` en `config/fortify.php` y registrarlos en `FortifyServiceProvider`; verificar que `php artisan config:clear` y `php artisan test` (auth) pasan
- [x] 1.2 Extender `LoginController`: tras contraseña correcta, si el usuario tiene 2FA habilitado inicia sesión en modo desafío y redirige a `/two-factor-challenge`; reusar rate limiting `login`/`two-factor`; verificar con feature test que login sin 2FA responde directo y con 2FA redirige al challenge

## 2. Profile + API v1

- [x] 2.1 Perfil web (`Livewire/Profile`): habilitar/deshabilitar TOTP (con recovery codes de un solo uso visibles una sola vez) y gestión de passkeys (registrar/eliminar); verificar con feature test que el habilitar cambia el reto del próximo login
- [x] 2.2 API v1 perfil: endpoints para habilitar/deshabilitar 2FA y listar/eliminar passkeys, con recovery codes enmascarados; verificar con API test que un usuario habilitado no inicia sesión sin el reto TOTP

## 3. iOS — paridad de login

- [x] 3.1 `LoginView`/`ApiClient`: cuando la API responde `requires_two_factor`, mostrar el sheet de challenge (código TOTP) y reintentar el login con el código; verificar con unit test/flow simple del flujo 2FA

## 4. Verificación final

- [ ] 4.1 Verificar suite completa (`php artisan test` + iOS unit tests) y actualización de `docs/` (perfil/logout/2FA en 3 idiomas); verificar con `git status` limpio y e2e opcional en staging

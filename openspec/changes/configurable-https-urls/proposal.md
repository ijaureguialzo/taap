# Proposal

## Why

En el despliegue TLS (Traefik delante), la web se sirve sobre https, pero `APP_URL` apunta a la URL interna del orquestador con esquema `http` (p. ej. `http://127.0.0.1:8000`; la usan los agentes y es la cara interna de la app). La lógica actual de `AppServiceProvider` (`URL::forceHttps(...)`, corregida en `2f56d5b` para no romper el dev local) se deriva **solo** del esquema de `APP_URL`: como esa URL es `http`, las URLs que genera la aplicación —assets de Vite vía `@vite`, redirects, `url()`/`asset()`— salen con `http`. El navegador las bloquea como contenido mixto sobre la página https y **el CSS/JS no carga**. El parche anterior que forzaba https por `APP_ENV=production` se corrigió porque rompía el desarrollo local en Docker, y desde entonces no hay manera de forzar el esquema por entorno sin tocar código.

## What Changes

- Nueva variable de entorno `TAAP_FORCE_HTTPS` en el orquestador, tri-estado, que controla el esquema de las URLs que la aplicación genera:
  - `true`: todas las URLs generadas usan https (assets, redirects, `url()`/`asset()`).
  - `false`: ninguna usa https.
  - No definida: comportamiento actual — el esquema se deriva de `APP_URL` (https solo si `APP_URL` es https). El dev local y los despliegues sin TLS no cambian.
- La decisión se expone como `config/taap.php → force_https` (robusto frente a `config:cache`) y el resolver vive en un helper puro testeable.
- `deploy/env.example` y `deploy/.env` la activan (`true`, el deploy de desarrollo usa Traefik/TLS); `orchestrator/.env.example` la documenta comentada; `docs/deployment.md` explica los tres estados.
- Tests: unitarios del resolver (3 estados) y feature de la aplicación por el provider sobre `url()`.
- Fuera de alcance: configuración del *host* (solo el esquema; el host sigue viniendo de la request / `APP_URL`), el endpoint WSS público (ya resuelto con `TAAP_PUBLIC_SCHEME`) y las URLs internas de agentes (`TAAP_ORCHESTRATOR_URL`).

## Capabilities

### New Capabilities

- `web-deployment`: el esquema (http/https) de las URLs que la aplicación web genera —assets, redirects, URLs absolutas— es configurable por entorno vía `TAAP_FORCE_HTTPS`, con derivación por defecto de `APP_URL`, de modo que una instalación tras proxy TLS sirva sus assets sin contenido mixto.

### Modified Capabilities

(ninguna)

## Impact

- **Orquestador**: `config/taap.php` (clave `force_https`), `app/Providers/AppServiceProvider` (consume la config en `boot()`), nuevo `app/Support/ForceHttps` (resolver tri-estado puro), tests `tests/Unit/ForceHttpsTest.php` y `tests/Feature/Providers/ForceHttpsTest.php`.
- **Deploy**: `deploy/env.example` + `deploy/.env` (`TAAP_FORCE_HTTPS=true` con nota), `orchestrator/.env.example`.
- **Docs**: `docs/deployment.md` (sección de endpoints/solución de problemas).
- **Sin cambios**: runtimes de agentes, API v1, Reverb/WSS (`TAAP_PUBLIC_SCHEME`), app iOS, `shared-filesystem`.

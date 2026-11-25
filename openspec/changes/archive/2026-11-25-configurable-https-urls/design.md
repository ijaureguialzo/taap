# Design

## Context

Ver `proposal.md` para la motivación. Restricciones del estado actual que condicionan el diseño:

- La web (Livewire) carga sus assets con `@vite([...])` en `resources/views/layouts/{app,auth}.blade.php`; `@vite` construye las URLs vía `url()`, que respeta `URL::forceHttps()` y, en caso contrario, el esquema de `APP_URL`.
- Histórico de la línea en `AppServiceProvider::boot()`: `b97d023` (23 nov) `forceHttps(isProduction())` → `2f56d5b` (24 nov) `forceHttps(str_starts_with(APP_URL, 'https://'))`. El segundo arreglaba el dev local en Docker (`APP_ENV=production` + `APP_URL=http` → redirects a https que el `php -S` de dev rechazaba, `ERR_CONNECTION_CLOSED`) pero dejaba sin salida al deploy TLS: `APP_URL` es la URL **interna** (http) que usan los agentes, mientras la web se sirve https por Traefik → assets `http://` → contenido mixto → CSS/JS no cargan.
- Ya existe el patrón de "variable de esquema por cliente público": `TAAP_PUBLIC_SCHEME` (`config/taap.php → public.scheme`) resuelve exactamente el mismo problema para el endpoint WSS de Reverb.
- El deploy no ejecuta `config:cache` (entrypoint: `storage:link` + `migrate` + procesos), pero el diseño debe ser robusto si se añade.

## Goals / Non-Goals

**Goals:**
- Un único control por entorno (`TAAP_FORCE_HTTPS`) con semántica tri-estado clara y predecible.
- Comportamiento por defecto invariado: dev local y despliegues sin TLS siguen exactamente igual (no repetir el error de `b97d023`).
- Testeable sin manipular el env global del proceso: resolver puro (unit) + aplicación real del provider (feature).

**Non-Goals:**
- Configurar el *host* (solo el esquema; el host sigue viniendo de la request / `APP_URL`).
- Endpoint WSS público (ya resuelto por `TAAP_PUBLIC_SCHEME`).
- URLs internas de agentes/runtime (`TAAP_ORCHESTRATOR_URL`, `TAAP_PROXY_INTERNAL_URL`).
- Esquema en emails o en los assets de la app iOS (la iOS usa el contrato de API, no `@vite`).

## Decisions

### D1. Tri-estado: valor explícito manda; sin definir = derivación actual
`true` → fuerza https; `false` → fuerza http; `null` (no definida) → `str_starts_with(APP_URL, 'https://')` (el estado actual de `2f56d5b`, que no cambia). Alternativas descartadas: (a) revivir `forceHttps(isProduction())` — rompía el dev local en Docker, ya pagado en `2f56d5b`; (b) nombre `APP_FORCE_HTTPS` al estilo Laravel — la convención del proyecto para knobs propios es `TAAP_*` (`TAAP_PUBLIC_*`, `TAAP_FS_*`, `TAAP_DEFAULT_LOCALE`); `APP_*` lo reservamos para el núcleo de Laravel.

### D2. La variable entra por config, no por `env()` suelto en el provider
`config/taap.php` gana `force_https => env('TAAP_FORCE_HTTPS')` (nulo si no se define) y el provider consume `config('taap.force_https')`. Laravel `env()` ya castea `true/false/1/0/yes/no/on/off` a bool y el valor vacío a `null`: el tri-estado llega limpio desde el fichero. Si el provider llamara a `env()` directamente, un futuro `config:cache` devolvería siempre `null` y la variable se ignoraría en silencio; por config sobrevive a la caché.

### D3. Resolver puro + provider idempotente
El mapeo `(explícito, appUrl) → bool` vive en `App\Support\ForceHttps::resolve()` (nuevo, sin dependencias del contenedor, junto a `DockerClient`): los tests unitarios cubren los 3 estados sin arrancar la app. `AppServiceProvider::boot()` pasa `config('taap.force_https')` y `config('app.url')` al resolver. `boot()` sigue siendo idempotente (`forceHttps`, `Date::use`, `prohibitDestructiveCommands`, `Password::defaults`), lo que permite al test feature fijar la config y re-ejecutarlo, así se afirma el esquema real de `url()` con la app montada.

### D4. Plantilla de despliegue la activa; el dev local la deja dormida
- `deploy/env.example`: `TAAP_FORCE_HTTPS=true` con comentario (el deploy de desarrollo vuela tras Traefik/TLS) + nota para despliegues sin TLS (`false` o comentada).
- `deploy/.env`: se fija `true` (mismo razonamiento).
- `orchestrator/.env.example`: variable documentada **comentada** (dev local sin TLS: `APP_URL=http://localhost:8000`).
- `docs/deployment.md`: nota breve en "Endpoints públicos" y "Solución de problemas" (CSS/JS no carga → mixed content → `TAAP_FORCE_HTTPS`).

## Risks

- **`config:cache` + cambio de variable**: con caché de config, un cambio de `TAAP_FORCE_HTTPS` requiere regenerar la caché (`php artisan config:cache`), igual que cualquier clave de `taap.php` (mismo riesgo que `TAAP_FS_ROOT`, ya documentado en el cambio `shared-filesystem`). El entrypoint actual no cachea, por lo que no afecta hoy.
- **`true` aplicado en un entorno http real**: assets https servidos por un servidor sin TLS → mixed content al revés (el navegador sí bloquea). Mitigado por la plantilla (solo la activan los despliegues TLS) y por el test del estado `false`.
- **Lectura del env en config**: `env('TAAP_FORCE_HTTPS')` en `config/taap.php` se resuelve al cargar la config; un `.env` mal formado (valor sin `true/false` reconocible, p. ej. `yes-please`) daría un string no casteado → el resolver lo trata como "sin definir" (derivación por `APP_URL`), no como error: degradación suave y predecible.

# Tasks

## 1. Código: resolver y config

- [x] 1.1 `App\Support\ForceHttps::resolve(mixed $explicit, string $appUrl): bool` (resolver tri-estado: bool manda; `null`/string no reconocible → `str_starts_with($appUrl, 'https://')`) + clave `force_https => env('TAAP_FORCE_HTTPS')` en `config/taap.php` + consumo en `AppServiceProvider::boot()` (sustituye la derivación en línea de `2f56d5b`). Verificación: `vendor/bin/pint --test` sin errores y el provider carga sin cambios de comportamiento observable.

## 2. Tests

- [x] 2.1 `tests/Unit/ForceHttpsTest.php`: los tres estados del resolver (`true`→true, `false`→false, `null`→derivación por el esquema de `APP_URL`, en ambos sentidos) y string no reconocible → derivación. Verificación: suite Unit en verde.
- [x] 2.2 `tests/Feature/Providers/ForceHttpsTest.php`: fija `config(['app.url' => ..., 'taap.force_https' => ...])` y re-ejecuta `AppServiceProvider::boot()`; afirma el esquema de `url('/...')` para los tres estados (incluido el cruce `APP_URL=https` + `false` → `http`). Verificación: suite Feature en verde (los 3 escenarios de la spec `web-deployment`).

## 3. Plantilla de despliegue y docs

- [x] 3.1 `TAAP_FORCE_HTTPS` en `deploy/env.example` (activada `true`, con nota para despliegues sin TLS) y `deploy/.env` (`true`) + variable documentada comentada en `orchestrator/.env.example`. Verificación: `docker compose config` válido y el orquestador levanta en el deploy de desarrollo sin cambios de red.
- [x] 3.2 `docs/deployment.md`: nota en "Endpoints públicos" y en "Solución de problemas" (CSS/JS no carga → contenido mixto → `TAAP_FORCE_HTTPS=true` tras TLS; regenerar caché de config si se usa). Verificación: los valores y comandos documentados son los que usa realmente el deploy (revisión contra `deploy/`).

## 4. Verificación global y cierre

- [x] 4.1 Suites completas del orquestador (Unit + Feature) + `make lint` (pint) en verde. Verificación: transcripción de la corrida en `notes/verification.md` dentro del cambio (resumen de suites).
- [x] 4.2 Humo en el deploy de desarrollo (Traefik TLS): abrir la web y confirmar que el CSS/JS de Vite carga (sin mixed content en la consola del navegador) y que `@vite` emite URLs https. Verificación: transcripción/captura en `notes/verification.md`.

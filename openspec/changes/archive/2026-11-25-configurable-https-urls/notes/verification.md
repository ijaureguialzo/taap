# Verificación (configurable-https-urls)

## 4.1 Suites completas + lint

- `cd orchestrator && php artisan test` → **453 tests, 441 passed, 12 skipped (integración Docker), 0 fallos**, 1739 aserciones, 172 s.
- `cd orchestrator && ./vendor/bin/pint --test` sobre los ficheros del change (`app/Support/ForceHttps.php`, `app/Providers/AppServiceProvider.php`, `config/taap.php`, `tests/Unit/ForceHttpsTest.php`, `tests/Feature/Providers/ForceHttpsTest.php`) → **passed**.
- Pint del repo completo: hay **fallos pre-existentes** en ficheros que este change no toca (adapters `Pi/Codex/Hermes/StdioRpc`, `Chat.php`, `ModelProxyController`, varios tests Unit, `lang/*` con trailing newline…). Se deja sin tocar: fuera del alcance del change.
- `docker compose -f deploy/compose.yaml -f deploy/compose.traefik.yaml config` → válido; `TAAP_FORCE_HTTPS="true"` visible en `orchestrator` y `queue`.

## 4.2 Humo en el deploy de desarrollo (Traefik TLS)

`make build && make up` con el código nuevo; en el contenedor, `printenv` devuelve
`TAAP_FORCE_HTTPS=true` y `APP_URL=http://127.0.0.1:8000` (la URL interna http, el caso que estaba roto).

```
$ curl -s --resolve taap.test:443:127.0.0.1 https://taap.test/login -o /tmp/taap_login.html -w "HTTP %{http_code}\n"
HTTP 200
$ grep -oE '(href|src)="[^"]*assets[^"]*"' /tmp/taap_login.html
href="https://taap.test/build/assets/app-DFuhNg4V.css"
href="https://taap.test/build/assets/app-DCq-bh-K.js"
href="https://taap.test/build/assets/app-DFuhNg4V.css"
src="https://taap.test/build/assets/app-DCq-bh-K.js"
$ for a in app-DFuhNg4V.css app-DCq-bh-K.js; do
    curl -s --resolve taap.test:443:127.0.0.1 -o /dev/null -w "$a -> %{http_code} (%{size_download} bytes)\n" "https://taap.test/build/assets/$a"
  done
app-DFuhNg4V.css -> 200 (327857 bytes)
app-DCq-bh-K.js -> 200 (311416 bytes)
```

Antes del change, el mismo request emitía `href="http://taap.test/build/assets/…"`
(scheme de `APP_URL`) → contenido mixto → CSS/JS no cargaban. Tras el change,
`@vite` emite `https://` y los assets responden 200 por TLS.

Acceso directo sin TLS (`http://127.0.0.1:8011/login`): los assets salen `https://`
(fuerza explícita); el punto de entrada público es el router TLS de Traefik, así que
el navegador no ve contenido mixto (limitación aceptada en el design, Risks).

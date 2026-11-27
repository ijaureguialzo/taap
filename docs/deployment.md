# Despliegue de TAAP (v1.0)

Guía de instalación y operación de la pila completa en un host con Docker.

## Qué hay

| Pieza | Qué es |
|---|---|
| `deploy/compose.yaml` | La pila: orquestador, cola, Postgres 16, egress (squid) |
| `deploy/entrypoint.sh` | Arranca en el orquestador: Reverb (WSS), scheduler, `taap:bridge`, servidor HTTP |
| `agents/pi`, `agents/hermes`, `agents/claude`, `agents/codex`, `agents/opencode` | Imágenes de los runtimes de agente |
| `deploy/squid` | Configuración + entrada del proxy de egress |
| `orchestrator` | Laravel 13 (web Livewire + API + Model Proxy + puente MCP) |
| `mobile/ios` | App iOS (XcodeGen; `make` en su directorio) |

## Prerrequisitos del host

- **Docker Engine** con el daemon accesible (el orquestador gestiona agentes a
  través del socket; se monta `docker.sock` en el contenedor orquestador).
- **Puertos libres** en el host: `80`/`443` (Traefik; siempre en la pila),
  `8011` (web/API; configurable con
  `TAAP_PORT`) y `8081` (WSS de Reverb; `TAAP_WS_PORT`, solo acceso directo).
- **~8 GB de RAM** libres para los contenedores (Postgres + orquestador +
  agentes) y **~10 GB de disco** (imágenes + datos de agentes).
- Dos directorios de datos (se crean solos, pero conviene preverlos):
  - `/var/lib/taap/squid` — fragmento ACL que escribe el orquestador
  - `/var/lib/taap/agents` — áreas persistentes de cada agente (sesiones,
    workspace, config); sobrevive a `docker compose down`
  - `/var/lib/taap/fs` — árbol del FS compartido (ver «FS compartido»);
    sobrevive a `docker compose down`
- Para modelos que corren **en el propio host** (router local, etc.): nada
  especial — los agentes y el orquestador lo alcanzan vía
  `host.docker.internal`.

## Instalación

```sh
git clone <repo> && cd taap
make env          # crea deploy/.env a partir de env.example
```

Edita `deploy/.env`:

| Variable | Qué poner |
|---|---|
| `APP_KEY` | `base64:…` de 32 bytes (`php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`) |
| `DB_PASSWORD` | cualquier string seguro |
| `REVERB_APP_KEY` / `REVERB_APP_SECRET` | 20 caracteres aleatorios (base64 si quieres) |
| `TAAP_PORT` / `TAAP_WS_PORT` | puertos públicos (defecto 8011/8081) |
| `TAAP_PI_IMAGE` / `TAAP_HERMES_IMAGE` / `TAAP_CLAUDE_IMAGE` / `TAAP_CODEX_IMAGE` / `TAAP_OPENCODE_IMAGE` | nombres de las imágenes de agente (defecto `taap/*:local`) |

```sh
make build        # orquestador + egress
make images       # agentes Pi, Hermes, Claude, Codex y OpenCode (taap/agent-{pi,hermes,claude,codex,opencode}:local)
make up
make seed         # (solo la primera vez) admin + proveedor/modelo de ejemplo
```

`make seed` crea:

- **Usuario `admin@taap.local`** (contraseña `taap1234` — cámbiala en el
  primer login desde la web) con rol admin.
- Proveedor `openai` (`https://api.openai.com/v1`, sin clave) y
  `qwen-local` (`http://host.docker.internal:8000/v1`).
- Modelos `gpt-5.2`, `gpt-5.2-mini` y `Qwen3.8-27B` (el del router local).

Comprueba:

```sh
make ps           # todos healthy/running
curl -s localhost:8011/up   # 200
```

Abre `https://taap.test` (o `http://localhost:8011`), entra con el admin y ve
el dashboard.

## Webhook de fallo de agente

Cuando un agente transiciona a «error», el orquestador envía un POST
JSON al webhook configurado (admin) con el payload
`{ event: 'agent.failed', agent_id, name, runtime, status_from, status_to, cause, at, url }`.

- Configuración (admin, API v1 — `failure_webhook_url` / `failure_webhook_secret`
  en `PUT /api/v1/settings`; el secret se cifra en reposo y nunca se devuelve
  ni se audita): URL y secreto HMAC compartido.
- La firma se viaja en la cabecera `X-TaaP-Signature: sha256=<hmac(secret, body)>`
  junto a `X-TaaP-Event`. El receptor debe verificar la firma antes de procesar.
- Reintentos: hasta 3 intentos (10/30/60 s) en errores 5xx/timeout; los 4xx no
  reintentan (URL rota). El último intento (resultado + http_code) aparece en el
  panel `/health`.
- La notificación es best-effort: nunca bloquea la transición del agente ni
  el chat.

## Visor de logs del agente

Vista read-only del tail de logs del contenedor del agente (owner/admin),
en la ficha del agente (web y iOS). Selector de tamaño (50/200/500 líneas),
copia al portapapeles, status claro para `no-container` y `unavailable`.
API: `GET /api/v1/agents/{id}/logs?tail={50|200|500}` → `{status, lines}`.
Solo tail: sin retención de logs (el daemon docker es el único origen).

## Búsqueda y exportación del chat

El chat es buscable y exportable (por agente, owner/admin):

- **Búsqueda** — barrita de búsqueda en el chat (web): la consulta se ejecuta
  al cambiar (~250ms) y muestra los mensajes que contienen el término,
  ordenados por relevancia. API: `GET /api/v1/agents/{id}/messages/search?q=&page=`.
- **Exportación** — `GET /api/v1/agents/{id}/messages/export?format=md|json`
  devuelve el historial completo (markdown o JSON) como adjunto.

En Postgres la búsqueda usa `tsvector` + `ts_rank` (índice GIN en la columna
generada `body_tsvector`); en sqlite usa LIKE (misma escala de datos, mismo
contrato). En la app iOS, el filtro es local (la sesión ya trae el historial)
y la exportación se comparte vía share sheet.

## Plantillas de agente

Cada usuario puede crear plantillas de agente — nombre, runtime, modelo
sugerido, servidores MCP precargados, Internet y límites — y usarlas como
punto de partida en la creación (web y iOS). La plantilla se aplica
pre-llenando runtime, modelo e Internet; el usuario puede modificarlos
libremente antes de crear. Las reglas son: runtime en el registro, modelo
compatible con el runtime, y nombre único por usuario.

- Web: página de creación — picker «Plantilla» + botón «Usar plantilla».
- API v1: `GET/POST/PUT/DELETE /api/v1/templates` (owner-only; el admin puede
  listar las de cualquiera con `?all=true`, pero no editarlas).

## Salud del sistema

Panel de salud operativo (rol admin): `/health` — agentes por estado en
vivo (refresh cada 10 s), últimos fallos (agent_events `type=error`) y
salud de proveedores (estado + última comprobación, si no se ha comprobado,
se muestra «sin comprobar»). API: `GET /api/v1/health/summary` (admin) con
el mismo shape (`agents_by_status`, `recent_failures`, `providers`).

## Backup y restore

`taap:backup` produce un bundle fechado (`<TAAP_BACKUP_DIR>/<timestamp>/`)
con `db.sql.gz` (dump de Postgres vía docker compose, o copia de sqlite),
`fs-root.tar.gz` (raíz de `TAAP_FS_HOST_BASE`), `agent-data.tar.gz`
(`TAAP_AGENT_DATA_HOST`) y `manifest.json` con sha256 y tamaño por parte.
Para volver a restaurar:

```sh
php artisan taap:backup:restore <bundle> --yes
```

La restauración **borra y sobrescribe** los datos existentes en el destino.
Antes de cualquier escritura, verifica los hashes del manifiesto y aborta en
cualquier fallo de integridad. El bundle **excluye secretos** (`.env`, claves
de proveedores, tokens): reponerlos es manual tras la restauración
(recrear `.env`, volver a crear proveedores si hace falta). Regla de
ventana: detener orquestador y cola durante el restore; las sesiones de
agentes se reanudan al arrancar de nuevo.

## Uso de modelos

El proxy de modelos escribe una entrada de telemetría por petición
(`model_usage`: agente, proveedor, modelo, tokens entrada/salida, estado y
latencia) de forma best-effort — si la escritura falla, la respuesta se
sirve igual. No hay retención automática en esta versión.

- Web: `/usage` (rol admin) — agregados (requests, tokens, errores) por
  agente y lista paginada de peticiones.
- API: `GET /api/v1/usage?agent_id=&provider_id=&from=&to=&page=` (admin):
  filas paginadas + `totals`, `per_agent` y `per_provider`.

## Auditoría

El panel de administración registra de forma inmutable cada acción admin
(alta/edición/borrado de usuarios, proveedores, modelos, MCP, toggle de
registro y borrado de agentes) en la tabla `audit_log`. La entrada se escribe
en la misma transacción que la operación: si la escritura falla, la operación
no se aplica.

- Web: `/audit` (rol admin) — filtros por actor, acción, objetivo y rango de
  fechas, con paginación.
- API: `GET /api/v1/audit?action=&target_type=&actor_id=&from=&to=&page=`
  (admin).

La tabla de auditoría es de solo lectura desde la app. La retención de datos
(borrado de entradas) queda deliberadamente fuera del alcance de esta versión.

## Autenticación en dos pasos (2FA) y passkeys

Todas las cuentas locales pueden habilitar 2FA (TOTP) y/o registrar passkeys
(WebAuthn) desde el perfil. La autenticación es **opt-in**: un usuario sin
2FA entra con su contraseña como antes.

- Web: perfil → «Autenticación en dos pasos». Escanea el QR, confirma el
  código de 6 dígitos y guarda los códigos de recuperación (se muestran una
  sola vez). Passkeys se registran desde el mismo perfil (requiere un
  navegador con WebAuthn).
- iOS: al iniciar sesión, si el servidor responde `requires_two_factor`, la app
  muestra el reto con el código (o un código de recuperación). Login por
  passkey en iOS nativo no está incluido (los passkeys funcionan sobre Web).
- Sesiones sin 2FA habilitado siguen funcionando sin cambios.

El 2FA no es obligatorio por rol en esta versión (los admins pueden optarse
por sí mismos). Para forzarlo, habrá una futura fase que lo exigirá.

## Endpoints públicos (FQDN y websocket)

La pila expone dos hosts por Traefik (FQDN configurables en `deploy/.env`):

| Variable | Para qué | Defecto |
|---|---|---|
| `FQDN_TAAP` | Frontend + API del orquestador (router `lamb`, :8000 interno) | `taap.test` |
| `FQDN_WS` | Servidor de eventos Reverb (router `ws`, :8080 interno, TLS :443) | `ws.taap.test` |

Los clientes (web e iOS) no configuran el websocket a mano: el orquestador
se lo comunica con el endpoint público (`TAAP_PUBLIC_HOST`,
`TAAP_PUBLIC_SCHEME`, `TAAP_REVERB_PUBLIC_PORT`; la web lo inyecta en el
layout y la app lo pide a `GET /api/v1/reverb-config`). Con los defectos,
ambos conectan a `wss://ws.taap.test` (443) a través de Traefik; el
navegador bloquearía un `ws://` directo desde la página HTTPS.

Lo mismo aplica a la propia app web (router de `FQDN_TAAP`): la página se
sirve por https, pero `APP_URL` es la URL interna (http) que usan los
agentes. `TAAP_FORCE_HTTPS=true` (fijada en `deploy/env.example`) fuerza
el esquema de las URLs que la aplicación genera —assets de Vite, redirects,
`url()`/`asset()`—, y así el CSS/JS cargan sin contenido mixto. Tri-estado:
`true` → https; `false` → http; sin definir → derivado del esquema de
`APP_URL` (el estado de siempre; no afecta el dev local en http).

**Desplegar en otro dominio** (p. ej. producción en `itlent.eus`): sin
tocar código, en el `.env` del host,

```
FQDN_TAAP=taap.itlent.eus
FQDN_WS=ws.taap.itlent.eus
TAAP_PUBLIC_HOST=ws.taap.itlent.eus
```

(esquema `https` y puerto `443` se mantienen) y regenera el certificado
para los dos dominios:

```sh
make certs DOMAINS="taap.itlent.eus ws.taap.itlent.eus"
make build && make up
```

**Solo LAN / sin Traefik** (p. ej. la app iOS apuntando a la IP del host):
`TAAP_PUBLIC_HOST=<IP del host>`, `TAAP_PUBLIC_SCHEME=http`,
`TAAP_REVERB_PUBLIC_PORT=<TAAP_WS_PORT>` y `TAAP_FORCE_HTTPS=false` (o sin
definir).

## Primer modelo (endpoint propio)

1. **Proveedores** → Nuevo: nombre, tipo (`openai`/`anthropic`/`other`),
   URL base (`https://api.openai.com/v1`, tu router, …) y API key.
   La key solo se escribe; las vistas la enmascaran y el Model Proxy la
   inyecta en cada llamada (los agentes nunca la ven).
2. **Modelos** → Nuevo: el proveedor, el `model_id` exacto que espera el
   endpoint (`gpt-5.2`, `orcarouter/Qwen3.8-27B-Uncensored-MLX`, …),
   ventana de contexto y tokens máximos.

El Model Proxy normaliza OpenAI y Anthropic tras el orquestador; los
agentes llaman a `http://taap-orchestrator:8000/…` con su token de agente.
Si el endpoint corre en el host (router local), usa como URL base
`http://host.docker.internal:<puerto>/v1`.

## Primer agente

1. **Agentes** → Nuevo: nombre, runtime (`pi`, `hermes`, `claude`, `codex` o `opencode`), modelo y, si
   quieres, prompt de sistema.
2. El orquestador crea su contenedor (red `taap-internal`, sin salida
   directa a Internet; todo el tráfico sale por el proxy squid), le monta
   `/workspace` + datos persistentes y le asigna un token.
3. Enseguida aparece como `starting` → `idle`; chatea desde la web o la app
   iOS. Cada agente tiene cuota por usuario (la del admin por defecto es 3;
   se ajusta en **Usuarios**).

Notas de operación:

- **Internet por agente**: el interruptor de Internet no da salida libre;
  el egress siempre es por squid con allowlist de hosts de modelos (D5).
  Para que un agente alcance un host extra, se añade a la allowlist del
  egress (el orquestador regenera el fragmento ACL automáticamente a partir
  de los endpoints de proveedor registrados).
- **Aprobaciones**: los comandos `bash` del agente Pi piden confirmación
  (extensión `taap-approval`); se responden desde la web o la app (y por API:
  `POST /api/v1/agents/{id}/approvals/{id}`).
- **Parar/borrar** un agente desde la web detiene y elimina su contenedor;
  los datos (sesiones Pi, workspace) persisten en `/var/lib/taap/agents`.

## FS compartido

Un único árbol físico en el host (`TAAP_FS_ROOT`, defecto
`/var/lib/taap/fs`) que los usuarios suben/bajan desde la web («Archivos») y
que los agentes pueden montar carpeta a carpeta.

- **Una raíz física, vistas virtuales**: dentro del orquestador y de la cola
  el árbol vive en `/data/fs` (bind; `TAAP_FS_BASE`); cada contenedor de
  agente lo ve en `/fs` como **arena en solo lectura** + los binds de las
  carpetas que tenga configuradas (`read_write` = rw, `read_only` = `:ro`,
  `disabled` = sin montaje). El kernel — no el prompt — aplica los modos.
- **Vistas por rol**: el admin ve el árbol completo; cada usuario ve su
  home (`/var/lib/taap/fs/<fs_slug>`, un slug corto único asignado en
  la migración/backfill) y no sale de ella; un path fuera de la home se
  marca «externa» en la web.
- **Carpetas por agente**: en la ficha del agente (sección «Carpetas
  compartidas») el propietario o el admin añaden carpetas con su modo.
  El agente las ve en el chat con la ruta `/fs<carpeta>`. La API v1
  también las expone/acepta en los agentes (`fs_mounts`).
- **Auto-aprobación dentro de lo montado**: en Pi, los comandos `bash`
  que solo tocan las carpetas montadas en su modo (escribir en rw, leer en
  ro) no piden confirmación; cualquier cosa fuera de ellas (o una escritura
  en una carpeta ro) sigue pidiendo aprobación.

**Cambiar `TAAP_FS_ROOT`** (p. ej. moverlo a otro volumen/disco): el bind
viaja dentro de los contenedores, así que requiere:

```sh
# en deploy/.env: TAAP_FS_ROOT=/nuevo/ruta/fs
docker compose -f deploy/compose.yaml -f deploy/compose.traefik.yaml up -d --force-recreate orchestrator queue
```

y luego el **rollout a la flota de agentes** (recrea los contenedores con
los nuevos binds, reanudando las sesiones):

```sh
docker compose -f deploy/compose.yaml exec -T orchestrator php artisan taap:agents:reprovision --dry-run
docker compose -f deploy/compose.yaml exec -T orchestrator php artisan taap:agents:reprovision
```

Verifica después con `docker inspect <contenedor>` que cada agente lleva
`<TAAP_FS_ROOT><path>:/fs<path>` y que los agentes vuelven a `idle`.

**Actualizar** (pull + imágenes nuevas) no toca el FS: los agentes no se
recrean solos; el puente se re-adjunta y el esqueleto de la arena se
re-asegura. Solo un cambio de mounts (carpetas del agente, raíz) requiere
el `taap:agents:reprovision` o el **Reiniciar** de cada ficha (que aplica
el mismo flujo).

## App iOS

- Apertura: `mobile/ios/regenerate.sh` (XcodeGen) y abrir `Taap.xcodeproj`.
- En ajustes: base URL del orquestador (p. ej. `http://192.168.x.y:8011`),
  login y token. El canal WSS no se deriva del base URL: la app lo pide al
  orquestador (`GET /api/v1/reverb-config`) y conecta al endpoint público
  configurado (por defecto `wss://ws.taap.test`; ver «Endpoints públicos»).

## Verificación

```sh
make lint         # Pint + build de assets
cd orchestrator && php artisan test
cd orchestrator && vendor/bin/phpstan analyse --memory-limit=2G
./deploy/smoke-e2e.sh   # E2E: proveedor → agente Pi (aprobación + MCP) → Hermes → FS compartido (9.1)
./deploy/audit-agents.sh  # auditoría de endurecimiento de los contenedores
```

## Actualizar

```sh
git pull
make build && make images
docker compose -f deploy/compose.yaml -f deploy/compose.traefik.yaml up -d --force-recreate orchestrator queue
```

Si has editado `deploy/.env` (el `env_file` se carga en la creación del
contenedor) o `deploy/compose.traefik.yaml` (labels de Traefik), usa el mismo
`up` con ambos ficheros —o directamente `make up`— para que surta efecto.

Los agentes no necesitan recrearse (el puente `taap:bridge` se re-adjunta
solo); si cambias el modelo/MCP de uno, usa **Reiniciar** en su ficha.
Si cambias sus **carpetas del FS** (o la raíz `TAAP_FS_ROOT`), los binds
cambian: usa `php artisan taap:agents:reprovision` (ver «FS compartido»);
las sesiones se reanudan, no se pierden.

## Solución de problemas

| Síntoma | Causa probable |
|---|---|
| Agente `offline` tras `docker compose down/up` | Normal los primeros 30 s: el bridge re-adjunta solo. Si persiste, `make logs` |
| El modelo no responde desde un agente | Comprueba el endpoint: `curl <url>/models` desde el host; para routers locales, la URL debe ser `host.docker.internal` |
| Egress 403 para un host | El host no está en la allowlist de squid (solo hosts de modelos) |
| WSS no conecta (web o iOS) | Comprueba que el host resuelve `ws.taap.test` (o el `FQDN_WS` configurado), que el certificado lo cubre y que `TAAP_PUBLIC_HOST`/`TAAP_PUBLIC_SCHEME`/`TAAP_REVERB_PUBLIC_PORT` apuntan a donde debe; en solo LAN usa la IP + `http` + `TAAP_WS_PORT` (ver «Endpoints públicos») |
| CSS/JS no cargan (página en blanco, contenido mixto en la consola) | La página es https pero los assets salen http: `TAAP_FORCE_HTTPS=true` en `deploy/.env` y `make build && make up` (ver «Endpoints públicos»); si usas caché de config, regénerala (`php artisan config:cache`) |
| Aprobación nunca aparece | El modelo no invocó la herramienta (modelos pequeños dudan): reenvía el mensaje |

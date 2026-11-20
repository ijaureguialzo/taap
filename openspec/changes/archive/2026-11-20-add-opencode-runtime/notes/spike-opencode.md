# Spike: `opencode-ai` 1.19.6 — API `serve` y stream de eventos

Ejecutado contra `opencode serve --port 8400 --hostname 127.0.0.1 --pure`
(`opencode-ai@1.19.6`, npm 2026-11-17) con un provider custom `taap`
(`npm: @ai-sdk/openai-compatible` → `options.baseURL`/`apiKey`) apuntando a un
gateway OpenAI local (chat completions SSE) y `permission: {bash: ask, edit: ask}`.

Transcripciones fijadas en `../fixtures/` (`openapi.json` = el `/doc` completo,
`event-flow.jsonl` = flujo limpio, `event3/4.txt` = streams crudos,
`gw-reqs.jsonl` = requests que el agente hizo al gateway). Gateway reutilizable:
`../gw.mjs`.

## Cuestiones del design, resueltas

### Q1. Layout de `serve` (puerto, endpoints, salud)

- `opencode serve --port N --hostname 127.0.0.0` → HTTP + SSE. **El puerto 0
  (defecto) elige uno al azar: el registry NO debe usar 0** (el adapter lo
  necesita fijo); usamos el `api_port` del registro (patrón Hermes, 4096).
- `GET /doc` → OpenAPI 3.1 completo (162 paths). **El adapter no necesita más
  que ~8 endpoints** (abajo).
- Salud: `GET /api/health` → 200 (también `GET /global/health`). **La API
  responde aunque el agente esté ocupado**: el health NO refleja el estado del
  turno (ese viene por `GET /session/status` y eventos).
- Auth: sin `OPENCODE_SERVER_PASSWORD` el servidor no exige credenciales (lo
  emite en stderr). En contenedor aislado es aceptable; si se fija el env, el
  adapter debe enviarlo (el renderer lo pondría en `opencode.json`… no: va por
  env en el entrypoint).

### Q2. Endpoints que usa el adapter

| Uso | Endpoint | Cuerpo | Respuesta |
|---|---|---|---|
| Salud | `GET /api/health` | — | 200 |
| Crear sesión | `POST /session` | `{title?, agent?, model?: {id, providerID}, permission?}` | `Session` (id `ses_*`) |
| Listar sesiones | `GET /session` | — | `[Session]` (sobrevive restarts) |
| Detalle sesión | `GET /session/{id}` | — | `Session` (404 si no existe) |
| Mensaje (bloqueante) | `POST /session/{id}/message` | `{parts: [{type:'text', text}], model?: {providerID, modelID}, system?}` | `{info, parts}` **cuando el turno termina** (5–60 s: no usable para el adapter: usa async) |
| Mensaje (async) | `POST /session/{id}/prompt_async` | igual | **204 inmediato** (el flujo llega por el stream) |
| Interrumpir | `POST /session/{id}/abort` | — | 200 |
| Estado | `GET /session/status` | — | `{ses_id: {type: 'busy'}}` (solo sesiones ocupadas; vacías = idle) |
| Permiso pendiente | `GET /permission` | — | `[PermissionRequest]` (lista global: **es el re-sync de re-attach**) |
| Resolver permiso | `POST /session/{id}/permissions/{perID}` | `{response: 'once' | 'always' | 'reject'}` | `true` / 404 `PermissionNotFoundError` |
| Modelo de sesión (hot) | `POST /api/session/{id}/model` | `{model: {id, providerID}}` | **204** y el `Session.model` queda actualizado |
| Config hot | `GET/PATCH /config` | config completo | config actualizada (ver Q5) |
| Stream | `GET /event` | — | SSE persistente global |

**El `POST /session/{id}/message` bloquea hasta el fin del turno** (medido:
0,4 s en fallo inmediato; 60 s con permiso pendiente). El adapter usa
`prompt_async` + stream (D1 del design) y `POST /message` queda como
alternativa de bloqueo para el caso de stream caído.

### Q3. Catálogo del stream `/event`

Formato SSE simple: **solo líneas `data: <json>`** (+ líneas en blanco; sin
`event:` ni `id:`) → **el drenado por fichero del patrón stdio funciona sin
cambios** (cada `data:` es una línea JSON).

Envelope: `{"id": "evt_…", "type": "<tipo>", "properties": {...}}`.
93 tipos en 1.19.6; los relevantes para la pipeline TAAP v1:

| Tipo | properties | → AgentEvent |
|---|---|---|
| `server.connected` | `{}` | marcador de arranque (como `remoteControl` de Codex) |
| `server.heartbeat` | `{}` | descartar (~15 s: útil para watchdog) |
| `session.created` | `{sessionID, info: Session}` | descartar (el id se sabe por la creación) |
| `session.status` | `{sessionID, status: {type: 'busy' \| 'idle'}}` | working ↔ idle (con la lógica de aprobaciones pendiente) |
| `message.updated` | `{sessionID, info: Message}` (role user/assistant; con `error` si falla) | `run.start`/`run.end` (info.error ≠ null → error) |
| `message.part.updated` | `{part: Part}` (partes `text`/`tool` con `state.status`) | `tool.start`/`tool.result` (partes tool); texto final |
| `message.part.delta` | `{sessionID, messageID, partID, field: 'text', delta: '…'}` | `message.delta` (streaming) |
| `permission.asked` | `{id: per_*, sessionID, permission: 'bash' \| …, patterns: [], metadata: {command?}, always: ['echo *'], tool: {messageID, callID}}` | `approval.request` (prompt = categoría + command) |
| `permission.replied` | `{sessionID, requestID, reply: 'once' \| 'always' \| 'reject'}` | `approval.resolved` (eco; el adapter también lo emite en `resolveApproval`) |
| `session.idle` | `{sessionID}` | idle (fin del turno) |
| `session.error` | `{sessionID, error: {name, data: {message, statusCode, …}}}` | `error` |

Ruido que se descarta: `plugin.added` (×45 al arrancar), `catalog.updated`,
`integration.*`, `reference.updated`, `session.diff` (904 por turno: deltas de
diff de ficheros — muy ruidoso), `session.updated`, `lsp.*`, `file.*`, `tui.*`,
`session.next.*` (pipeline experimental v2: sin consumir en v1.1).

### Q4. Sesiones: almacenamiento, reinicio, modelo

- Almacenamiento: **`$XDG_DATA_HOME/opencode/`** → `opencode.db` (SQLite;
  ~13 MB tras 4 sesiones de prueba) + `log/` + `repos/`. **El config va en
  `$XDG_CONFIG_HOME/opencode/opencode.json`** (con el subdirectorio `opencode/`
  — sin él NO se carga, verificado). `cwd` del proceso = `directory` de la
  sesión (proyecto): **con `cwd=/workspace` estable el proyecto es estable y la
  sesión sobrevive al reinicio** (verificado: 4 sesiones intactas tras `pkill`
  + re-`serve`, con sus modelos).
- `GET /session` tras el reinicio lista todas las sesiones (sin necesidad de
  `resume`: el adapter solo tiene que volver a enviar a la misma `ses_id`).
- **Modelo por sesión**: `Session.model = {id, providerID, variant}`. Se fija
  en la creación (`POST /session {model}`) y cambia en caliente con
  `POST /api/session/{id}/model {model: {id, providerID}}` (204, verificado).
- **Override por mensaje**: `prompt_async {model: {providerID, modelID}}` —
  **PERO el modelo debe ser resolvable**: si no está en
  `provider.taap.models` (ni en catálogos builtin) el prompt se **admite en
  silencio y no llama al modelo** (verificado: 204 + 0 requests al gateway).
  → El cambio de modelo fiable = renderer re-renderiza `opencode.json` con el
  nuevo modelo en el mapa del provider **y** `POST /api/session/{id}/model`
  (o reinicio, si se prefiere determinismo; el renderer lo decide en D4).
- `POST /api/session/{id}/model` con la forma `{"modelID", "providerID"}`
  (plana, como el body de `message`) → 400 `Missing key at ["model"]`: **la
  v2 exige el objeto anidado**.

### Q4b. Provider Anthropic (verificado)

Con `npm: "@ai-sdk/anthropic"` + `options.baseURL: http://…/v1` + `apiKey`, el
agente llama a **`POST <baseURL>/v1/messages`** (shape Anthropic estándar:
`user-agent: opencode/1.19.6 ai-sdk/provider-utils/4.0.46 runtime/bun/1.4.4`,
header `anthropic-beta`) — **el mismo contrato que el CLI Claude**: el renderer
usa `baseURL = <proxy>/v1` para proveedores `type: anthropic`. **Nota**:
opencode 1.19.x corre sobre **Bun** (runtime empaquetado, no node puro): la
imagen debe poder ejecutarlo (funciona en `debian:bookworm` vía el binario;
verificar en la imagen — si Bun exige glibc, la base `node:24-bookworm` lo
cumple).

### Q5. Config en caliente

- `GET /config` devuelve la config efectiva (JSON completo: model, provider,
  permission, instructions, mcp…).
- `PATCH /config` con el JSON nuevo → 200 y **la respuesta ya lleva el cambio**
  (aplicado a la instancia en memoria). **Matiz observado**: tras el PATCH, un
  `GET /config` posterior devolvió el valor anterior (el disco no se
  reescribe); el cambio rige los prompts nuevos en la instancia. **No se
  documenta como API estable** (no está en `/doc`… sí está: `get,patch
  /config`). Decisión: el renderer usará **re-render + reinicio** (determinista,
  como Hermes) y el `POST /api/session/{id}/model` queda como acelerador
  opcional del cambio de modelo (sin reinicio; la sesión no se toca).
- `PUT /config` → 200 en silencio (aceptado e ignorado: el schema real es
  GET/PATCH).

### Q6. Permiso: detalles

- `permission.asked` trae `always: ['echo *']`: **el patrón que aplicaría
  «permitir siempre»** (el runtime lo propone; el `response: 'always'` lo
  confirma). El `metadata.command` da el detalle para el `prompt` de la UI.
- Resolver: `POST /session/{id}/permissions/{per_id} {response}` → `true`.
  Ids de permiso: `per_*`. Tras `always`, los `echo *` no vuelven a preguntar
  (verificado implícitamente: el loop del gateway dejó de generar asks nuevos…
  en la práctica sí regresa por turno nuevo; el «always» persiste por sesión).
- **Timeout**: el runtime NO tiene timeout de permiso propio (la sesión queda
  `busy` indefinidamente): el adapter lo implementa (como en Codex: caduca →
  `reject`).
- `GET /permission` lista los pendientes globales: **es el re-sync de
  re-attach** (tras la brecha del stream se consultan y se re-emiten los
  `approval.request` aún vivos).

## Implicaciones para el implementation (resumen)

1. **Imagen**: `node:24-bookworm` + `npm i -g opencode-ai@1.19.6` + `python3`
   (MCP stdio) + `ca-certificates`; envs `XDG_DATA_HOME=/data`,
   `XDG_CONFIG_HOME=/config`, `HOME=/workspace` (opencode también mira `~`),
   `cwd=/workspace`; entrypoint `opencode serve --port 4096 --hostname 0.0.0.0
   --pure` (`--pure`: sin plugins externos → sin ruidos de plugin.added y
   arranque predecible; el warning de password se ignora en contenedor).
2. **Adapter `api-events`**: coproceso `docker exec -i … sh -c 'while :; do
   curl -sN http://127.0.0.1:4096/event; sleep 1; done' >> $dir/out 2>> $dir/err`
   + writer de comandos por HTTP (no hace falta coproceso: Guzzle desde PHP).
   `isAttached` = coproceso vivo + `State.StartedAt` igual (patrón del fix de
   Codex) + health 200. `normalize()` traduce los tipos de la tabla Q3.
3. **Renderer**: `opencode.json` con `provider.taap` según `type` del proveedor
   (openai → `@ai-sdk/openai-compatible` + `/v1`; anthropic → el equivalente
   con `/v1/messages`: **el Model Proxy ya sirve ambas rutas**), `model`,
   `permission` (ask para bash/edit/webfetch, allow para lectura), `mcp`,
   `instructions`.
4. **Sesiones**: `config.session_id` (patrón `session_key` de Hermes); el
   `cwd=/workspace` fijo garantiza el mismo proyecto tras reinicio.

### Q7. Resolución del SDK del provider (verificado en la imagen, 2026-11-20)

- El binario `opencode` (Bun embebido, `$bunfs`) **lleva BUNDLADOS los SDKs
  `@ai-sdk/*`** (openai-compatible ×727 refs, anthropic ×400, openai ×532…).
  Un provider custom con `npm: "@ai-sdk/openai-compatible"` **NO necesita
  instalación npm**: se resuelve del bundle (`import.meta.resolve` contra el
  bunfs). **No hay que pre-instalar nada para que un provider funcione.**
- El `node_modules` (~64 MB: `effect`, `zod`, `@opencode-ai/plugin`…) que
  opencode crea en la app dir (`$XDG_CONFIG_HOME/opencode/`) es del SISTEMA DE
  PLUGINS, no de los providers. Con `--pure` el sistema de plugins se salta.
- El pre-warm de la imagen (build-time) cubre esas deps de plugin por si un
  arranque sin `--pure` no tiene Internet; el entrypoint usa `--pure`.
- `session.status` emite **tres** tipos: `busy`, **`retrying`** (fallo de
  upstream; opencode reintenta con backoff) e `idle` (fin real). El adapter
  solo cierra el turno en `idle`; el error definitivo llega en
  `message.updated` con `info.error` (o `session.error`).
- `POST /session` **sin** `model` → `{"_tag":"BadRequest"}`: la creación de
  sesión requiere modelo resolvable (el renderer siempre lo incluye).

# Spike: OpenAI Codex app-server como runtime de agent

Fecha: 2026-11-20. Binary: `codex-app-server` de **rust-v0.171.0** (2026-11-20,
~232 MB). Plataforma: macOS arm64 (Docker desktop; el container será linux/amd64 —
mismo binary, distinta variante de plataforma `x86_64-unknown-linux-musl`).

Fixture completo (transcript JSON-RPC de un turno con 2 aprobaciones):
`fixtures/spike-0.171.0-full-turn.jsonl` (96 mensajes: initialize, thread/start,
turn/start, deltas, requestApproval x2, turn/completed, resume).

## Respuestas a las Open Questions del diseño

### Q: ¿Qué binario/version expone `codex app-server`?

El asset `codex-app-server-<plataforma>` existe desde `rust-v0.128.0` (2026-04-30).
**No existe en 0.103.0** (la TUI solo). Fijamos **0.171.0** (más reciente a fecha del
spike). El binario habla JSON-RPC 2.0 por stdio (línea = 1 mensaje, el formato
`Content-Length` de JSON-RPC clásico **no** se usa — confirmado: el servidor escribe
JSON por línea y lo acepta por línea).

### Q: ¿Qué wire format soporta contra un proxy?

`-c model_providers.<name>.wire_api = "responses"` **o** `"chat"`.
**Ojo (cambio vs v1.0):** en las releases `>= 0.128.0` el valor `"chat"` **ya no se
admite**: el binario arranca pero falla al resolver el provider:

```
failed to initialize model provider ...: unknown variant `chat`, expected one of
`responses`, `responses_websockets`
```

(chat fue deprecado en 0.128 — GitHub OpenAI/codex#7782 — y eliminado por completo
con la fecha de febrero 2026). **Conclusión:** el runtime Codex usa **`responses`**
→ el Model Proxy necesita ruta `/v1/responses` (SSE + JSON). Verificado: con un
gateway `/v1/responses` sintético el turno corre de punta a punta.

Provider custom completo (todos los `-c` que necesita el adapter):

```
-c model=<slug>
-c model_provider=<nombre>
-c model_providers.<nombre>.name=<nombre>
-c model_providers.<nombre>.base_url=<url>
-c model_providers.<nombre>.env_key=<VAR>        # nombre de la env con la API key
-c model_providers.<nombre>.wire_api=responses   # (obligatorio >= 0.128)
```

La API key viaja por la env indicada (`TAAP_API_KEY=...` en el container).
El proxy debe autenticar con `Authorization: Bearer` (codex la envía siempre).

### Q: ¿Cómo se mapean prompt, approval, tool, error, usage, session?

**JSON-RPC por stdio. Métodos que USAMOS (verificados):**

| Dirección | Método | Params → Result |
|---|---|---|
| c→s | `initialize` | `{clientInfo:{name,version}}` → `{userAgent, codexHome, platformFamily, platformOs}` |
| c→s | `initialized` (notificación) | `{}` → ack `{ok:true}` |
| c→s | `thread/start` | `{cwd, ...}` → `{thread:{id, environments[{environmentId:"local", cwd, runtimeWorkspaceRoots}], sessionId, ...}}` |
| c→s | `turn/start` | `{threadId, input:[{type:"text", text}]}` → `{turn:{id, rootTurnId, status:"inProgress", ...}}` |
| c→s | `thread/resume` | `{threadId}` → `{thread:{...}}` (nuevo proceso, mismo `CODEX_HOME`) |
| s→c | `item/commandExecution/requestApproval` (REQUEST con id) | ver abajo → respuesta `{decision: ...}` |

**Notificaciones s→c (las que importa normalizar; el resto es ruido):**

| Notificación | Payload relevante |
|---|---|
| `thread/started` | `{thread}` (mismo objeto que thread/start) |
| `thread/status/changed` | `{threadId, status:{type:"active"\|"idle", activeFlags:["waitingOnApproval"]}}` |
| `turn/started` | `{threadId, turn:{id,...}}` |
| `item/started` | `{item, ...}` — `item.type`: `userMessage`, `agentMessage`, `commandExecution`... |
| `item/agentMessage/delta` | `{threadId, turnId, itemId, delta}` — **streaming de texto** |
| `item/completed` | `{item}` final (agentMessage con `text`; commandExecution con `aggregatedOutput`, `exitCode`, `durationMs`) |
| `thread/tokenUsage/updated` | `{threadId, turnId, tokenUsage:{total:{totalTokens,inputTokens,cacheWriteInputTokens,cachedInputTokens,outputTokens,reasoningOutputTokens}, last:{...}}}` |
| `turn/completed` | `{threadId, turn:{...status:"completed", error, durationMs, items[...], startedAtMs, completedAtMs}}` |

Ruido que ignoramos: `account/rateLimits/updated`, `remoteControl/status/changed`,
`warning` (p.ej. "Model metadata for `X` not found — defaulting to fallback",
benigno para slugs custom).

**Forma de los items (para `normalize()`):**

```jsonc
// item/agentMessage/delta → AgentEvent delta (tipo "delta", itemId correlaciona)
{ "itemId": "msg_1", "delta": "texto..." }

// item/completed de agentMessage → fin de bloque
{ "item": { "type": "agentMessage", "id": "msg_1", "text": "texto completo" } }

// item/completed de commandExecution → result
{ "item": {
  "type": "commandExecution", "id": "call_1",
  "command": "/bin/zsh -lc 'touch /tmp/x.txt'",   // el envuelto real
  "commandActions": [{ "type": "unknown", "command": "touch /tmp/x.txt" }], // el original
  "cwd": "...", "processId": "15879", "status": "completed",
  "aggregatedOutput": "ping\n", "exitCode": 0, "durationMs": 3
} }
```

### Aprobación (el mecanismo, verificado)

Cuando una acción necesita aprobación (p.ej. `exec_command` con
`sandbox_permissions: require_escalated`, o un comando fuera del sandbox en
`read-only`), el servidor envía un **REQUEST** (id numérico correlacionable):

```jsonc
{ "jsonrpc":"2.0", "id": 0,
  "method": "item/commandExecution/requestApproval",
  "params": {
    "kind": "command",
    "threadId": "...", "turnId": "...", "itemId": "call_1",   // itemId = id del item commandExecution
    "environmentId": "local",
    "command": "/bin/zsh -lc 'touch /tmp/spike-out.txt'",     // envuelto (zsh -lc)
    "cwd": "/workspace",
    "commandActions": [ { "type": "unknown", "command": "touch /tmp/spike-out.txt" } ],
    "proposedExecpolicyAmendment": ["touch", "/tmp/spike-out.txt"],
    "availableDecisions": [
      "accept",
      { "acceptWithExecpolicyAmendment": { "execpolicy_amendment": ["touch","/tmp/spike-out.txt"] } },
      "cancel"
    ] } }
```

**Respuesta del cliente** (determina el desenlace):

- `{"decision":"accept"}` → ejecuta UNA vez con elevación. **Verificado: el fichero
  se escribió, el item pasó a completed, el turno siguió.**
- `{"decision":{"acceptWithExecpolicyAmendment":{"execpolicy_amendment":[...]}}}`
  → "siempre": el prefijo queda guardado (el proxy lo inyecta luego como mensaje
  developer "Approved command prefix saved: ..."). **Verificado: el segundo
  `touch` idéntico NO pidió aprobación de nuevo** — el amendment persiste en
  CODEX_HOME y viaja en el resume.
  Ojo: el valor debe ser el **objeto** tal cual lo ofrece `availableDecisions`;
  la cadena suelta `acceptWithExecpolicyAmendment` falla a la deserialización
  (`invalid type: unit variant, expected struct variant`).
- `{"decision":"cancel"}` → denegado; el tool reporta el error al modelo y el
  turno continúa (no lo mata).

Los 3 se mapean 1:1 a nuestros `approval.resolved` outcomes (`allowed` /
`allowed_always` / `denied`). **El timeout de aprobación del bridge responde
`cancel`** → el turno continúa sin que el contenedor muera (mismo patrón que Pi y
Claude).

### Session y continuity

- Todo el estado vive en `CODEX_HOME`: `state_*.sqlite`, `thread_history_*.sqlite`,
  `sessions/<a>/<m>/<d>/rollout-<ts>-<uuid>.jsonl` (transcript duradero),
  `installation_id`, `rules/`, `memories`, `logs`. **~2 MB tras un turno corto.**
  → bind mount en `/data/agents/<slug>/codex-home` (misma política que Pi).
- **`thread/resume {threadId}` en un proceso nuevo** devuelve el thread y el
  siguiente `turn/start` incluye el historial completo: **el gateway sintético
  recibió 19 items** (developer prompt, skills, user previos, assistant, el
  amendment aprobado y la pregunta nueva). **La conversación y la política de
  aprobación sobreviven a reinicios** → el `get_state` del bridge puede detectar
  "agent vivo + threadId persistido" y reanudar con `thread/resume` (patrón
  idéntico al `get_state` de Pi).
- **Rotación de modelo:** `thread/start` acepta el modelo de turno; al cambiar el
  modelo (o provider) el bridge crea `thread/start` nuevo (el `turn/start` lleva
  `threadId` fijo; no hay `thread/switch-model`). La continuidad de chat se logra
  con el thread; el modelo es un parámetro del thread. (Alternativa menor:
  `turn/start` admite `summary` al reanudar threads viejos — no la necesitamos.)

### Sandbox (config, no la normalizamos pero afecta las aprobaciones)

`-c sandbox_mode=workspace-write` (default razonable para coding) — los comandos
"seguros" (read-only dentro del workspace) corren sin aprobación; el resto pide.
`-c approval_policy=on-request` (default; `never` para auto, `untrusted` para
estricto). El modo sandbox se expone como key `sandbox_mode` de la config del
agent (mapeada a `-c sandbox_mode=...`), no como dial de aprobación.

### Herramientas que el modelo ve (tools del request `/v1/responses`)

`exec_command` (PTY unificada: `cmd`, `sandbox_permissions`, `max_output_tokens`...),
`write_stdin`, `request_user_input`, `view_image`, namespace `multi_agent_v1`
(close_agent, ...), `get_goal`/`create_goal`/`update_goal`, `web_search`.
→ `exec_command` es LA herramienta shell (el nombre `shell` de versiones viejas ya
no existe — ver error `unsupported call: shell`). Las de multi-agent/goals/web se
pueden desactivar con `-c` si se quiere (no es prioridad v1.1).

### Decidido para el diseño (decisiones que se fijan aquí)

- **D6 (nueva):** wire = `responses`. El Model Proxy agrega `handleResponses()`
  (passthrough OpenAI: `/v1/responses` JSON + SSE, sin normalización de formato —
  codex habla Responses nativo; el proxy solo autentica/ruta/registra).
- **D7 (nueva):** el `codex app-server` corre como PID 1 (el binario propio,
  `--listen stdio://` es el default; `--session-source taap` para marcar el origen).
  La config se inyecta con `-c key=value` renderizados desde `config.json`
  (`ConfigRenderer::renderCodex()`: model, provider, approval_policy,
  sandbox_mode, cwd). **No hay sidecar Node.**
- Normalización 100% en PHP (`CodexAdapter extends StdioRpcAdapter`): el mapping
  de la tabla anterior. El `AgentEvent::approval()` usa el `itemId` como `toolUseId`
  y el `id` JSON-RPC como `approvalId`.
- Probe de coproceso: el `initialize` handshake (result con `userAgent`/`codexHome`)
  es el `get_state` — y permite distinguir "app-server vivo" de "contenedor zombi".

## Riesgos / notas

1. **Tamaño**: 232 MB el binary (+ Node no es necesario — el app-server es Rust
   nativo; el image será MUCHO más ligero que el de Claude, que lleva el SDK +
   el CLI JS entero).
2. **Versiones**: cada minor del codex-cli cambia el protocolo app-server (las
   notificaciones llevan `v2` en el nombre — `item/completed` en 0.171; en 0.103
   era `item/completed` también, pero sin el requestApproval — el mecanismo de
   aprobación por server-request es de las versiones 0.12x+). **La imagen debe
   fijar la versión exacta del binario** (el asset de GitHub por tag) y el
   adapter documenta la versión que entiende. Upgrade = nueva imagen + revisar
   el fixture.
3. El `cwd` del thread = el workspace del agent (`/workspace`). El sandbox
   `workspace-write` permite escribir ahí sin aprobación (comportamiento
   deseado para un coding agent).
4. Las API keys: SOLO por env (`env_key`); no hay forma de inline key en el
   provider custom (verificado en el schema del `-c`) — encaja con el patrón de
   los demás runtimes (`env` renderizado por el ConfigRenderer).

## Complemento (spike completo)

- **`initialize` NO es idempotente**: un segundo `initialize` devuelve
  `{"code":-32600,"message":"Already initialized"}`. El probe del bridge lo
  traduce a `ready` igualmente (idempotencia en el adapter).
- **`initialized` es NOTIFICACION (sin `id`)**: si se envía con id el servidor
  responde error `unknown variant 'initialized'` (trata el mensaje como request).
  El adapter la envía sin id.
- **Declarar `capabilities: {experimentalApi: true}` en `initialize`** habilita
  `thread/settings/update` (sin ella: `requires experimentalApi capability`).
  **Con ella, `thread/settings/update {threadId, model}` cambia el modelo del
  hilo SIN rotar**: verificado — los requests al proxy pasaron de `model-A` a
  `model-B` manteniendo el mismo threadId (el gateway vio `model-B` en los 3
  requests posteriores). **Consecuencia para D2**: no hay rotación de thread al
  cambiar modelo; el adapter envía `thread/settings/update` tras cada
  `thread/resume` (el hilo reanudado arrastra el modelo antiguo del rollout).
- **`turn/start` con un turno en vuelo se COLA nativamente**: dos `turn/start`
  simultáneos → ambos `inProgress` → dos `turn/completed` (el app-server tiene
  cola interna; también hay métodos `thread/queue/*` y `turn/steer`/`turn/interrupt`
  para el futuro). `writeMessage` = `turn/start` simple.
- **`thread/list`** es el probe ligero de un app-server ya inicializado
  (idempotente, sin efectos).
- El binario **`codex-app-server-x86_64-unknown-linux-musl`** (tar.gz ~90 MB de
  la release `rust-v0.171.0`) corre en `debian:bookworm-slim` sin dependencias
  (`--version` OK en container): es estático. Existe también la variante
  `aarch64` (build nativo arm64) — el Dockerfile usa `TARGETARCH`.
- Catálogo de métodos: 192 en 0.171.0 (los relevantes: los de arriba +
  `thread/turns/list`, `thread/items/*`, `model/list`, `modelProvider/*`,
  `command/exec*`).

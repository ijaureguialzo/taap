# Release Notes — TAAP v1.1

**The Agentic AI Playground 1.1** — runtimes Claude Code, Codex y OpenCode, y
un orquestador más regulado por registro.

## Runtimes de agente

- **Claude Code** (nuevo): agente basado en el Claude Agent SDK, empaquetado
  como sidecar Node en `taap/agent-claude:local`.
  - Habla el protocolo TAAP v1 por stdio (patrón `rpc-stdio`, igual que Pi):
    `prompt`, `approval_response`, `get_state` por un FIFO `/rpc/in` y
    eventos JSONL por stdout.
  - **Aprobaciones**: las herramientas con efectos (Bash, Edit, Write, WebFetch,
    MCP) se aprueban/denegan desde web/iOS/API con el mismo flujo que Pi
    (`approval.request` / `approval.resolved`); «permitir siempre» por
    herramienta y timeout configurable (por defecto 30 min → denegado, y el
    agente continúa).
  - **Resumen y reanudación**: la sesión Claude persiste en el volumen de
    datos del agente y se reanuda con `resume` tras cualquier reinicio o
    recolocación; el chat del orquestador guarda el historial completo.
  - **Configuración en caliente** (`next-turn`): los cambios de modelo, MCP,
    prompt de sistema, permisos, `max_retries` y `approval_timeout_seconds`
    se aplican en el *siguiente* turno sin reiniciar el contenedor: el sidecar
    re-lee `/config/claude.json` en cada `query()`.

## Codex

- **Codex** (nuevo): agente basado en `codex-app-server` (la interfaz oficial
  de Codex para apps, binario Rust fijo en `rust-v0.171.0`), empaquetado en
  `taap/agent-codex:local`.
  - A diferencia de Claude, **no hay sidecar**: el app-server habla JSON-RPC
    por stdio directamente (patrón `rpc-stdio`); la normalización a la
    pipeline TAAP la hace el orquestador (`CodexAdapter`).
  - **Hilos y reanudación**: cada agente tiene un *thread* Codex persistente
    (`CODEX_HOME` en su volumen de datos). Tras cualquier reinicio o
    recolocación el orquestador hace `thread/resume`: el agente recuerda el
    historial completo. Si el agente se reinicia a medias, el app-server se
    re-inicializa solo (se detecta el arranque nuevo y la sesión, `docker
    restart` incluido).
  - **Aprobaciones**: las `exec_command` con sandbox se aprueban/denegan desde
    web/iOS/API (`approval.request` / `approval.resolved`); «permitir siempre»
    mediante *amendment* de la política de ejecución de Codex; timeout
    configurable (por defecto 30 min → cancel, y el agente continúa).
  - **Cambio de modelo en caliente**: `thread/settings/update` cambia el modelo
    del hilo *in situ* (sin rotar el hilo ni reiniciar el contenedor); los
    dials `sandbox_mode`, `approval_policy` y `max_retries` se aplican con
    `applyConfig` (re-render + reinicio suave).

## Orquestador

- **Registro de runtimes**: `config/taap.php` es la fuente de verdad
  (runtimes soportados, imágenes, modo de acople, volúmenes, ficheros de
  configuración y *capabilities*). Formularios (Create/Detail) y el bridge se
  gobiernan desde el registro: añadir un runtime ya no toca código de
  formularios.
- **Model Proxy — ruta Anthropic**:
  - Autenticación dual: acepta `Authorization: Bearer <token>` y
    `x-api-key: <token>` con el mismo token de agente.
  - `GET /v1/messages` con `stream: true` contra proveedor Anthropic: si el
    upstream responde su propio stream (`text/event-stream`) se hace *relay*
    transparente de los bytes SSE; si responde JSON, se sintetiza el stream
    Anthropic (`asAnthropicSse`: `message_start`, `content_block_*`,
    `message_delta`, `message_stop`).
  - `GET /v1/models` (validación del modelo que hace el CLI al arrancar cada
    query) y `GET /api/hello` (health del CLI), ambos con el token de agente.
  - El campo OpenAI `reasoning_effort`/`temperature` solo se inyecta para
    proveedores `openai`: la API Anthropic no los conoce.
  - Comportamiento OpenAI de v1.0 intacto (mismos campos, misma síntesis SSE),
    con una corrección de forma en el chunk final sintetizado: `delta: {}`
    (objeto, forma canónica) en lugar de `delta: []` (array), más el campo
    `usage` en passthrough. Los clientes v1.0 no la notan; los SDKs estrictos
    (p. ej. `@ai-sdk/openai-compatible`, que valida con Zod) la requieren.
  - **Test-only**: `taap.agents.test_api_publish` (no existe en producción)
    publica el puerto API en `127.0.0.1:0` y hace que el adapter OpenCode
    enroute por `docker exec curl` — el forward de `127.0.0.1` de Docker
    Desktop envejece tras un `docker restart` (ver design.md D6).
- **Adaptador por acople**: `StdioRpcAdapter` (base extraída de Pi) encapsula
  coprocesos, drenado, watchdog y cola de mensajes; `PiAdapter`,
  `ClaudeAdapter` y `CodexAdapter` la heredan y solo aportan su dialecto.
  Detecta reinicios del contenedor (`State.StartedAt`) y fuerza el re-attach:
  los runtimes `rpc-stdio` sobreviven a un `docker restart` sin perder el chat.
- **Model Proxy — passthrough Responses**: `POST /v1/responses` reenvía el
  cuerpo *tal cual* al upstream (con la sintaxis de `wire_api = "responses"`
  que Codex exige desde 0.128) y relays los bytes/SSE de vuelta; sin
  inyección de campos. El comportamiento OpenAI de v1.0 se mantiene intacto.

## OpenCode

- **OpenCode** (nuevo): agente basado en `opencode serve` (`opencode-ai@1.19.6`,
  servidor HTTP + stream SSE permanente), empaquetado en
  `taap/agent-opencode:local`.
  - **Modo `api-events`** (nuevo patrón de acople): a diferencia de Pi/Claude/
    Codex, los COMANDOS no van por stdio sino por la propia API HTTP de
    `opencode` (`prompt_async`, `permissions`, `session`), y los EVENTOS llegan
    por el stream `/event` que un coproceso `curl -N` cola en la IPC de
    ficheros; el orquestador lo drena con la misma lógica de offset/buffer que
    los runtimes `rpc-stdio` (`OpenCodeAdapter` sobre `StdioRpcAdapter`).
  - **Sesiones y reanudación**: la sesión `ses_*` persiste en el volumen de
    datos (`XDG_DATA_HOME/opencode/opencode.db`) y el *cwd* es estable
    (`/workspace`): tras cualquier reinicio el orquestador re-adjunta el
    stream y la sesión sigue con todo el contexto (verificado en integración
    con el gateway: el request post-reinicio lleva el historial completo).
  - **Aprobaciones**: `permission.asked` → `approval.request` (categoría +
    comando; «aprobado / permitir siempre / denegado» se mapean a la respuesta
    nativa `once`/`always`/`reject`); timeout configurable (por defecto 30
    min → `reject`, y el agente continúa).
  - **Formato dual de proveedor**: el provider custom `taap` usa
    `@ai-sdk/openai-compatible` o `@ai-sdk/anthropic` según el `type` del
    proveedor (ambos se resuelven del SDK que el binario empaqueta; sin
    instalación npm en el agente), apuntando al Model Proxy, que ya sirve las
    dos rutas.
  - **Configuración**: los cambios se aplican con re-render de
    `/config/opencode/opencode.json` + reinicio del contenedor; el cambio de
    modelo se acelera en caliente con `POST /api/session/{id}/model`.
  - **Integración verificada** (skipped sin Docker; CI con servicio Docker):
    4 tests en `tests/Feature/Agents/OpenCodeRuntimeIntegrationTest.php` —
    flujo completo con permiso + `docker restart` + *resume* de sesión
    (evidencia: el request post-reinicio lleva el historial), proveedor
    Anthropic (`/v1/messages`), fallo de proveedor (evento `error`) y
    permiso sin responder (timeout → `reject` → el agente continúa).

## Orquestador

- `docs/deployment.md` y el README describen los cinco runtimes (`make images`
  construye Pi, Hermes, Claude, Codex y OpenCode;
  `TAAP_{PI,HERMES,CLAUDE,CODEX,OPENCODE}_IMAGE`).

## Chat en vivo y razonamiento

- **Razonamiento visible en todos los runtimes**: los cinco adaptadores
  emiten el razonamiento del modelo SEPARADO del texto (nunca fundido):
  `thinking.delta` durante el turno y `thinking` en `message.final`
  (persistido en `chat_messages.meta`). En la web se muestra como seccion
  colapsable «Razonamiento» (oculta por defecto) dentro de la burbuja.
  - Pi: `thinking_delta` / bloques `thinking` del `message_end`.
  - Claude: el sidecar acumula los bloques de thinking del SDK.
  - Codex: resumen del item de razonamiento (`item/reasoning`) — se muestra
    el RESUMEN, no el razonamiento crudo token a token.
  - OpenCode: partes `reasoning` del stream.
  - Hermes: `delta.reasoning_content` (convenio Qwen/DeepSeek) a traves
    del Model Proxy.
- **Chat web en tiempo real**: los deltas de texto/razonamiento y el feed
  de actividad (herramientas en curso) se pintan en el cliente por el canal
  `agent.{id}` sin round-trip por token; las secciones de la burbuja
  asientada (Razonamiento + Actividad) se reconstruyen desde `meta` y
  `agent_events` por run.
- **Estado en vivo**: los badges de estado (lista, detalle y chat) se
  actualian por canal sin recargar; la tarjeta de aprobacion aparece y se
  limpia en vivo y la aprobacion se resuelve con un click (el agente
  reanuda en el mismo turno).
- **Codex**: por defecto se deshabilita `web_search` en la config renderizada
  (`-c web_search=disabled`): el binario la include siempre y los upstreams
  que no soportan la herramienta hacen fallar el turno entero (override
  configurable por agente).

## No cambia

- v1.0 intacto: Pi y Hermes se comportan exactamente igual; la web, la app
  iOS, las aprobaciones y el chat funcionan como antes (el contrato solo
  crece: los tipos nuevos `thinking.delta` y el campo `thinking` del final
  se ignoran en clientes antiguos).

## Detalles de implementación (interés técnico)

- Sidecar: `agents/claude/sidecar/` (Node 24, `@anthropic-ai/claude-agent-sdk`
  fijado) con suite de tests (`node --test`), normalización SDK→TAAP v1 y
  persistencia de sesión.
- La puerta del Model Proxy para el CLI Claude es `<proxy>/api/proxy/agents/{id}`
  (base *sin* `/v1`): el CLI Claude llama a `/v1/messages` y `/v1/models` y el
  proxy reenvía ese `/v1` intacto a los proveedores Anthropic (a diferencia de
  OpenAI, cuyo `base_url` ya lo lleva).
- Suite de integración en Docker (`tests/Feature/Agents/ClaudeRuntimeIntegrationTest`,
  `CodexRuntimeIntegrationTest` y `OpenCodeRuntimeIntegrationTest`): flujo
  completo con aprobación/denegación y reanudación tras reinicio, timeout de
  aprobación, fallo de proveedor y (en OpenCode) el formato dual Anthropic; se
  omiten sin Docker. Cada suite lleva su gateway sintético
  (`tests/Support/gateway-{responses,chat}.mjs`) que determina las herramientas
  según el mensaje y registra `nItems`/`nMessages` para probar que el request
  post-reinicio lleva el historial completo.

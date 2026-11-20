# Design

## Context

v1.0 integra dos runtimes a través de la costura `AgentRuntimeAdapter`
(`attach/detach/isAttached/drain/sendUserMessage/writeMessage/resolveApproval/
applyConfig/status/probe/destroy`), con dos modos en el registro
(`config/taap.php` → `runtimes`): `rpc-stdio` (Pi: `pi --mode rpc` como PID 1,
JSONL por IPC de archivos — los pipes de `proc_open` del build PHP local están
rotos, de ahí los coprocesos `docker attach`→`$dir/out` y watcher FIFO) y
`api-server` (Hermes: HTTP por turno, sin stream persistente). El demonio
`taap:bridge` orquesta el bucle: `drain()` → `EventSink` → Reverb/Postgres/
timeline. El Model Proxy ya expone la ruta Anthropic `/v1/messages`
(`x-api-key` + `anthropic-version`) sin consumidor. Design v1 (D12) reservó
exactamente esta integración. Ver proposal.md para el porqué.

## Goals / Non-Goals

**Goals**

- Claude Code como tercer runtime sobre la costura existente, sin tocar clientes.
- El sidecar normaliza dentro del contenedor: el mapeo SDK→`AgentEvent` vive en
  Node, el adapter PHP es una tubería fina.
- Extraer la mecánica stdio (coprocesos, IPC de archivos, drain, watchdog) a una
  base `StdioRpcAdapter` reutilizable (Pi, Claude y el futuro Codex).
- Registro con capacidades + selección de modelo consciente de formato.

**Non-Goals**

- Codex y OpenCode (cambios independientes; heredan la base y las capacidades).
- Cambios de comportamiento en Pi y Hermes (el refactor es transparente para ellos).
- Modo Bedrock/Vertex del SDK (`CLAUDE_CODE_USE_BEDROCK`/`_VERTEX`): el Model
  Proxy ya es el mecanismo de credenciales; se revisa cuando un proveedor lo exija.
- «Nueva sesión» explícito por agente (v1.0 tampoco lo tiene para Pi/Hermes).
- Auto-descubrimiento de modelos Anthropic (`GET /v1/models` de Anthropic): el
  formulario ya permite editar la lista a mano.

## Decisions

### D1. Sidecar Node (Claude Agent SDK) como PID 1, normalización en contenedor

La imagen `agents/claude/` contiene una app Node mínima (sidecar) que corre el
Claude Agent SDK (`@anthropic-ai/claude-agent-sdk`) como proceso principal. El
sidecar lee comandos JSONL del FIFO `/rpc/in` (entrypoint idéntico al de Pi:
`mkfifo` + holder) y escribe eventos JSONL por stdout, volcado a `$dir/out` por
el coproceso `docker attach` del bridge — la IPC de archivos de Pi, sin
nuevos mecanismos.

**Protocolo TAAP v1** (versionado: cada línea lleva `protocol: 1`):

- Comandos (orquestador → sidecar, por FIFO):
  - `{"protocol":1, "id":"…", "type":"prompt", "message":"…"}` — nuevo turno
  - `{"protocol":1, "id":"…", "type":"approval_response", "approval_id":"…", "approved":true, "note":"…"}`
  - `{"protocol":1, "id":"…", "type":"get_state"}` — sonda (respuesta `ready`)
- Eventos (sidecar → orquestador, por stdout): **el shape exacto de
  `AgentEvent::toArray()`**: `{"protocol":1, "type":"message.delta",
  "payload":{...}}` con los diez tipos existentes. El `normalize()` del
  `ClaudeAdapter` es un pase-through: decodifica, valida `type`/`protocol` y
  construye el `AgentEvent` con el mismo factory.

Razones:

1. El Agent SDK es joven y cambia con frecuencia (nombres de mensajes,
   `canUseTool`, storage). Con la normalización en contenedor, un bump del SDK
   = rebuild de la imagen `taap/agent-claude`, nunca del orquestador; el contrato
   que el bridge consume (`AgentEvent`) es estable por diseño de v1.0.
2. El SDK expone programáticamente justo lo que TAAP necesita: `query()` con
   `resume`, callback de permisos y stream de mensajes — la superficie más
   rica de los cinco runtimes.

Alternativas consideradas:

- *`claude -p --output-format stream-json --resume` por turno, sin sidecar*:
  cero código propio en contenedor, pero las aprobaciones en modo print exigen
  `--permission-prompt-tool` (un MCP server de utilería) o `--dangerously-skip-
  permissions`; no hay proceso residente para sondear salud. Descartado.
- *Passthrough crudo del SDK con mapeo en PHP*: el container queda trivial,
  pero el PHP queda acoplado a las shapes del SDK (cada bump puede romper el
  orquestador) y el release del orquestador tendría que estar coordinado con la
  imagen. Descartado.

### D2. Sesión: `resume` del SDK + id persistido por el sidecar

El SDK devuelve el `session_id` vigente en el mensaje `result` de cada turno.
El sidecar lo persiste en el volumen de datos (fichero propio del sidecar, p.
ej. `/data/session-state.json` — el `ConfigRenderer` NUNCA lo toca) y lo pasa
como `resume` en el `options` de cada `query()`. Tras un reinicio del
contenedor, el nuevo sidecar lo lee y reanuda. La sesión (transcripts JSONL)
vive bajo el directorio de configuración de Claude (env `CLAUDE_CONFIG_DIR` →
volumen de datos; confirmar valor exacto con la versión fijada en el spike);
`cwd` = `/workspace` (estable → hash de proyecto estable).

**Cambio de modelo sin rotar sesión**: a diferencia de Pi (el renderer rota el
`session_id` si cambia el modelo, porque la sesión reanudada arrastra la config
vieja), el modelo es `options.model` por query en el SDK: el sidecar relee
`/config/claude.json` en cada turno y la sesión sobrevive al cambio de modelo.
Simpler y conforme a la spec («surtir efecto sin perder sesión»).

### D3. Aprobaciones nativas vía `canUseTool`

El SDK llama `canUseTool(toolName, input, options)` antes de ejecutar una
herramienta no autorizada. El sidecar:

1. emite `approval.request` (`prompt` = nombre de herramienta + resumen del
   input; `options` = [aprobar, denegar, «permitir siempre» si el SDK lo
   soporta]);
2. deja pendiente una Promise por `approval_id`;
3. al recibir `approval_response`, resuelve con `{behavior:"allow",
   updatedInput}` / `{behavior:"deny", message: note}`; para «permitir
   siempre», la forma exacta de retorno (`updatedPermissions`) la fija el spike.

El agente queda **genuinamente en pausa** (el `query` está en vuelo) → el
`status.change waiting-approval` que ya emite la pipeline cubre la UI.
Tiempo sin respuesta: timeout configurable (default 30 min) → se resuelve como
denegado con nota, el agente continúa (evita un agente zombi con Promise
eterna). Política por defecto de permisos: preguntar para `Bash`, `Write`,
`Edit`, `WebFetch` y herramientas MCP; auto-permitir lectura
(`Read`, `Grep`, `Glob`, `LS`, `TodoWrite`) para no spammear aprobaciones.
Listas renderizables en `claude.json` (ajustables por agente más adelante).

### D4. Base `StdioRpcAdapter` extraída de `PiAdapter`

Se sube a una base abstracta la mecánica que Pi y Claude comparten al 100%:
estado de sesión por agente (`reader`/`writer`/`dir`/`container_id`/`offset`/
`buffer`/`run_id`), `attach/detach/isAttached` (validación de `container_id`
para forzar re-attach tras re-provisionado), `spawnCoprocesses` (kill previos +
`attachReader`/`startWriter`), `write()` (FIFO por archivo), `drain()` (offset +
buffer + split por líneas) y el watchdog `readerStale()` (re-attach si `out` no
crece en 60 s con trabajo pendiente). Métodos de plantilla: `probePayload()`,
`normalize(array $line): AgentEvent[]`, `probeInterval()`.

- `PiAdapter` hereda: `normalize` = su mapa actual (agent_start,
  message_update, tool_*, extension_ui_request…), `probePayload` = `get_state`.
  Sin cambio de comportamiento; se prueba con sus tests existentes.
- `ClaudeAdapter` hereda: `normalize` ≈ pase-through del protocolo D1,
  `probePayload` = `get_state`.
- Alternativa (duplicar ~200 líneas en Claude): descartada — con Codex
  en camino serían tres copias; la deriva es el bug.

### D5. Registro con capacidades y filtro de formato

Cada entrada de `runtimes` declara:

```
'capabilities' => [
    'formats'     => ['anthropic'],        // wire que consume vía Model Proxy
    'approvals'   => 'native',             // native | turn | none
    'config_apply' => 'next-turn',         // next-turn | restart
],
```

Rellenado v1.0: Pi = `['openai'], native, restart`; Hermes = `['openai'], turn,
restart`. Claude = `['anthropic'], native, next-turn`.

Filtro de modelo: en los formularios de creación y detalle, la consulta de
modelos añade `provider->type ∈ formats(runtime)` (mapeo `openai→openai`,
`anthropic→anthropic`, `other→openai` — «other» se comporta como OpenAI en el
proxy hoy; se documenta y la UI avisa si el único proveedor es «other»).
El Create pasa de validar `in:pi,hermes` a leer el registro. Las capacidades se
muestran localizadas en el detalle del agente (etiqueta informativa, p. ej.
«aprobaciones: nativas · config: aplica en el siguiente turno»).

### D6. Imagen y contenedor

`agents/claude/Dockerfile`: `node:24-bookworm`; `npm i -g` de
`@anthropic-ai/claude-code` + `@anthropic-ai/claude-agent-sdk` con versiones
fijadas por `ARG`; `COPY` del sidecar; usuario no root; directorios
`/config`, `/data`, `/workspace`; FIFO y holder como en `agents/pi/entrypoint.
sh`; `CLAUDE_CONFIG_DIR=/data/claude` (o el valor que fije el spike). El
`AgentManager` no cambia: los mounts salen de `ConfigRenderer` y el registro ya
describe `volumes` + `config_files`.

`renderClaude` (ConfigRenderer): `/config/claude.json` con modelo (id), endpoint
del Model Proxy (su ruta interna), token del agente, servidores MCP (la puerta
MCP HTTP del orquestador, como hoy), prompt de sistema (`append` sobre el preset
claude-code para conservar su comportamiento nativo) y listas de permisos.
Aplicación: el sidecar lo relee por turno → `config_apply: next-turn`, sin
reinicio (mejora sobre v1.0).

### D7. Model Proxy: verificación + síntesis SSE Anthropic

El SDK/CLI llama a `ANTHROPIC_BASE_URL/…/v1/messages` normalmente con
`stream: true`; el proxy v1.0 reescribe a no-stream y, si el cliente pidió
stream, sintetiza SSE **en shape OpenAI** (`asSse`). Para la ruta Anthropic el
shape sintético debe ser el de Anthropic (`message_start`,
`content_block_*`, `message_delta`, `message_stop`): se añade `asAnthropicSse`
con el mismo criterio (un chorrillo mínimo con el mensaje completo). También se
revisan las inyecciones transversales del proxy (`max_tokens`,
`temperature`, `reasoning_effort`) contra la API Anthropic: `reasoning_effort`
no es un campo de Anthropic (el interruptor real es `thinking`) → la inyección
se hace condicional al tipo de proveedor.

## Risks / Trade-offs

- [Volatilidad del Agent SDK] → versiones SDK+CLI fijadas por `ARG` en la
  imagen; normalización en contenedor aísla el churn; test de integración contra
  la imagen fijada (el primer caso lo trae este cambio; los demás lo reutilizan).
- [El sidecar es nuevo mantenimiento] → mantenerlo mínimo: protocolo + mapeo,
  sin lógica de runtime; el `protocol: 1` de las líneas permite al adapter
  detectar versión incompatible y pasar el agente a «error» con nota legible.
- [Aprobación sin responder → agente en pausa] → timeout (D3) → denegado.
- [APIs estrictas pueden rechazar campos ajenos (`reasoning_effort`,
  `temperature`)] → inyección condicional por tipo de proveedor (D7) +
  verificación con Anthropic real y con un gateway local OpenAI-compatible.
- [El filtro de formato deja vacío el selector] (p. ej. solo hay proveedores
  OpenAI y el agente es Claude) → empty-state amable en el formulario:
  «configura un proveedor Anthropic» con enlace a la pantalla de admin.
- [Los agentes Claude Code solo funcionan con proveedores Anthropic] →
  inherente al wire; se comunica con el filtro (no es un fallo, es la
  compatibilidad declarada).
- [rootfs readonly vs escritura del proceso node] → `CLAUDE_CONFIG_DIR` en
  `/data`, resto en tmpfs `/tmp` (patrón de Pi); si el CLI quiere escribir en
  home, el spike lo captura.

## Migration Plan

Añaditivo, sin migración de datos: nuevo caso en `AgentRuntime`, nueva entrada
de registro (dormida hasta `make images`), nueva imagen, refactor `PiAdapter`
transparente. Despliegue: build de imágenes → despliegue del orquestador →
reinicio del `taap:bridge`. Rollback: revertir el orquestador y ocultar la
entrada del registro; agentes Claude ya creados pasarían a «error» (v1.1 local:
aceptable, se documenta en release notes).

## Open Questions

Todos cerrados por el spike (`notes/spike-claude.md`):

- Nombres/versión del Agent SDK y del paquete `claude-code`, env de
  directorio de sesión y forma de retorno de «permitir siempre» de
  `canUseTool` → **cerrado**: `@anthropic-ai/claude-agent-sdk@0.3.329` +
  `@anthropic-ai/claude-code@2.1.329`; `CLAUDE_CONFIG_DIR`; retorno
  `behavior: allow|deny` — `updatedPermissions` no desbanca una regla `ask`
  gestionada en el mismo proceso, de ahí el set `always_allowed` persistente
  del sidecar. Si algo cambia con un bump del SDK, cambia el sidecar, no la
  spec.
- Semántica del proveedor tipo `other` en el filtro → **cerrado**: se trata
  como OpenAI (no se adivina por URL; un endpoint Anthropic-compat se
  declara `anthropic` en el proveedor).
- Listas por defecto de permisos (D3) → **cerrado**: `ask: Bash, Edit,
  Write, WebFetch, mcp__*`; `allow: Read, Grep, Glob, Skill, TodoWrite,
  Task` (válido por el spike: sin ruido para lectura; ajustable por la
  config renderizada).

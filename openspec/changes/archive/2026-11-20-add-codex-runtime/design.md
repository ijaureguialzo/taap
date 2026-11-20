# Design

## Context

Estado tras `add-claude-code-runtime`: la costura tiene la base `StdioRpcAdapter`
(mecánica de IPC de archivos: coprocesos, drain, watchdog; plantilla
`probePayload/normalize/probeInterval`) que hereda Pi, y el registro declara
capacidades. Codex es el CLI de OpenAI (binario Rust) y ofrece `codex app-
server`: un servidor JSON-RPC bidireccional sobre stdio (el mismo patrón de
proceso residente que Pi, sin bridge propio). Design v1 (D12) lo reservó para
v1.2. Ver proposal.md para el porqué.

## Goals / Non-Goals

**Goals**

- Codex como cuarto runtime sobre la costura, **sin bridge en contenedor**:
  proceso nativo `codex app-server` como PID 1, normalización en PHP.
- Demostrar la reutilización de `StdioRpcAdapter` (segundo heredero tras Pi).
- Sesión continua por hilo (thread) con reanudación tras reinicios.
- Aprobaciones nativas del app-server hacia la pipeline.

**Non-Goals**

- OpenCode (cambio independiente; modo HTTP + stream de eventos).
- Modo TUI interactivo de Codex (irrelevante en contenedor headless).
- ~~Wire `responses` del API OpenAI como formato de proveedor~~ **REVISIONADO
  por el spike**: en codex `>= 0.128` el valor `wire_api = "chat"` ya no existe
  (deprecado en 0.128, eliminado en feb 2026) — el binario falla al arrancar el
  proveedor. La integración se fija a `wire_api = "responses"` y el Model Proxy
  agrega passthrough de `/v1/responses` (ver specs/model-management).
- Perfiles múltiples de Codex (`[profiles]`): un agente = un perfil implícito.

## Decisions

### D1. `codex app-server` como superficie, normalización en PHP

El contenedor arranca `codex app-server` como PID 1; el orquestador habla con
él por la IPC de archivos estándar (FIFO `/rpc/in` + `docker attach`→`$dir/out`,
entrypoint idéntico al de Pi). `CodexAdapter extends StdioRpcAdapter` y su
`normalize()` diferencia líneas: `{"id","method",…}` = **notificación** del
servidor (→ eventos `AgentEvent`), `{"id","result"|"error"}` = **respuesta**
de un comando (ACK; los `error` se traducen a evento `error`).

Difiere de Pi en el framing (JSON-RPC con ids correlacionados vs JSONL suelto)
y de Claude en que **no** hay proceso propio: el mapeo de eventos vive en PHP,
como Pi. El protocolo app-server es más joven que el RPC de Pi y menos
estable: por eso el spike (grupo 1) lo mapea contra la versión fijada antes de
escribir el adapter, y el mapping queda en PHP (cambios de protocolo = release
del orquestador, que ya es la pieza que se despliega).

Alternativa: `codex exec --json` por turno (sin proceso residente). Descartada:
pierde la correlación de hilos, las aprobaciones en vivo y el sondeo de salud;
y el modo rpc-stdio con base compartida ya paga toda la mecánica.

### D2. Sesión: hilo (thread) como unidad de conversación

El app-server modela la conversación como **thread**: `thread/start` (con
`cwd`) en el arranque y `thread/resume {threadId}` tras un reinicio. El id del
hilo activo se guarda en el `config` del agente (clave `thread_id`, patrón del
`session_id` de Pi) y el estado completo del app-server vive en `CODEX_HOME=/data`
(`state_*.sqlite`, `sessions/<a>/<m>/<d>/rollout-*.jsonl`, `rules/`,...; ~2 MB
por sesión corta tras el spike). Es el layout tipo Hermes: un solo bind.

**Handshake y attach (spike):** `initialize {clientInfo}` → resultado
`{userAgent, codexHome, ...}` (el probe de coproceso: cierra `starting` →
`ready`), luego notificación `initialized`. En `attach` (post-reinicio):
`initialize` + si hay `thread_id` persistido → `thread/resume {threadId}`, si no
→ `thread/start {cwd: /workspace}`; el id devuelto se persiste (notificación
`thread/started` lo confirma). **Verificado en el spike**: tras matar y relanzar
el proceso con el mismo `CODEX_HOME`, el `thread/resume` devolvió el thread y el
siguiente `turn/start` envió al proxy el historial completo (19 items, incluidos
los comandos aprobados con su prefijo persistido).

**Cambio de modelo (decidido por el spike):** el hilo **fija** el modelo —
`turn/start` acepta un parámetro `model` pero el request al proxy sigue usando
el modelo del thread. **Por tanto el renderer rota el thread** al cambiar el
modelo (misma regla `model_key` de Pi): el adapter borra `thread_id` del config
y el próximo `attach` hace `thread/start` con el nuevo modelo; el historial en
el chat del orquestador se conserva (spec: «sin perder la sesión»).

### D3. Aprobaciones y sandbox

`sandbox_mode = "workspace-write"` (escribe en `/workspace`; la aislación dura
la da el contenedor) + `approval_policy = "on-request"`: cada comando fuera de
lo permitido genera en el app-server una petición de aprobación con id.
`CodexAdapter` la traduce a `approval.request` (`prompt` = comando + directorio
de trabajo; opciones aprobar/denegar/«permitir siempre» si el protocolo lo
da) y resuelve enviando la respuesta correlacionada con el id. Sin aprobación
pendiente, el agente está `idle`; con ella, `waiting-approval` (los eventos de
turno ya lo indican).

### D4. Plano de configuración: `-c` overrides + `codex.env` en el volumen

El app-server acepta overrides de config por línea de comandos (`-c key=value`,
formato TOML plano), lo que evita escribir `config.toml` a mano y deja la
semántica de aplicación idéntica a la de Pi (el entrypoint los pasa siempre;
no hay `config.toml` renderizado — lo que sí se renderiza en el bind de datos:

- `codex.env` (sourced por el entrypoint, patrón `agents/pi`): `TAAP_API_KEY`
  (token del agente), `CODEX_HOME=/data`.

Los `-c` renderizados por `ConfigRenderer::renderCodex()` (D4bis, se generan en
el bind de datos como `codex-args.env`, sourced por el entrypoint):
`model=<model_id>`, `model_provider=taap`,
`model_providers.taap.name/base_url/env_key=TAAP_API_KEY`,
**`model_providers.taap.wire_api=responses`** (obligatorio: `chat` fue
eliminado en 0.128), `approval_policy=on-request`, `sandbox_mode=workspace-write`
(dial configurable desde el form), más las llaves MCP (`mcp_servers.*`).

Aplicación: re-render + `docker restart` (el app-server lee `-c` al arrancar;
`config_apply: restart`), el hilo se reanuda (D2). Misma semántica que Hermes.

### D5. Imagen

`agents/codex/Dockerfile`: base `debian:bookworm-slim` + binario `codex` de la
versión fijada por `ARG` (release estática; se descarga en el build) + `python3`
y `node` (runtimes para servers MCP stdio, paridad con la imagen de Pi) +
usuario no root + entrypoint FIFO (patrón Pi) + `readonly` rootfs compatible
(los writes van a `/data` y tmpfs `/tmp`).

## Risks / Trade-offs

- [Protocolo app-server inestable/joven] → versión fijada por `ARG`
  (**0.171.0**); el spike (grupo 1, `notes/spike-codex.md` + fixture
  `fixtures/spike-0.171.0-full-turn.jsonl`) mapeó el protocolo ANTES de escribir
  el adapter; los tests de contrato usan transcripciones JSON-RPC de esa
  versión como fixture.
- [El mapping vive en PHP (a diferencia de Claude)] → un cambio de shapes del
  app-server obliga a release del orquestador; aceptado: el binario Rust es
  mucho más estable que el SDK de Claude, y no hay pieza en contenedor que
  mantener.
- [Hilo fijado al modelo → rotación de sesión al cambiar modelo] (si el spike
  lo confirma) → misma regla que Pi hoy; la spec no la penaliza («sin perder la
  sesión» se satisface con nueva sesión + historial en chat).
- [Crecimiento de `~/.codex/sessions` en el volumen] → igual que las sesiones de
  Pi; volúmenes gestionados por agente, limpieza futura (no de este cambio).
- [MCP stdio dentro del contenedor] → la imagen lleva python3+node (coste de
  imagen); los servers http ya van por la puerta MCP del orquestador.

## Migration Plan

Añaditivo, sin migración de datos: caso `Codex` en el enum, entrada en el
registro (dormida hasta `make images`), imagen, adapter. Despliegue como el
resto: imágenes → orquestador → bridge. Rollback: revertir orquestador y
ocultar la entrada del registro; agentes Codex ya creados pasan a «error»
(aceptable en v1.1 local; release notes).

## Open Questions (resueltas por el spike, grupo 1)

- Nombres del protocolo: `initialize`/`initialized`, `thread/start|resume`,
  `turn/start {threadId, input:[{type:"text",text}]}`, items
  `userMessage`/`agentMessage`/`commandExecution` (con `aggregatedOutput`,
  `exitCode`, `durationMs`), deltas `item/agentMessage/delta`, `usage` en
  `thread/tokenUsage/updated`, `turn/completed`. **Aprobaciones**: request
  `item/commandExecution/requestApproval {kind, itemId, command, cwd,
  commandActions, proposedExecpolicyAmendment, availableDecisions}` → respuesta
  `{decision: "accept" | {acceptWithExecpolicyAmendment:{...}} | "cancel"}` —
  los tres se mapean a `allowed` / `allowed_always` / `denied`. Lo que no exista
  (p.ej. `fileChange` no apareció en el spike; el tool de escritura de archivos
  de 0.171 lo hace vía `exec_command`/patch interno) se muestra como actividad
  genérica (criterio v1.0). Detalles en `notes/spike-codex.md`.
- ¿El hilo fija el modelo? **SÍ** (el override `model` de `turn/start` se
  acepta pero el request al proxy usa el modelo del thread) → rotación de
  thread al cambiar modelo (D2).
- Paquete binario: el asset estático **`codex-app-server-<plat>`** de la release
  GitHub `rust-v0.171.0` (el binario `codex` de npm es la TUI; el app-server es
  un binario separado). En el Dockerfile: `ARG CODEX_VERSION=0.171.0`,
  descarga del asset `x86_64-unknown-linux-musl` (build linux/amd64).
- **Wire**: `responses` (D4bis) — el Model Proxy agrega `/v1/responses`
  passthrough (delta en specs/model-management).

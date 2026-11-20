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
- Wire `responses` del API OpenAI como formato de proveedor: el Model Proxy hace
  passthrough de cualquier ruta, pero la integración se fija a `wire_api =
  "chat"` (lo que el proxy v1.0 soporta de verdad); el soporte `responses` se
  revisa cuando un proveedor lo exija.
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
hilo activo se guarda en el `config` del agente (patrón del `session_id` de Pi)
y los transcripts viven en `~/.codex/sessions/` (rollouts) bajo
`CODEX_HOME=/data` (volumen de datos; layout tipo Hermes: un solo bind).

En `attach` (post-reinicio o re-provisionado): si hay thread id persistido →
`thread/resume`, si no → `thread/start`; se guarda el id devuelto. La respuesta
del handshake `init` (o el request equivalente de info de app según spike)
cierra el `starting` → `ready` como el `get_state` de Pi.

**Cambio de modelo**: si el spike confirma que el hilo fija el modelo (como el
`--session-id` de Pi arrastra config), el renderer rota el thread id al cambiar
el modelo (misma regla `model_key` de Pi): nueva sesión, historial en chat
intacto. Si el app-server permite sobreescribir el modelo por `turn/start`, no
se rota y el cambio aplica en el siguiente turno (la spec solo exige no perder
la sesión: ambas rutas la cumplen; decide el spike).

### D3. Aprobaciones y sandbox

`sandbox_mode = "workspace-write"` (escribe en `/workspace`; la aislación dura
la da el contenedor) + `approval_policy = "on-request"`: cada comando fuera de
lo permitido genera en el app-server una petición de aprobación con id.
`CodexAdapter` la traduce a `approval.request` (`prompt` = comando + directorio
de trabajo; opciones aprobar/denegar/«permitir siempre» si el protocolo lo
da) y resuelve enviando la respuesta correlacionada con el id. Sin aprobación
pendiente, el agente está `idle`; con ella, `waiting-approval` (los eventos de
turno ya lo indican).

### D4. Plano de configuración: `config.toml` en el volumen

`renderCodex` escribe en el área de datos (bind `/data` = `CODEX_HOME`):

- `config.toml`: `model = <model_id>`, `model_provider = "taap"`,
  `[model_providers.taap]` (`base_url` = ruta del Model Proxy del agente,
  `env_key = "TAAP_API_KEY"`, `wire_api = "chat"`, `name`),
  `[mcp_servers.*]` (stdio: `command/args/env`; http: la puerta MCP del
  orquestador con `bearer_token_env_var`), `sandbox_mode`, `approval_policy`.
- `codex.env` (sourced por el entrypoint, patrón `agents/pi`): `TAAP_API_KEY`
  (token del agente), `CODEX_HOME=/data`.

Aplicación: re-render + `docker restart` (Codex lee `config.toml` al arrancar;
`config_apply: restart`), el hilo se reanuda (D2). Misma semántica que Hermes.

### D5. Imagen

`agents/codex/Dockerfile`: base `debian:bookworm-slim` + binario `codex` de la
versión fijada por `ARG` (release estática; se descarga en el build) + `python3`
y `node` (runtimes para servers MCP stdio, paridad con la imagen de Pi) +
usuario no root + entrypoint FIFO (patrón Pi) + `readonly` rootfs compatible
(los writes van a `/data` y tmpfs `/tmp`).

## Risks / Trade-offs

- [Protocolo app-server inestable/joven] → versión fijada por `ARG`; el spike
  del grupo 1 mapea el protocolo ANTES de escribir el adapter; contrato de test
  contra la versión fijada (transcripciones JSON-RPC como fixture).
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

## Open Questions

- Nombres exactos y semántica del protocolo app-server en la versión que se
  fije (handshake `init`, `thread/start|resume`, `turn/start`, items de
  `commandExecution`/`fileChange`/`mcpToolCall`, forma de las peticiones de
  aprobación y de «permitir siempre», `usage`) → **spike** (grupo 1); lo que
  no exista se deja fuera del mapping y se muestra como actividad genérica
  (criterio de v1.0 para Hermes).
- ¿El hilo fija el modelo? (D2) → spike; decide si hay rotación de thread.
- Paquete binario vs wrapper npm para el build: se elige en el Dockerfile
  (release GitHub estática es el candidato); sin impacto en specs.

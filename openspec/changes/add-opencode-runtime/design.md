# Design

## Context

El modo `api-server` v1.0 (Hermes) asume **eventos dentro de la respuesta**
(HTTP por turno, `drain()` sin-op). OpenCode no encaja: su `serve` expone
comandos por HTTP **y** un stream SSE persistente (`/event`) por donde llegan
la actividad y los permisos. Es el único runtime de la lista que exige un
nuevo modo de registro. Estado restante: base `StdioRpcAdapter` y registro con
capacidades (de `add-claude-code-runtime`), proxy dual-formato, puerta MCP.
Design v1 (D12) lo reservó; su riesgo de volatilidad ya estaba anotado. Ver
proposal.md para el porqué.

## Goals / Non-Goals

**Goals**

- OpenCode como quinto runtime: `serve` como PID 1, API HTTP + stream de
  eventos, sesiones explícitas, aprobaciones nativas por API.
- Nuevo modo `api-events` en el registro, reutilizando el drenado por archivos
  ya probado (patrón de IPC de v1.0: los streams largos viven en coprocesos,
  el PHP sondea archivos).
- Runtimes duales de formato: agentes OpenCode pueden usar cualquier proveedor
  (el filtro del selector lo refleja).

**Non-Goals**

- TUI de opencode (irrelevante en contenedor headless).
- Multi-sesión por agente / varios hilos (v1.0: 1 agente = 1 sesión).
- Integraciones de opencode ajenas a TAAP (LSP, formatters): fuera del
  renderer.

## Decisions

### D1. Modo `api-events`: HTTP para comandos, SSE colado en archivo para eventos

`opencode serve --host 0.0.0.0 --port <puerto>` como PID 1 (puerto en el
registro, patrón `api_port` de Hermes). Dos canales:

- **Comandos** (adapter → contenedor, HTTP directo desde el orquestador):
  crear/reutilizar sesión, enviar mensaje a la sesión, resolver permisos,
  sondar estado.
- **Eventos** (contenedor → adapter): el stream SSE `/event` es **global y
  persistente**; el adapter mantiene un coproceso vigilante
  (`docker exec … sh -c 'while :; do curl -sN http://127.0.0.1:<puerto>/event; sleep 1; done'`
  con appends a `$dir/out`) y drena el fichero con la lógica offset+buffer del
  patrón stdio. Cada línea SSE se traduce a `AgentEvent` en `normalize()`.

El modo `api-events` diferencia a `api-server` (Hermes) solo en que `drain()`
lee el stream en vez de ser sin-op y `isAttached` exige vivo el coproceso del
stream. **Composición**: `OpenCodeAdapter` hereda `StdioRpcAdapter` y sobreescribe
el spawn del coproceso lector (el «writer» FIFO no existe: los comandos van por
HTTP) — si la base extraída en `add-claude-code-runtime` entrelaza ambas cosas,
se ajusta su composición en ese momento (refactor transparente, sin impacto en
specs ni en Pi/Claude). El watchdog de lector stale del patrón stdio cubre un
coproceso SSE muerto en silencio.

Alternativa: Guzzle con stream a lo largo en el loop del bridge. Descartada: un
stream muerto sin detección silenciaría eventos (los permisos se perderían) y
contradice la lección de v1.0 (los streams de larga vida viven en coprocesos).

**Brechas de reconexión**: si el coproceso se cae, los eventos emitidos en la
ventana de reconexión se pierden. Mitigación: el spike determina si el API
lista permisos/pendientes de la sesión (p. ej. `GET /session/:id`); en caso
afirmativo, el adapter se re-sincroniza tras cada re-attach consultando la
sesión. Si no existe tal endpoint, la ventana se mitiga con la re-sincronización
por eventos de estado de la sesión (idle/complete) y se documenta.

### D2. Sesiones explícitas por API

1 agente = 1 sesión opencode. Al `attach`: si hay `session_id` en el `config`
del agente → reutilizar (el almacenamiento vive en el volumen de datos:
`$XDG_DATA_HOME=/data`, proyectos hasheados por `cwd` → `cwd=/workspace`
estable); si no → `POST /session` y persistir el id (patrón `session_key` de
Hermes). La sesión sobrevive reinicios del contenedor.

**Cambio de modelo**: opencode permite sobreescribir el modelo por mensaje; si
el spike lo confirma, el adapter lo pasa en el cuerpo del mensaje → cambio sin
rotar sesión ni reiniciar (semántica `next-turn`). Si no, el renderer rota el
`session_id` (regla `model_key` de Pi) + reinicio: la spec se cumple en ambas
rutas.

### D3. Aprobaciones: sistema de permisos nativo

El renderer fija `permission` en `opencode.json` (`edit`/`bash`/`webfetch` y
demás categorías: `ask` para efectos, `allow` para lectura, `deny` opcional) y
el adapter traduce:

- evento `permission.asked` (id, categoría, detalles) del stream →
  `approval.request` (`prompt` = categoría + detalle, p. ej. el comando a
  ejecutar; opciones aprobar/denegar/«permitir siempre» según el spike).
- decisión del usuario → `POST /session/:id/permission/:id` con la respuesta;
  `approval.resolved` a la pipeline. El agente queda en pausa hasta la
  respuesta (semántica `waiting-approval` existente).

### D4. Plano de configuración

`renderOpenCode` → `opencode.json` en el volumen de config
(`$XDG_CONFIG_HOME=/config`) con:

- `provider`: entry custom `taap` apuntando al Model Proxy del agente
  (`options.baseURL` + `apiKey` = token; la forma exacta — npm package
  `@ai-sdk/openai-compatible` o provider del registro — según spike; para
  proveedores Anthropic, el equivalente con la ruta `/v1/messages`).
- `model` por defecto (el modelo elegido; el override por mensaje según D2).
- `mcp`: servers asignados (stdio `command/args/env`; http → puerta MCP del
  orquestador).
- `permission` (D3) e `instructions` (prompt de sistema del agente).

**Aplicación**: si el spike confirma la API de config en caliente
(`POST /config`) → `config_apply: next-turn/hot` sin reinicio; si no,
re-render + reinicio (como Hermes). La sesión se conserva en ambos casos.

### D5. Imagen

`agents/opencode/`: `node:24-bookworm` + `npm i -g <paquete-opencode>@<versión
fijada>` (el nombre del paquete cambió con el rebrand del proyecto: lo fija el
spike) + `python3` para MCP stdio (paridad Pi) + usuario no root;
`XDG_DATA_HOME=/data`, `XDG_CONFIG_HOME=/config`, `cwd=/workspace`; entrypoint
`opencode serve` (sin FIFO: no hay stdio que gestionar) + tmpfs `/tmp`;
rootfs readonly compatible. Sondeo de salud: `GET` a la API (200 = vivo),
patrón del `status()` de Hermes.

## Risks / Trade-offs

- [Volatilidad de opencode (el design v1 ya lo señalaba: «V2 latest»)] →
  versión fijada por `ARG`; spike antes del adapter; fixtures de la API/SSE
  como contrato de test contra la versión fijada.
- [Brecha de reconexión del stream (D1)] → re-sincronización por consulta de
  sesión si el API lo permite; el watchdog re-arranca el coproceso; se
  documenta la ventana residual.
- [El stream es global por servidor] → irrelevante: 1 contenedor = 1 agente =
  1 sesión, como en el resto de runtimes.
- [Dos formatos en un solo renderer] → `renderOpenCode` genera el provider
  según el `type` del proveedor (openai/anthropic): un código, dos formas.
- [El coproceso `curl -N` mantiene una conexión larga dentro del contenedor] →
  el loop con `sleep 1` tras cada EOF evita colgaduras; el coste es una
  conexión inactiva (despreciable en servicio local).

## Migration Plan

Añaditivo, sin migración: caso `OpenCode`, entrada en el registro (dormida
hasta `make images`), imagen, adapter. Despliegue: imágenes → orquestador →
bridge. Rollback: revertir orquestador y ocultar la entrada; agentes OpenCode ya
creados pasan a «error» (aceptable en v1.1 local; release notes).

## Open Questions

- Paquete npm y versión a fijar (rebrand del proyecto) → spike.
- Shapes exactos de la API v2: `POST /session`, `POST /session/:id/message`
  (incl. override de modelo), permisos (`permission.asked`, `POST …/
  permission/:id`, «permitir siempre»), lista de pendientes de sesión, `POST
  /config`, y el catálogo de eventos del stream (nombres y payloads) →
  **spike** (grupo 1), con transcripciones fijadas como fixtures.
- Layout exacto de almacenamiento (`XDG_DATA_HOME` vs `~/.local/share`, hash de
  proyecto) y de config → spike; el renderer y la imagen se ajustan al
  resultado (sin impacto en specs).

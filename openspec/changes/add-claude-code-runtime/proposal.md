# Proposal

## Why

v1.0 shipó solo Pi y Hermes, pero el diseño ya reservó la extensión (design.md D12:
"v1.1: Claude Code … + ruta Anthropic del Model Proxy"). La ruta Anthropic del Model
Proxy (`/v1/messages`) existe desde v1.0 sin consumidor, y Claude Code aporta las
aprobaciones más genuinas de los cinco runtimes en juego: su SDK pausa el agente y
bloquea hasta que el usuario decide. Es el candidato natural para el siguiente
runtime, y su integración (sidecar Node) ejercita por primera vez el caso de «bridge
en contenedor» que la costura de v1.0 ya anticipó.

## What Changes

- **Nuevo runtime Claude Code** (tercero del registro), integrado mediante un
  **sidecar Node** (Claude Agent SDK) como proceso principal del contenedor: JSONL
  por la IPC de archivos existente, sin tocar la superficie `rpc-stdio`. El sidecar
  **emite los eventos ya normalizados** desde dentro del contenedor: el mapeo vive
  junto al SDK (que evoluciona rápido) y el adapter PHP queda como tubería fina.
- **Refactor del adapter Pi**: extraer la mecánica compartida (coprocesos, IPC de
  archivos, drain, watchdog de lector stale) a una clase base `StdioRpcAdapter`
  que heredan Pi y Claude. Sin cambio de comportamiento; base reutilizable para
  futuros runtimes stdio (Codex).
- **Registro con capacidades**: cada entrada declara `formats` (formatos de alambre
  que consume: openai/anthropic), `approvals` (native/turn/none) y
  `config_apply` (restart/next-turn). Pi y Hermes se retroalimentan.
- **Selección de modelo consciente de formato**: el selector filtra modelos por
  formato del proveedor × capacidades del runtime, y los formularios de
  creación/detalle de agente se gobiernan desde el registro (hoy: lista
  `pi,hermes` hardcodeada en el formulario, pese a que la spec ya declara el
  registro como única fuente de verdad).
- **Plano de configuración**: renderiza `config/claude.json` (modelo, endpoint del
  Model Proxy, token, MCP, prompt de sistema); el cambio se aplica **desde el
  siguiente turno, sin reinicio**, conservando la sesión.
- **Aprobaciones nativas**: el callback `canUseTool` del SDK pausa el agente;
  aprobar/denegar/«permitir siempre» desde web e iOS como hoy.
- Claude Code consume el Model Proxy por la ruta Anthropic existente
  (`ANTHROPIC_BASE_URL`/`ANTHROPIC_API_KEY` → su ruta de proxy); no hay cambios
  más allá de verificación de extremo a extremo.

Sin cambios rompibles: el comportamiento de Pi y Hermes no cambia.

## Capabilities

### New Capabilities

(ninguna — el nuevo comportamiento encaja en capacidades existentes)

### Modified Capabilities

- `agent-runtimes`: el registro incluye `claude` (sustituye "exactamente dos
  runtimes"); nuevo requisito de adapter Claude Code (sidecar sobre el Agent SDK,
  reanudación de sesión, aprobaciones nativas); el registro declara capacidades.
- `model-management`: la selección de modelo por agente filtra por compatibilidad
  formato proveedor × formato del runtime.

## Impact

- `orchestrator/app/Agents/`: nuevo `ClaudeAdapter.php`; nueva base
  `StdioRpcAdapter.php` extraída de `PiAdapter.php`; `RuntimeAdapters` (brazo del
  match); `ConfigRenderer` (`renderClaude`); `AgentRuntime` (caso `Claude`).
- `orchestrator/config/taap.php`: entrada `claude` en `runtimes` + capacidades en
  las tres entradas.
- `orchestrator/app/Livewire/`: `Agents/Create.php` y `Agents/Detail.php`
  (gobierno por registro + filtro de formato); etiquetas nuevas en
  `lang/es|en|eu` (nombres de runtime y capacidades).
- `agents/claude/`: imagen nueva (`node:24-bookworm` + `@anthropic-ai/claude-code`
  fijado + sidecar); `Makefile` (`images`).
- Tests: contrato del protocolo sidecar↔adapter, unitarios de `ClaudeAdapter` y
  de la base, feature del filtro de formato en la selección de modelo.
- Clientes (web/iOS): sin cambios — consumen eventos normalizados y el registro
  gobierna el formulario.

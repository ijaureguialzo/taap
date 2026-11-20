# Proposal

## Why

Design v1 (D12) reservó "v1.2: Codex (app-server) y OpenCode (HTTP)". Codex
(runtime de OpenAI) es el primer runtime que hereda la base `StdioRpcAdapter`
que el cambio `add-claude-code-runtime` extrae: su superficie nativa
(`codex app-server`, JSON-RPC por stdio, proceso de larga vida) encaja en el
modo `rpc-stdio` existente sin bridge propio en contenedor. Añade el cuarto
runtime al registro y demuestra la reutilización de la costura.

Dependencia de planificación: este cambio asume el registro y la base stdio del
cambio `add-claude-code-runtime` (el delta del registro refleja el estado final
con los cuatro runtimes). No hay dependencia de implementación entre sí.

## What Changes

- **Nuevo runtime Codex** (cuarto del registro), integrado mediante su
  **app-server nativo** como proceso principal del contenedor: JSON-RPC sobre
  los flujos estándar por la IPC de archivos existente (hereda la base
  `StdioRpcAdapter`; normalización en PHP, como Pi — no hay bridge propio).
- **Plano de configuración**: renderiza `config.toml` de Codex en el volumen de
  datos (`CODEX_HOME`): provider de modelo apuntando al Model Proxy (formato
  OpenAI, `wire_api = "chat"`), servidores MCP, política de aprobaciones y
  sandbox; el cambio de configuración se aplica con reinicio del contenedor
  (`config_apply: restart`), reanudando la sesión (hilo) persistida.
- **Aprobaciones nativas**: las peticiones de aprobación del app-server
  (ejecución de comandos fuera del sandbox) se traducen a
  `approval.request`/`approval.resolved` de la pipeline.
- **Registro**: entrada `codex` (imagen fijada, capacidades openai/native/
  restart); el requisito de registro pasa de tres a cuatro runtimes.
- **Imagen** `agents/codex/` con el binario de versión fijada + runtime para
  servers MCP stdio.

Sin cambios rompibles: Pi, Hermes y Claude no cambian.

## Capabilities

### New Capabilities

(ninguna — el nuevo comportamiento encaja en capacidades existentes)

### Modified Capabilities

- `agent-runtimes`: el registro incluye `codex` (el requisito pasa de
  "tres runtimes" a "cuatro runtimes"); nuevo requisito de adapter Codex
  (app-server, reanudación de hilo, aprobaciones nativas).

## Impact

- `orchestrator/app/Agents/`: nuevo `CodexAdapter.php` (hereda
  `StdioRpcAdapter`); `RuntimeAdapters` (brazo); `ConfigRenderer`
  (`renderCodex`).
- `orchestrator/app/Enums/AgentRuntime.php`: caso `Codex`.
- `orchestrator/config/taap.php`: entrada `codex` en `runtimes`.
- `orchestrator/app/Livewire/` + `lang/es|en|eu`: formulario por registro y
  etiquetas (los mecanismos los aporta `add-claude-code-runtime`).
- `agents/codex/`: imagen nueva (binario fijado); `Makefile` (`images`).
- Tests: contrato del protocolo app-server (contra la versión fijada),
  unitarios del adapter y del renderer, integración con Docker.
- Clientes (web/iOS): sin cambios — eventos normalizados y registro como
  fuente de verdad del formulario.

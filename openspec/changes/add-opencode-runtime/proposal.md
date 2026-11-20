# Proposal

## Why

Cierre del plan de D12 (design v1): "v1.2: … OpenCode (HTTP)". OpenCode
(`opencode`) aporta dos cosas que ningún otro runtime de la lista trae: su
**`opencode serve`** expone una API HTTP real con **sesiones explícitas** y un
**sistema de permisos nativo** (aprobaciones con respuesta por API), y su
consumo de proveedores soporta **ambos** formatos (OpenAI-compatible y
Anthropic vía su registro de providers) — es el único runtime cuyos agentes
podrán usar cualquier proveedor configurado.

Dependencia de planificación: asume el registro con capacidades, la base
`StdioRpcAdapter` y los mecanismos de formulario/filtro de
`add-claude-code-runtime` (el delta del registro refleja el estado final con
los cinco runtimes). Sin dependencia de implementación con los otros cambios.

## What Changes

- **Nuevo runtime OpenCode** (quinto del registro), integrado mediante
  `opencode serve` como proceso principal del contenedor.
- **Nuevo modo de registro `api-events`**: comandos por HTTP (como Hermes)
  **pero** los eventos (actividad de herramientas, permisos, final de turnos)
  llegan por un **stream SSE persistente** (`/event`), no dentro de la
  respuesta. La mecánica reutiliza el patrón de IPC de archivos: un coproceso
  vigilante cola el stream en `$dir/out` y el adapter lo drena.
- **Sesiones explícitas por API**: el adapter crea/reanuda una sesión del
  agente (id persistido en su config, almacenamiento en el volumen de datos);
  sobrevive reinicios.
- **Aprobaciones nativas**: los eventos de permiso del stream →
  `approval.request`; la decisión del usuario se resuelve por la API de
  permisos.
- **Plano de configuración**: renderiza `opencode.json` (provider custom →
  Model Proxy, modelo, MCP, políticas de permiso, instrucciones); aplicación
  según soporte del runtime (hot vía su API de config o reinicio; decide el
  spike), sin perder la sesión.
- **Registro**: entrada `opencode` (capabilities openai+anthropic / native /
  según spike); el requisito de registro pasa de cuatro a cinco runtimes.
- **Imagen** `agents/opencode/` con la versión fijada.

Sin cambios rompibles: los demás runtimes no cambian.

## Capabilities

### New Capabilities

(ninguna — el nuevo comportamiento encaja en capacidades existentes)

### Modified Capabilities

- `agent-runtimes`: el registro incluye `opencode` (el requisito pasa de
  "cuatro runtimes" a "cinco runtimes"); nuevo requisito de adapter OpenCode
  (API HTTP + stream de eventos, sesiones explícitas, aprobaciones por API).
- `model-management`: el filtro de selección de modelo deja de excluir
  proveedores para este runtime (consume ambos formatos) — el requisito se
  generaliza a "compatible según las capacidades declaradas por el runtime".

## Impact

- `orchestrator/app/Agents/`: nuevo `OpenCodeAdapter.php`; posible ajuste
  transparente de composición en la base `StdioRpcAdapter` (el modo
  `api-events` reutiliza el drenado de eventos por archivo con un coproceso
  distinto — ver design); `RuntimeAdapters` (brazo); `ConfigRenderer`
  (`renderOpenCode`).
- `orchestrator/app/Enums/AgentRuntime.php`: caso `OpenCode`.
- `orchestrator/config/taap.php`: entrada `opencode` en `runtimes` (nuevo modo
  `api-events`, puerto de API, capacidades).
- `orchestrator/app/Livewire/` + `lang/es|en|eu`: formulario por registro y
  etiquetas.
- `agents/opencode/`: imagen nueva (versión fijada); `Makefile` (`images`).
- Tests: contrato de la API/SSE (versiones fijadas), unitarios del adapter y
  del renderer, integración con Docker.
- Clientes (web/iOS): sin cambios — eventos normalizados y registro como
  fuente de verdad del formulario.

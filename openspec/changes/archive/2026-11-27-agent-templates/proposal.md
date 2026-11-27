# Proposal: Plantillas de agente (templates de creación)

## Why
Crear un agente hoy es un formulario manual (nombre, runtime, workspace, modelo, servidores MCP, Internet, límites) en web y en iOS: no hay presets ni sugerencias. Un usuario nuevo (o el mismo dos veces) repite la configuración y otros propósitos (revisión de código, docs, data) se resuelven reinventando la misma combinación.

## What Changes
- Catálogo de plantillas (admin y usuario): nombre, runtime, sugerencia de modelo, servidores MCP precargados, Internet, límites por defecto.
- El flujo de creación de agente ofrece plantillas como punto de partida; el usuario puede aceptarla o adaptarla.
- En web y iOS (iOS usa las mismas plantillas vía la API v1 de plantillas).

**Out of scope:** marketplace/share entre usuarios (plantillas privadas del autor); validación de compatibilidad modelo-runtime al aceptar una plantilla (se valida igual que la creación manual); plantillas por admin para todo el equipo (decisión de diseño).

## Capabilities

### New Capabilities
*(ninguna)*

### Modified Capabilities
- `agent-lifecycle`: la creación de agentes gana punto de partida de plantilla.

## Impact
- Nueva entidad/migración (templates), catálogo administrado, UI de creación (Livewire `Agents/Create` + `NewAgentSheet.swift`), endpoint API v1 (`GET/POST /api/v1/templates`).
- La validación de creación no cambia: plantilla = defaults que pueden adaptarse.

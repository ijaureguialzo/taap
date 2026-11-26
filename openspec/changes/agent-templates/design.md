# Design: Plantillas de agente

## Context
Ver proposal.md — Why. Estado actual: los agentes se crean configurando runtime, workspace, modelo, MCP, internet y límites (web y iOS). No existe un catálogo de plantillas.

## Goals / Non-Goals
**Goals:**
- Plantillas por usuario: nombre, runtime, modelo sugerido, MCP preseleccionado, Internet, límites.
- Flujo de creación web/iOS con prellenado + modificación antes de crear.

**Non-Goals:**
- Marketplace o compartición entre usuarios; plantillas administrativas predefinidas (deferred — design note); enforcement de compatibilidad en el arranque (la validación de creación sigue siendo la misma).

## Decisions

1. **Tabla `agent_templates`** — `id`, `owner_id` (FK users, NOT NULL — plantillas por usuario), `name` (string, ~100), `runtime` (string, NOT NULL — runtime del registro), `model_id` (FK models, nullable — nullable porque el modelo puede no estar seleccionado al crear la plantilla, o no disponible), `mcp_server_ids` (JSON, nullable), `internet` (bool, default true), `cpu` (decimal/nullable), `memory` (nullable), `created_at`, `updated_at`. Índices: `(owner_id)`, `(owner_id, name)` para el nombre único por usuario.
2. **Validación al crear la plantilla** — el runtime debe ser uno del registro; el modelo (si se elige) debe ser compatible con el runtime (mismos helpers de validación del selector actual). MCP servers: los del catálogo (`mcp_servers`).
3. **API v1** — `GET /api/v1/templates` (devuelve las plantillas del propietario), `POST` (crea: validación antes de persistir), `PUT /api/v1/templates/{id}` (edita: mismo contrato, solo owner o admin), `DELETE /api/v1/templates/{id}` (solo owner; admin puede borrar plantillas de otros). Filtros: `GET` no incluye plantillas de otros usuarios (y admin opcionalmente las ve: `GET` con `?all=true` solo admin).
4. **Flujo de creación web** — `Agents\Create`: selector de plantillas (desplegable + botón «usar plantilla»); al escoger, pre-rellenar runtime/modelo/limitaciones; el usuario puede seguir editando. La validación de creación se aplica tal cual (no se añade validación nueva a plantillas más allá de las del selector).
5. **Flujo de creación iOS** — `NewAgentSheet`: picker de plantillas (segmented picker + prellenado); mismo contrato (API v1).
6. **Per-admin** — admins no gestionan plantillas de otros usuarios en esta fase (solo lectura de `?all=true` para oversight); el enforcement queda en una fase posterior.
7. **Workspace** — las plantillas no incluyen workspace (se pide por creación); el workspace NO se guarda en plantilla (evita colisiones de rutas por usuario).

## Risks / Trade-offs
- [Plantilla referencia modelo borrado] → nil-safe: si el modelo es null (borrado), la plantilla se carga con el modelo sin seleccionar y la creación falla con el error habitual (modelo incompatible/ausente).
- [Owner cambia de email/borrado] → plantillas protegidas por owner_id; on usuario borrado, cascada de plantillas (cascade delete en migración — decisión: borrar las plantillas del usuario borrado, no dejar huérfanas).

## Migration Plan
- Nueva migración `agent_templates` con cascada de delete. Rollout: API + UI; rollback: dropear la tabla.

## Open Questions
Ninguna diferible. (Plantillas administrativas predefinidas quedan deliberadamente fuera de esta fase.)

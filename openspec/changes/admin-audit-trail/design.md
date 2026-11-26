# Design: Auditoría de acciones de administrador

## Context
Ver proposal.md — Why. Estado actual: `UserController`/`UserQueries`, `ModelController`, `McpController`, acciones de agentes y `SettingsController` mutan recursos del sistema admin sin registro persistente. No hay tabla de auditoría ni servicio de registro; los agentes/chats tienen `agent_events` (actividad de agentes, no de admin).

## Goals / Non-Goals
**Goals:**
- Cobertura total de acciones admin definidas en el proposal (usuarios, proveedores/modelos, MCP, agentes, toggle de registro).
- Operación atómica con la acción admin (mismo request, misma transacción) — la auditoría nunca se pierde en silencio.

**Non-Goals:**
- Retención o purga automática; firmas criptográficas; auditoría de lecturas; auditorías de agentes.

## Decisions

1. **Tabla `audit_log`** — columnas: `id`, `actor_id` (FK users, NOT NULL — todo admin tiene usuario), `action` (string, ~60), `target_type` (string, ~40: `user`/`provider`/`model`/`mcp`/`agent`/`settings`), `target_id` (string, nullable para `registration.toggled`), `metadata` (JSON, ~300 chars — p. ej. `{"role":"user"}`, `{"provider":"groq"}`), `created_at` (timestamp). Índices: `(actor_id, created_at)`, `(action, created_at)`, `(target_type, target_id)`. Índice B-tree en `created_at`.
2. **Servicio `AuditLogger::log($actor, $action, $targetType, $targetId, $metadata)`** — una única llamada desde el handler admin, dentro de la misma transacción (`DB::transaction`). Rationale: obligar a que la acción no suceda si la auditoría falla — la accountability jamás se sacrifica en silencios. Alternative: escribir post-commit o en job asíncrono — rechazado (perdería una fila temporal o nunca).
3. **Cobertura de acciones** — reutilizar los puntos de entrada existentes (no nuevos endpoints): `UserController`/`Admin` (alta, rol, baja, reactivación, reseteo, borrado), `ModelController`/`ProvidersIndex` (create/update/delete), `McpController` (create/update/delete), `UserManagement::destroy` (agentes borrados), `SettingsController` (toggle de registro). Cada handler invoca `AuditLogger::log(...)` dentro de la transacción.
4. **Consulta web (Livewire)** `/audit` — filtros (actor, acción, rango) + paginación; `role:admin`; ruta en `web.php`.
5. **API v1** — `GET /api/v1/audit?action=&actor=&target_type=&from=&to=&page=`; `role:admin`; shape: `{ data: [{ id, actor: {id, name}, action, target_type, target_id, metadata, created_at }], total, page }`. Paginado, N=50 techo.
6. **Sin actualización/borrado de entradas** — no hay `PUT`/`DELETE` ni comandos de purga; la API solo expone `GET`.
7. **Retención** — sin purga en esta versión (decisión documentada); si algún día se purge, el diseño requiera una tarea explícita confirmable.

## Risks / Trade-offs
- [Credibilidad: las entradas crecen] → índice + paginación; borrar entradas solo por decisión explícita de producto.
- [La escritura es una escritura extra en el camino admin] → 1 fila por operación; índice cubierto; overhead despreciable.
- [Metadata puede contener PII] → metadata limitado a valores no sensibles (role, provider name — nunca tokens, passwords); nunca registran inputs de contraseña.
- [Un admin que borra su propio audit_log] → no hay endpoints PUT/DELETE; la tabla no tiene delete path por diseño.

## Migration Plan
- Nueva migración `audit_log`. Reversible (solo lectura). Sin datos migrados.
- Rollout: cada handler gana la llamada dentro de su transacción; la vista `/audit` es nueva (retrocompatible).
- Rollback: `DROP` la tabla; los handlers vuelven a su flujo sin auditoría (sin cambio de specs).

## Open Questions
Ninguna diferible (retención queda deliberadamente fuera).

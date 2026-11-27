# Spec Delta

## Purpose
Registro inmutable de las acciones críticas de administrador (usuarios, proveedores, modelos, MCP y toggles de configuración) que permiten reconstruir qué hizo un admin, cuándo y sobre qué recurso, visible en la web y en la API v1.

## ADDED Requirements

### Requirement: Auditable admin actions
El sistema SHALL registrar, de forma atómica con la operación admin correspondiente, una entrada de auditoría para cada operación admin aplicable: `user.created`, `user.updated`, `user.disabled`, `user.reactivated`, `user.password_reset`, `user.deleted`, `registration.toggled`, `provider.created`, `provider.updated`, `provider.deleted`, `model.created`, `model.updated`, `model.deleted`, `mcp.created`, `mcp.updated`, `mcp.deleted`, `agent.destroyed`.

#### Scenario: User deletion
- **WHEN** un admin borra a un usuario
- **THEN** el sistema registra la entrada con actor (admin que hizo el borrado), la acción `user.deleted`, el objetivo (id/email del usuario borrado) y el momento del borrado

#### Scenario: Atomicity on failure
- **WHEN** la escritura de la entrada de auditoría falla dentro de la misma transacción de la operación admin
- **THEN** la operación admin no se aplica y no queda ningún efecto parcial

#### Scenario: Same-request atomicity
- **WHEN** un admin completa una operación auditable (p. ej. crear un usuario)
- **THEN** la entrada de auditoría existe en la misma transacción que el cambio de recurso

### Requirement: Audit log browsing
El administrador SHALL poder consultar el log de auditoría en la web (con filtros por actor, acción, objetivo y rango de fechas) y vía API v1 (`GET /api/v1/audit`), con paginación; la consulta SHALL estar restringida a admins.

#### Scenario: Filter by actor
- **WHEN** un admin filtra el log por actor y acción
- **THEN** la vista y la API responden exactamente las entradas que coinciden con ambos filtros y la paginación respeta el total

#### Scenario: Audit by API
- **WHEN** un admin llama a `GET /api/v1/audit?action=user.created&actor=<id>`
- **THEN** la API responde con las entradas coincidentes, ordenadas por fecha descendente

### Requirement: Read-only audit entries
El log de auditoría SHALL ser de solo lectura para cualquier usuario (incluidos los admins): el sistema SHALL exponer creación a través del hook en el handler admin, pero no actualización ni borrado de entradas.

#### Scenario: No update endpoint
- **WHEN** cualquier cliente intenta modificar o borrar una entrada de auditoría (vía web o API)
- **THEN** el sistema rechaza la operación con 403/404 y la entrada permanece exactamente como fue creada

# Proposal: Auditoría de acciones de administrador

## Why
El panel de admin crea/edita/borra usuarios, proveedores, modelos, servidores MCP y agentes. Varias de esas operaciones son irreversibles o de impacto amplio (un proveedor borrado rompe la inferencia de todos sus agentes; un usuario borrado invalida sesiones y detiene sus agentes), y hoy no queda **ningún registro durable** de quién hizo qué ni cuándo. Antes de dar el servicio a un equipo, un auditor debe poder reconstruir cualquier operación crítica.

## What Changes
- Nueva tabla `audit_log` (actor, acción, tipo/id de objetivo, metadatos JSON, timestamp) con escritura inmutable en el mismo request de la operación admin.
- Cobertura de las acciones admin actuales: gestión de usuarios (alta, rol, baja, reactivación, reseteo de contraseña, borrado), proveedores/modelos (alta/edición/borrado), MCP (alta/edición/borrado), borrado de agentes (admin) y toggle de registro público.
- Vista admin (web) de auditoría con filtros por actor/acción/fecha y paginación, más endpoint API v1 (`GET /api/v1/audit`).
- La auditoría no se reescribe: la política de retención (si existe un momento de purgado) es explícita y no entra en esta fase.

**Out of scope:** retención/purga automática de filas (decisión de producto), registros inmutables con firma criptográfica, auditoría de lecturas (listados), acciones automatizadas de agentes (los agentes no son "admin").

## Capabilities

### New Capabilities
- `admin-audit`: catálogo de acciones admin registradas, consulta por admin (web y API v1), con invariantes de inmutabilidad.

### Modified Capabilities
*(ninguna — las acciones admin existentes cambian de comportamiento pero no sus specs)*

## Impact
- Migraciones + un servicio `AuditLogger`; los puntos de entrada existentes (`UserController`, `ModelController`/Providers admin, `Mcp` admin, acciones de agentes) ganan un hook de auditoría sin cambiar su contrato.
- Nueva vista Livewire admin + endpoint API v1 bajo `role:admin`.
- Debe ser barata de escribir (1 fila por acción) y segura (solo admin la lee).

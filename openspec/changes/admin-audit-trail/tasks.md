# Tasks

## 1. Migración y servicio

- [ ] 1.1 Crear migración `audit_log` (id, actor_id, action, target_type, target_id, metadata JSON, created_at) + índices; verificar con `php artisan migrate` y `php artisan test` (migración verde)
- [ ] 1.2 Crear `AuditLogger` con `log(actor, action, targetType, targetId, metadata)`; verificar con test unit que la escritura en una transacción fallida hace rollback

## 2. Hooks de cobertura admin

- [ ] 2.1 Enlazar `AuditLogger` en `UserController`/`Admin` (alta, rol, baja, reactivación, reseteo, borrado usuarios); verificar con feature test que cada acción produce su entrada de auditoría en la misma transacción
- [ ] 2.2 Enlazar `AuditLogger` en `ModelController`/`Providers` (alta/edición/borrado provider y model) y en `McpController` (alta/edición/borrado MCP); verificar con API test que cada acción produce su entrada
- [ ] 2.3 Enlazar `AuditLogger` en borrado de agentes (admin) y en toggle de registro público (`SettingsController`); verificar con test que las entradas de `agent.destroyed` y `registration.toggled` se registran

## 3. Consulta web + API v1

- [ ] 3.1 Vista Livewire admin `/audit`: filtros (actor, acción, objetivo, rango de fechas) + paginación (`role:admin`); verificar con feature test que un admin filtra y ve el total correcto
- [ ] 3.2 API v1 `GET /api/v1/audit`: filtros + paginación; 403 para no admins; verificar con API test que filtros y pagination responden correctamente

## 4. Verificación final

- [ ] 4.1 Suite completa + docs (sección de auditoría en docs/deployment.md); verificar con `php artisan test` verde

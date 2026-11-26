# Tasks

## 1. Consulta agregada

- [ ] 1.1 Servicio de agregados (`HealthSummary`): `agents_by_status`, `recent_failures` (LIMIT 20), `providers` con estado; verificar con test unit que cada sección del resumen es correcta contra datos de prueba

## 2. Vista web + API

- [ ] 2.1 Página Livewire `/health` (`role:admin`): desglose de agentes por estado, últimos fallos y proveedores; refresh cada 10s; verificar con feature test que el admin ve los números correctos y un no admin recibe 403
- [ ] 2.2 API v1 `GET /api/v1/health/summary`: shape documentado en el spec; verificar con API test que el shape es correcto y que no admins reciben 403

## 3. Verificación final

- [ ] 3.1 Suite completa + docs; verificar con `php artisan test` verde

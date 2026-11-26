# Tasks

## 1. Migración y servicio de uso

- [ ] 1.1 Crear migración `model_usage` (agent_id, provider_id, model_id, tokens_input, tokens_output, status, latency_ms, stream, created_at) + índices; verificar con `php artisan migrate` y `php artisan test`
- [ ] 1.2 Crear servicio de persistencia en el proxy (write post-response, best-effort); verificar con feature test del proxy que la fila se persiste aunque el upstream responda con 0 tokens

## 2. Vista admin + API v1

- [ ] 2.1 API v1 `GET /api/v1/usage` (agent_id, provider_id, fechas, paginación `role:admin`); verificar con API test que los agregados son correctos para el periodo seleccionado
- [ ] 2.2 Vista web admin (`/usage` o sección en Providers/Models): agregados por agente/proveedor + paginación; verificar con feature test que el admin ve los totales correctos y un no admin no puede entrar

## 3. Verificación final

- [ ] 3.1 Suite completa + actualización de docs/; verificar con `php artisan test` verde

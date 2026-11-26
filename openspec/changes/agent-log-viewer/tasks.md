# Tasks

## 1. Consulta de log

- [ ] 1.1 Endpoint API v1 `GET /api/v1/agents/{id}/logs?tail=` con acceso (owner/admin); verificar con API test que un no autorizado recibe 403 y un owner recibe tail con el formato esperado
- [ ] 1.2 Servicio de tail server-side (reutilizar `DockerClient::logs`); verificar con unit test que la consulta realiza `docker logs --tail N` y maneja el caso no-container/unavailable

## 2. Web + iOS

- [ ] 2.1 Sección «Logs» en `Agents\Detail` (selector de tamaño, texto plano con scroll, copiar, escape HTML); verificar con feature test que un owner ve las líneas y un no admin no
- [ ] 2.2 LogsSheet en iOS `AgentDetailView` (mismo endpoint, texto monospaced, copia); verificar con unit test del DTO y test del flujo

## 3. Verificación final

- [ ] 3.1 Suite completa + docs; verificar con `php artisan test` verde

# Tasks

## 1. Migración y servicio

- [x] 1.1 Migración `agent_templates` + cascada de delete; verificar con `php artisan migrate` y test unit de created/destroy
- [x] 1.2 Servicio de validación de plantillas (runtime, modelo, MCP, límites); verificar con test que una plantilla con modelo incompatible es rechazada

## 2. API v1

- [x] 2.1 API v1 `GET/POST/PUT/DELETE /api/v1/templates` (owner-only + admin read all); verificar con API test que las plantillas se crean/editan/borran y que un no admin no puede borrar plantillas de otros

## 3. UI web + iOS

- [x] 3.1 Web (`Agents\Create`): picker de plantillas + prellenado (runtime/modelo/MCP/internet/límites); verificar con feature test que la plantilla pre-rellena y el usuario puede modificar y crear
- [x] 3.2 iOS (`NewAgentSheet`): mismo picker (API v1); verificar con unit test que la selección pre-rellena el formulario

## 4. Verificación final

- [x] 4.1 Suite completa + docs; verificar con `php artisan test` verde

# Tasks

## 1. Infraestructura de búsqueda

- [ ] 1.1 Migración: columna generada `body_tsvector` + GIN índice sobre `chat_messages.body`; verificar con test que la columna se rellena y se indexa
- [ ] 1.2 Servicio de búsqueda con paginación y ranking (`ts_rank`); verificar con feature test que la query paginada devuelve resultados ordenados por relevancia y respeta `agent_id`

## 2. API v1 + web

- [ ] 2.1 API v1 `GET /api/v1/agents/{id}/messages?q=`: paginación + filtro de acceso; verificar con API test que un no autorizado recibe 403 y un admin recibe resultados por relevancia
- [ ] 2.2 Web (`Agents\Chat`): buscador con debounce (~250ms), resultados clicables (scroll al mensaje) + exportación md/json vía endpoint; verificar con feature test que los resultados aparecen y la exportación descarga

## 3. iOS

- [ ] 3.1 Intégrer buscador de chat + exportación en iOS (buscador arriba de la lista; exportación via share sheet); verificar con unit test del DTO y test del flow

## 4. Verificación final

- [ ] 4.1 Suite completa + docs; verificar con `php artisan test` verde

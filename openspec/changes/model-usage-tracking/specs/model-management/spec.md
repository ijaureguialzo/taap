# Spec Delta

## ADDED Requirements

### Requirement: Model usage persistence
El sistema SHALL persistir, por petición a través del Model Proxy, una entrada de uso con el agente, el proveedor, el modelo, los tokens de entrada/salida (cuando el upstream los declare), el código de estado upstream y la latencia, para consolidar costes y detectar comportamientos anómalos. La persistencia SHALL ser best-effort: si la escritura falla, la respuesta del proxy se sirve igualmente.

#### Scenario: Usage recorded per request
- **WHEN** un agente hace una petición al Model Proxy
- **THEN** se persiste una entrada con agente, proveedor, modelo, tokens entrada/salida, estado y latencia

#### Scenario: Usage write failure does not break inference
- **WHEN** la escritura de la entrada de uso falla
- **THEN** el proxy devuelve la respuesta al agente sin cambio

#### Scenario: Streaming response
- **WHEN** un cliente pide una respuesta en streaming y el upstream la devuelve
- **THEN** la entrada de uso refleja lo que el proxy sintetiza de la respuesta (tokens al final de stream = 0 si el upstream no lo declara)

### Requirement: Model usage aggregates (admin)
El sistema SHALL exponer agregados de uso por agente y por proveedor —requests, tokens entrada/salida, errores y latencia media— para el administrador, tanto en la vista web como vía API v1. Los agregados SHALL reproducirse por periodo y SHALL estar restringidos a admins.

#### Scenario: Aggregates by agent
- **WHEN** un admin consulta los agregados de uso de un agente
- **THEN** la respuesta muestra requests, tokens entrada/salida, errores y latencia media para ese agente en el periodo seleccionado

#### Scenario: Aggregates by provider
- **WHEN** un admin consulta los agregados de uso de un proveedor
- **THEN** la respuesta muestra los mismos agregados limitados a todas las peticiones de ese proveedor en el periodo seleccionado

#### Scenario: Non-admin denied
- **WHEN** un usuario no admin consulta los agregados de uso vía API v1
- **THEN** el sistema responde con error 403

### Requirement: Model usage endpoint (API v1)
El sistema SHALL exponer `GET /api/v1/usage` con filtros `agent_id`, `provider_id` y rangos de fechas, respondiendo con la forma `{ data, total, page, ... }` paginada, accesible solo para admins.

#### Scenario: Usage by API with filters
- **WHEN** un admin llama a `GET /api/v1/usage?agent_id=<id>&from=<fecha>&to=<fecha>`
- **THEN** la API responde con las entradas de uso coincidentes y sus agregados en el periodo

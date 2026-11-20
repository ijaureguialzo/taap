# Spec Delta

## ADDED Requirements

### Requirement: Responses wire passthrough
El Model Proxy SHALL servir passthrough del wire `responses` de la API OpenAI
(`/v1/responses`): autenticar con el token del agente, registrar el request en el
log, y reenviar la petición y la respuesta (JSON y `text/event-stream`) al
proveedor sin transformación del cuerpo — el runtime Codex habla Responses
nativo y el proxy no conoce su esquema. Los proveedores que no soportan
`/v1/responses` devolverán un error claro del proxy (501/400) en lugar de
silenciar el request.

#### Scenario: Codex streams through the proxy
- **WHEN** un agente con runtime Codex llama a `/v1/responses` con su token
  **THEN** el proxy reenvía al proveedor la petición intacta y regresa la
  respuesta (incluido el stream SSE) sin alterar el cuerpo

#### Scenario: Provider without Responses support
- **WHEN** un request `/v1/responses` llega a un proveedor cuyo upstream no
  implementa la ruta
  **THEN** el proxy devuelve un error legible (no un 200 vacío ni un hang) y lo
  registra en el log de peticiones

# Spec Delta

## ADDED Requirements

### Requirement: Failure notification webhook
El sistema SHALL disparar una notificación HTTP saliente (webhook) cuando un agente transiciona a estado «error», con la configuración del webhook (URL y secreto HMAC) persistida en la configuración del admin. El payload SHALL incluir agente, runtime, causa, timestamp y URL de detalle. La notificación SHALL ser best-effort: si falla, no bloqueará la transición del agente, pero SHALL mostrarse en el panel admin.

#### Scenario: Agent failure triggers webhook
- **WHEN** un agente transiciona a «error» con el webhook habilitado
- **THEN** el sistema envía POST al webhook con el payload estructurado y el secreto HMAC en cabeceras

#### Scenario: Disabled webhook does not fire
- **WHEN** el webhook está desactivado (URL ausente) y un agente transiciona a error
- **THEN** no se envía ninguna notificación y la transición procede normalmente

#### Scenario: Retry on transient failure
- **WHEN** el webhook responde con un error transitorio (5xx o timeout)
- **THEN** el sistema reintenta con backoff acotado (máx. 3 intentos) y registra el desenlace sin afectar al agente

#### Scenario: Webhook secret never leaks
- **WHEN** el webhook falla o se loguea cualquier error
- **THEN** el secreto HMAC nunca aparece en logs o payloads

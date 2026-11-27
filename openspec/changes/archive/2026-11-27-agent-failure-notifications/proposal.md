# Proposal: Webhook saliente al fallar un agente

## Why
Un agente en estado error solo lo ve el dueño en su chat (timeline + estado en el header); nada alerta a un operador ni a un sistema externo. Para una pila 24/7 esto es un cuello de botella operativo: el usuario puede estar fuera del chat mientras el agente falla y nadie lo sabe hasta que lo revisa de casualidad.

## What Changes
- Hook de webhook saliente (URL configurable + secreto + filtro de eventos) configurado por admin en `settings` (`Support\Settings`), fired cuando un agente transiciona a un estado de fallo (error/stopped no esperado, runtime incompatible).
- Payload estructurado: agente, runtime, estado viejo/nuevo, causa, timestamp, URL de detalle.
- Cadena de reintentos acotada con backoff; fallos logueados y visibles en el panel (no romper el flujo de la transición por telemetría).

**Out of scope:** push/notificaciones push mobile; email (no hay SMTP); sistemas de pager/alerting externos (el webhook es el contrato; el operador conecta lo que necesite).

## Capabilities

### New Capabilities
*(ninguna)*

### Modified Capabilities
- `agent-lifecycle`: los fallos de agente producen una notificación externa además de las ya visibles en chat/estado.

## Impact
- Nueva tabla/config (`settings`), nueva acción de notificación en la llamada de ciclo de vida (transición de estado), panel admin para editar URL/secreto.
- No cambia los estados públicos del agente; añade un camino de side-effect en la transición.
- Tests: transición a fallo → webhook disparado (webhook local de test), secretos nunca en logs.

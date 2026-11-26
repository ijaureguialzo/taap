# Design: Webhook saliente al fallar un agente

## Context
Ver proposal.md — Why. Estado actual: transiciones de estado se transmiten por el canal existente (`Broadcast` events) a clientes autorizados; no hay notificación externa. Settings se persisten en `settings` (`Support\Settings`) con modelo de defaults.

## Goals / Non-Goals
**Goals:**
- Notificación saliente al operador cuando un agente falla (error).

**Non-Goals:**
- Push mobile; email; alerting externo (el webhook es el contrato).

## Decisions

1. **Configuración** — claves `failure_webhook_url` y `failure_webhook_secret` en `settings` (modelo `Support\Settings`); la URL nunca se muestra completa en la UI (solo host); el secreto se enmascara. El webhook está desactivado por defecto (URL vacía).
2. **Payload** — POST JSON `{ event: 'agent.failed', agent_id, name, runtime, status_from, status_to: 'error', cause, at, url }`; cabeceras `X-TaaP-Event: agent.failed`, `X-TaaP-Signature: sha256=<hmac(secret, body)>`, `Content-Type: application/json`.
3. **Disparo** — en la transición de estado del agente (el hook que emite los eventos/status), encolado como job (`SendAgentFailureNotification`) respetando `queue` existente — nunca dentro del camino crítico de la transición; si el job falla, queda registrado en logs y visible en el panel admin.
4. **Reintentos** — 3 intentos con backoff 10s/30s/60s (vía `$tries`/`$backoff` de la cola). Fallos permanentes (4xx del webhook: URL rota, auth) no reintentan.
5. **Panel admin** — en la página `/health` (o el panel existente de configuración) un resumen de las notificaciones enviadas (último intento, resultado) — no reconstruimos una tabla de auditoría nueva en esta fase (reutilizada via las tablas existentes o agregado simple; decisión: mantener resumen mínimo sin tabla nueva — solo logs + última respuesta visible).
6. **Seguridad** — el secreto HMAC solo vive en `settings`; nunca en logs ni payloads; la firma usa `hash_hmac('sha256', $body, $secret)`. Documentar que el receptor debe verificar la firma antes de procesar.
7. **No enforcement** — no bloquea ni arresta el agente por fallos del webhook; best-effort con reintentos acotados.

## Risks / Trade-offs
- [Webhook rota o mal configurado] → entradas visibles en el panel; la transición del agente no se ve afectada (best-effort).
- [Payload revela info de agente] → restringido a URL/secreto admin-only; los receptores no son público; documentación de verificación HMAC.
- [Reintentos en cola floja] → backoff apropiado; si la cola se satura, el job se marca fallido (no se pierde — los logs conservan el desenlace).

## Migration Plan
- Sin migraciones de datos (settings es clave→valor). Rollout: claves nuevas en `settings` (default: deshabilitado). Rollback: borrar las claves (webhook desaparece sin afectar al resto del sistema).

## Open Questions
Ninguna diferible.

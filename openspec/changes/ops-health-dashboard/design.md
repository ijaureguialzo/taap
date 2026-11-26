# Design: Panel de salud operativo

## Context
Ver proposal.md — Why. Estado actual: los datos ya persisten — `agents.status` (vía bridge + `Broadcast` eventos), `agent_events` (fallos de agente), `model_providers.status`/`last_checked_at` (salud persistida). No existe vista agregada ni endpoint de resumen.

## Goals / Non-Goals
**Goals:**
- Una vista única de salud para el admin que no requiere abrir cada agente/proveedor.
- Mismo dato vía API v1 para web e iOS.

**Non-Goals:**
- Endpoint de métricas (Prometheus); alertas externas (ver `agent-failure-notifications`); historial de salud por proveedor.

## Decisions

1. **Fuente de verdad: datos existentes, sin tablas nuevas** — `agents.status` (lectura en la vista), `model_providers` (status + last_checked_at) y `agent_events` (fallos). El panel es una capa de agregación sobre datos ya consistentes.
2. **Agrupamiento en queries SQL simples** — agentes por estado: `GROUP BY status` de los agentes visibles (admin: todos); últimos fallos: `agent_events` ordenado por `created_at DESC LIMIT 20` con `WHERE lower(message) LIKE '%error%'`; proveedores: `SELECT name, status, last_checked_at FROM model_providers`.
3. **Vista web: página Livewire `/health` (`role:admin`)** — nueva página en el layout admin; auto-refresh mediante Livewire polling de 10s (`$refreshInterval`); evita nueva infra de WebSocket para una vista admin (los agentes ya tienen canal en vivo para chat, no reusamos).
4. **API v1** — `GET /api/v1/health/summary` (admin): shape `{ agents_by_status: {...}, recent_failures: [{ agent_id, name, runtime, cause, at }], providers: [{ id, name, status, last_checked_at }] }`.
5. **iOS** — consumo del mismo endpoint (sheet/panel en la app); no bloquea esta fase (iOS en follow-up, opcional).
6. **Privacidad** — el panel no expone tokens, prompts ni secretos; el shape de la API no incluye datos de usuarios ni archivos.

## Risks / Trade-offs
- [Polling 10s en la vista admin] → aceptable para una vista admin; el canal WebSocket en vivo es costoso y está sobre-diseñado para un admin.
- [Fallos de agentes pueden no tener `agent_events` para el runtime usado] → fallback: también leer `agents.status` reciente (última transición a error en `agents` si no hay evento).
- [Los proveedores no sanos pueden no haber sido consultados aún] → mostrar "sin comprobar" cuando `last_checked_at` es null (no confundirlo con sano).

## Migration Plan
- Sin migraciones (sin tablas nuevas). Rollout: ruta `/health` + API; rollback: borrar ruta (retrocompatible).

## Open Questions
Ninguna diferible.

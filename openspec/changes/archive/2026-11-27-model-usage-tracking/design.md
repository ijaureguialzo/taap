# Design: Seguimiento de uso de modelos

## Context
Ver proposal.md — Why. Estado actual: `ModelProxyController` registra cada petición (`Log::info('ModelProxy: req ...')`), parsea `usage` del cuerpo no-stream y del síntesis de SSE al sintetizar la stream, y `CodexAdapter` loguea usage en `Log::debug`. No existe persistencia ni agregado. El proveedor tiene `status` y `last_checked_at` (salud); el uso no tiene ninguna traza.

## Goals / Non-Goals
**Goals:**
- Persistencia durable del uso del proxy (por petición: agente/proveedor/modelo/tokens/estado/latencia).
- Vista admin de agregados (por agente y por proveedor) + endpoint API v1.

**Non-Goals:**
- Enforcement/cuotas de coste (capa posterior).
- Contabilidad chunk-a-chunk de streams (mejores esfuerzos con la síntesis existente).
- Facturación monetaria.

## Decisions

1. **Tabla `model_usage`** — `id`, `agent_id` (FK, NOT NULL, indexado), `provider_id` (FK, nullable, indexado), `model_id` (FK, nullable), `tokens_input` (int), `tokens_output` (int), `status` (int — código HTTP upstream), `latency_ms` (int), `stream` (bool — si el cliente pidió stream), `created_at` (timestamp, indexado). Índices: `(agent_id, created_at)`, `(provider_id, created_at)`, `(created_at)`.
2. **Escritura en el camino del proxy, best-effort** — en `ModelProxyController` (y `CodexAdapter` para su camino), la escritura ocurre al final de la respuesta (no en el final de la transacción: la persistencia de telemetría nunca bloquea o corrompe la inferencia — si falla, se loguea y se sirve la respuesta). Rationale: accountability≠telemetría; la regla es que el uso never road-block the inference path. Alternatives: guardado transactional (rechazado para telemetría — el contrato de uso es best-effort); otro camino de logs post-hoc (rechazado — la escritura en el momento del evento es la más simple y determinista).
3. **Tokens** — OpenAI/Responses: `usage` del cuerpo no-stream, o el último chunk SSE (el proxy sintetiza el chunk final con usage). Anthropic: `usage` del cuerpo o `usage` en `message_delta`. Fallback: 0 tokens (la fila persiste el estado y latencia igualmente).
4. **Agregados calculados en SQL en cada consulta** — `GROUP BY` (nunca materialized views: los agregados deben ser correctos y simples; el volumen de filas de un proxy de agente local es modesto). Per periodo: `COUNT(*)`, `SUM(tokens_input)`, `SUM(tokens_output)`, `SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END)`, `AVG(latency_ms)`.
5. **API v1** — `GET /api/v1/usage?agent_id=&provider_id=&from=&to=&page=`; shape `{ data: [usage rows], total, page, per_agent: {...}, per_provider: {...} }`; `role:admin`; techo de página 100.
6. **Vista web admin** — nueva sección dentro de `Models/Providers` admin o una página `/usage`; tabla con agregados por agente/proveedor + drill-down a las filas. Beneficia a ambos lados sin nueva ruta de navegación.
7. **No se mezclan contadores** — los usage de runtime no entran en esta tabla; `agent_events` sigue manejando la actividad del agente (qué herramienta/estado); uso = tokens/estado del modelo.

## Risks / Trade-offs
- [Filas de uso crecen sin tope] → índices + paginación; retención análoga a la auditoría queda deferida.
- [Stream ambigüa latencia/tokens] → best-effort; la fila persiste estado y latencia aunque los tokens sean 0.
- [Migración de filas existentes] → no hay datos existentes que migrar; el período empieza en el merge.
- [El agregado por periodo no se materializa] → consultas costosas; mitigación: paginación + índices; re-evaluate en fase 2 si el volumen lo exige.

## Migration Plan
- Nueva migración `model_usage`. Reversible (solo lectura; dropear tabla).
- Rollout: `ModelProxyController` gana la escritura en el mismo request (best-effort); las vistas y API son nuevas (retrocompatibles).
- Rollback: drop de tabla + revertir la escritura; la inferencia sigue funcionando.

## Open Questions
Ninguna diferible. (Retención y enforcement quedan deliberadamente fuera.)

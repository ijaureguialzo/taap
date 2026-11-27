# Design: Búsqueda y exportación del chat

## Context
Ver proposal.md — Why. Estado actual: los mensajes del chat se persisten (`chat_messages`) y se muestran con streaming y timeline; sin búsqueda ni exportación. El catálogo de búsqueda es **Por-agente** (más simple que global; la UI vive dentro del detalle del agente).

## Goals / Non-Goals
**Goals:**
- Búsqueda full-text (por agente, por paginación) con ranking.
- Exportación Markdown/JSON de la historia completa.

**Non-Goals:**
- Búsqueda semántica (embeddings); búsqueda global multi-agente (deferred); búsqueda de logs (ver `agent-log-viewer`).

## Decisions

1. **Búsqueda Postgres full-text** — columna generada `tsvector` sobre `messages.body` (español e inglés; `tsvector` con `'simple'` config para no requerir configuración de stemming, y ranking con `ts_rank`), GIN índice. Query: `WHERE agent_id = ? AND body_tsvector @@ websearch_to_tsquery('simple', ?)` + orden `ts_rank DESC`. Alternative: LIKE (rechazado: lento y sin ranking).
2. **Paginación** — `page`/`per_page=50` con total. Query paginada sobre la búsqueda; los resultados son mensajes (id, role, body, reasoning, created_at) — sin metadatos internos.
3. **Exportación** — `GET /api/v1/agents/{id}/messages/export?format=md|json`, `role:admin` o owner: orden cronológico, sin paginación (los chats no tienen millones de mensajes en esta app); markdown fino (no necesita conversión de markdown — solo encabezados y cuerpos, con saltos de línea preservados); JSON con forma documentada en el spec.
4. **Web** — búsita en el chat: input en la barra del chat; al escribir, llama a un listener o a un endpoint de búsqueda paginado con debounce (~250ms); lista de resultados clickables (navegan al mensaje — scroll + highlight). Exportación: botón «Exportar» (dropdown md/json) que dispara la descarga.
5. **iOS** — buscador arriba en la lista del chat; resultados compactos con scroll; exportación vía sheet con opciones md/json (descarga vía share sheet).
6. **Razonamiento** — exportado con la misma estructura que el historial; no se incluye en la búsqueda (el texto del mensaje es la superficie de búsqueda; el razonamiento es un delta adicional — decisión: no está en la búsqueda; si se quiere, fase 2).
7. **Acceso** — mismo patrón que el chat (owner + admin); no se exporta más allá de eso.

## Risks / Trade-offs
- [Índice tsvector sobre la tabla de mensajes existente] → migración con backfill; mensajes nuevos generan la columna automáticamente (generado).
- [Chats muy grandes] → la paginación de exportación (`md`/`json` sin límite) puede crecer; aceptado (el export es sobre demanda, no vía API bulk).
- [La búsqueda es solo en castellano/inglés] → `simple` config con ranking honesto; no se promete stemming.

## Migration Plan
- Migración: `chat_messages` gana columna generada `body_tsvector` + GIN índice. No toca datos existentes (la columna se rellena en la migración).
- Rollout: UI + endpoints nuevos (retrocompatible). Rollback: dropear la columna.

## Open Questions
Ninguna diferible. (Búsqueda global y embeddings quedan fuera deliberadamente.)

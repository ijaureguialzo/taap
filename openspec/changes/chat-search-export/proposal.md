# Proposal: Búsqueda y exportación del chat

## Why
Los mensajes y el historial del chat se persisten y se muestran con streaming y timeline, pero son **no buscables ni exportables** — un usuario que vuelve después de unos días no puede encontrar un mensaje específico ni sacarlo a formato Markdown/JSON para un segundo chequeo.

## What Changes
- Búsqueda full-text sobre mensajes del chat (por agente, y/o global), con resultados filtrables y paginación: web (dentro de la vista del chat) y API v1 (`GET /api/v1/agents/{id}/messages?q=…`).
- Exportación del historial del chat a Markdown/JSON (con streaming de errores? no — salida completa), botón en el chat + `GET /api/v1/agents/{id}/messages/export`.
- Búsqueda servidor-side (trigramas/índice pgsql) con rendimiento acotado a los mensajes persistidos.

**Out of scope:** búsqueda semántica (embeddings) — capa posterior; búsqueda de logs/timeline de agentes (ver `agent-log-viewer`); exportación de timeline.

## Capabilities

### New Capabilities
*(ninguna)*

### Modified Capabilities
- `agent-chat`: el historial gana búsqueda full-text y exportación.

## Impact
- Migración de índices GIN/trigram + servicio de búsqueda; query/export endpoints en `ChatController`.
- Livewire (`Agents\Chat` vista) + SwiftUI (`AgentDetailView`) consumen los mismos endpoints.
- Sin cambios de contrato existente para mensajes existentes; los listados del chat existentes se mantienen.

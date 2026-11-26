# Proposal: Visor de logs del agente

## Why
Los logs del contenedor del agente existen (son el stdout del proceso de runtime dentro del contenedor) pero **solo son alcanzables desde el host** (`docker logs`): no hay superficie alguna en web o iOS para que el dueño o el admin vea lo que ha hecho el agente, ni en tiempo real, ni como archivo histórico. Esto extiende el chat (que muestra la actividad conversacional) pero no cubre la depuración del runtime.

## What Changes
- Visor read-only en la ficha del agente (web + iOS): los últimos N líneas del log del contenedor, con refresco manual o en vivo (canal websocket o polling acotado).
- El log se materializa servidor-side (streaming de logs del contenedor + búfer acotado), sin exponer detalles internos a usuarios sin permiso.
- Filtrado por línea/rango de tiempo opcional; copy-to-clipboard para el usuario.

**Out of scope:** parsing estructurado de logs (p. ej. errores de runtime por categoría); retención histórica (el log es efímero — solo el tail visible); streams de admin global.

## Capabilities

### New Capabilities
- `agent-log-viewer`: visor de logs del contenedor del agente a través de la API, con tail visible al dueño/admin (web y iOS).

### Modified Capabilities
*(ninguna)*

## Impact
- Servicio/bridge que lee los logs del contenedor (vía `DockerClient` o flujo supervisado del bridge); nuevo endpoint API v1 (`GET /api/v1/agents/{id}/logs`).
- `Agents\Detail` + SwiftUI `AgentDetailView` ganan el visor; i18n es/en/eu + dark mode.
- No cambia el flujo de mensajes: los logs quedan separados de la timeline del chat.

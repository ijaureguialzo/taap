# agent-log-viewer Specification

## Purpose

Visor read-only del tail de logs del contenedor del agente (web y iOS), accesible al propietario y al admin, para depurar la actividad del runtime sin acceso de host.

## Requirements

### Requirement: Agent log tail
El sistema SHALL exponer los últimos N líneas del log del contenedor del agente (default 200, selector 50/200/500) vía API v1 (`GET /api/v1/agents/{id}/logs`) y como sección read-only en la ficha del agente (web y iOS). La salida SHALL ser texto plano sin interpretación (el usuario no edita ni reescribe logs).

#### Scenario: View tail on web
- **WHEN** un usuario autorizado abre la sección de logs de un agente
- **THEN** la vista muestra las últimas N líneas del log del contenedor (texto plano, scroll, copiar al portapapeles) y el selector de tamaño (50/200/500)

#### Scenario: View tail on iOS
- **WHEN** un usuario autorizado abre la sección de logs de un agente en la app iOS
- **THEN** la vista muestra las mismas líneas del log, idénticas al web

#### Scenario: Log tail by API
- **WHEN** un usuario autorizado llama a `GET /api/v1/agents/{id}/logs?tail=200`
- **THEN** la API responde con las últimas 200 líneas del log y nulo/403 para no autorizados

### Requirement: Log tail access control
El log del agente SHALL estar disponible solo para el propietario del agente y para admins (misma regla de acceso que chat y timeline); el sistema SHALL no exponer logs de otros agentes.

#### Scenario: Non-owner denied
- **WHEN** un usuario que no es propietario ni admin llama a `GET /api/v1/agents/{id}/logs`
- **THEN** el sistema responde con 403 y no expone contenido

### Requirement: No log retention
El tail de logs SHALL ser efímero: no hay historia ni archivo persistente de logs (el docker stream es el único origen); si el contenedor no existe o no tiene logs, la API responde con la salida vacía y un aviso explícito.

#### Scenario: Missing container
- **WHEN** el agente no tiene contenedor (detenerse o borrar)
- **THEN** la API responde con `{ tail: [], status: 'no-container' }` y la vista muestra el aviso explícito

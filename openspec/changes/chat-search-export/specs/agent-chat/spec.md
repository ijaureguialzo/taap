# Spec Delta

## ADDED Requirements

### Requirement: Chat search
El sistema SHALL permitir buscar los mensajes del chat de un agente (texto del mensaje, user y agent), con paginación y ranking por relevancia, sobre la web y vía API v1. Los resultados SHALL restringirse al propietario y a los admins (la misma regla de acceso que el chat) y SHALL no exponer otros datos del mensaje más allá del texto y la fecha.

#### Scenario: Search on web
- **WHEN** un usuario autorizado escribe un término en el buscador del chat
- **THEN** la lista de resultados muestra los mensajes que contienen el término (ranking por relevancia) con fecha y rol

#### Scenario: Search by API
- **WHEN** un usuario autorizado llama a `GET /api/v1/agents/{id}/messages?q=<término>`
- **THEN** la API responde con la paginación de los mensajes coincidentes, en orden de relevancia, y 403 para no autorizados

### Requirement: Chat export
El sistema SHALL exportar el historial completo de mensajes de un agente en Markdown y en JSON (`GET /api/v1/agents/{id}/messages/export?format=md|json`), para que el usuario autorizado lo revise o comparta fuera de la app. La exportación SHALL respetar la misma regla de acceso que el chat.

#### Scenario: Export Markdown
- **WHEN** un usuario autorizado solicita la exportación en Markdown
- **THEN** la respuesta es un archivo .md con título, autor, fecha y texto de cada mensaje en orden cronológico

#### Scenario: Export JSON
- **WHEN** un usuario autorizado solicita la exportación en JSON
- **THEN** la respuesta es un documento JSON con el historial completo (mensajes, roles, timestamps y razonamiento asociado) en orden cronológico

#### Scenario: Export restricted
- **WHEN** un usuario no autorizado solicita la exportación del chat de un agente
- **THEN** el sistema responde con error 403

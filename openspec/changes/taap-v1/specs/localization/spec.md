# Spec Delta

## Purpose

Garantiza que toda la interfaz (web e iOS) esté disponible en castellano, inglés y euskera, con selección de idioma por usuario y sin localizar el contenido generado por usuarios.

## ADDED Requirements

### Requirement: Three supported languages
Todos los textos visibles de la interfaz de la aplicación web y de la aplicación iOS SHALL estar disponibles en castellano (es), inglés (en) y euskera (eu).

#### Scenario: Switch language
- **WHEN** un usuario cambia el idioma de la interfaz
- **THEN** todas las pantallas se muestran en el idioma seleccionado

### Requirement: Per-user language preference
Cada usuario SHALL poder establecer el idioma de la interfaz por cuenta (web) y por dispositivo (iOS). El valor por defecto SHALL ser el idioma del navegador o del dispositivo si es uno de los soportados, y castellano en caso contrario.

#### Scenario: Device in English
- **WHEN** un usuario sin preferencia guardada abre la aplicación en un dispositivo en inglés
- **THEN** la interfaz se muestra en inglés

### Requirement: Content is not localized
El contenido generado por usuarios o externo (nombres de agentes, mensajes de chat, nombres de modelos, nombres de MCP) SHALL mostrarse tal cual en todos los idiomas; solo la interfaz SHALL estar localizada.

#### Scenario: Same content across languages
- **WHEN** un usuario cambia de idioma
- **THEN** los nombres de agentes y el historial del chat permanecen sin cambios

### Requirement: Missing translation fallback
Cuando un texto falte en el idioma seleccionado, el sistema SHALL recurrir al inglés y mostrar el identificador de la clave pendiente en modo de desarrollo.

#### Scenario: Missing Basque string
- **WHEN** el idioma seleccionado es euskera y una clave no tiene traducción al euskera
- **THEN** se muestra el valor en inglés

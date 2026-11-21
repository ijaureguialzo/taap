# brand-identity Specification

## Purpose

Define la identidad visual de TAAP en todas las plataformas: la marca (el grifo), el sistema de iconos, los tokens de color de la paleta propia y el modo oscuro, para que web e iOS compartan una apariencia coherente, accesible y reconocible.

## Requirements

### Requirement: Marca: el grifo
La identidad visual de TAAP SHALL ser la silueta vectorial plana de un grifo con una gota, definida en un único archivo maestro del que se generan todos los formatos (favicon SVG e ICO, icono de app iOS, apple-touch-icon y marca en la interfaz web). El glifo SHALL conservar margen de seguridad para que los recortes de sistema (esquinas redondeadas, máscaras) no lo recorten.

#### Scenario: Un visitante abre el sitio
- **WHEN** un usuario abre cualquier página del servicio en el navegador
- **THEN** la pestaña muestra el grifo como favicon, reconocible a 16 píxeles

#### Scenario: Un usuario instala la app
- **WHEN** un usuario instala la aplicación iOS
- **THEN** la pantalla de inicio muestra el icono con la marca (el grifo) y no un icono genérico

#### Scenario: Encabezado web
- **WHEN** un usuario autenticado ve la barra de navegación
- **THEN** la marca se muestra como glifo junto al nombre TAAP, en lugar del emoji anterior

### Requirement: Sistema de iconos
Cada acción, estado y contenido vacío de la interfaz SHALL llevar un icono de un mapa semántico único: la web lo renderiza desde el set Bootstrap Icons empaquetado localmente (sin CDN) a través de un único componente, e iOS desde SF Symbols. La marca (el grifo) no forma parte del set. Los iconos decorativos SHALL ser invisibles para las tecnologías de asistencia y los funcionales SHALL acompañar siempre a un texto legible.

#### Scenario: Borrar en cualquier listado
- **WHEN** un usuario ve un botón de borrar en cualquier listado de la web
- **THEN** muestra el mismo icono de papelera, sin emoji

#### Scenario: Estados de agente
- **WHEN** un agente muestra su estado en la web o en la app
- **THEN** el estado incluye su icono semántico (en pausa, activo, esperando, error) coherente en ambas plataformas

#### Scenario: Lector de pantalla
- **WHEN** una tecnología de asistencia recorre una interfaz con iconos
- **THEN** anuncia el texto de la acción o del estado, no los iconos decorativos

### Requirement: Tokens de color de la marca
La paleta de la marca (escala aqua como color primario, acento ink, colores de estado y neutros fríos) SHALL definirse como tokens: variables CSS en la web que reescriben las variables del framework para que todos sus componentes las adopten, y color sets en el asset catalog de iOS. Toda la interfaz (web e iOS) SHALL usar exclusivamente esos tokens: ningún elemento tomará un color ad hoc que no exista en la tabla de tokens.

#### Scenario: Un cambio de paleta global
- **WHEN** se modifica un valor de la escala primaria en la tabla de tokens
- **THEN** todos los elementos de la web y de la app cambian de color sin tocar vistas individuales

#### Scenario: Una pantalla nueva
- **WHEN** se desarrolla una nueva pantalla en web o en iOS
- **THEN** usa colores de la tabla de tokens y no hexadecimales sueltos

### Requirement: Modo oscuro
El sistema SHALL ofrecer en todas las plataformas un tema de tres estados (automático, claro y oscuro): por defecto automático siguiendo el sistema, y la elección del usuario se persiste por dispositivo. El modo oscuro SHALL usar superficies oscuras frías (no negro puro) con la misma semántica de colores que el modo claro, y en la web el tema SHALL aplicarse antes del primer pintado para que no haya parpadeo, adaptando todos los componentes mediante el mecanismo nativo del framework.

#### Scenario: Conmutar en la web
- **WHEN** un usuario elige «oscuro» en su perfil y recarga la página
- **THEN** la página se pinta directamente en modo oscuro, sin parpadeo claro, y conserva la elección

#### Scenario: Conmutar en la app
- **WHEN** un usuario activa el modo oscuro en los ajustes de la app
- **THEN** todas las pantallas, incluido el chat, usan la variante oscura y la elección se conserva entre sesiones

#### Scenario: Seguir el sistema
- **WHEN** el usuario no ha elegido tema (automático) y su sistema pasa a modo oscuro
- **THEN** la web y la app adoptan el modo oscuro sin acción del usuario

### Requirement: Contraste accesible
Todos los pares texto-superficie de la paleta (en modo claro y en modo oscuro) SHALL cumplir el contraste mínimo AA (4.5:1) de WCAG, y la tabla de tokens SHALL verificarse automáticamente en cada build: un par que no cumpla la ratio debe hacer fallar la verificación.

#### Scenario: Auditoría en CI
- **WHEN** se ejecuta la verificación de la tabla de tokens
- **THEN** cada par texto-superficie declarado alcanza la ratio AA en ambos modos

#### Scenario: Un token nuevo
- **WHEN** se añade un par texto-superficie a la tabla de tokens con contraste insuficiente
- **THEN** la verificación falla y señala el par afectado

# Proposal

## Why

Hoy no existe un servicio local que ejecute agentes de IA (Pi, Hermes) de forma aislada y 24/7 y permita a un equipo verlos, configurarlos e interactuar con ellos desde el navegador o el móvil. Cada agente es un CLI huérfano en la máquina de alguien: sin acceso multiusuario, sin configuración centralizada de modelos y MCP, y sin forma de dejar trabajo corriendo y volver a comprobar cómo va. Este cambio construye **TAAP (The Agentic AI Playground)**: un servicio auto-alojado que corre cada agente en su propio contenedor Docker y expone una interfaz web y una app móvil nativa para chatear, configurar y supervisar agentes.

## What Changes

- **Servicio orquestador** nuevo, que corre en su propio contenedor (Laravel 13 + Livewire 4 + Bootstrap 5 + PostgreSQL + Docker Engine API): gestiona el ciclo de vida de agentes, la autenticación, los modelos, los MCP y el chat.
- **Un contenedor Docker por agente**: runtime de agente + volumen persistente + red aislada, con acceso a sistemas de archivos, Internet (on/off) y recursos (CPU/memoria) configurables, disponible 24/7 (auto-reinicio, estado siempre recuperable).
- **Runtimes de agente v1.0**: **Pi** (vía modo RPC) y **Hermes** (vía su API server OpenAI-compatible), integrados a través de una costura `AgentRuntimeAdapter` que normaliza los eventos de agente; los runtimes siguientes (Claude Code, Codex, OpenCode) se añaden en fases posteriores como adapters adicionales.
- **Gestión de modelos**: el admin define endpoints compatibles con la API de OpenAI y de Anthropic; un **Model Proxy** dentro del orquestador enruta las peticiones de inferencia de los agentes (las API keys viven en el orquestador y los agentes sin Internet también pueden usar el modelo).
- **Gestión de MCP**: catálogo de servidores MCP (stdio/HTTP) gestionado por el admin; el usuario conecta/desconecta servidores de sus agentes a petición.
- **Multiusuario**: cuentas locales con roles **admin** y **usuario**; los agentes son **privados** de su creador y el admin puede ver y gestionar todos.
- **Chat**: texto con streaming de actividad del agente, cola de turnos, aprobaciones como eventos del chat y línea de tiempo de actividad para el patrón «dejo instrucciones y vuelvo a ver cómo va».
- **App móvil nativa iOS** (SwiftUI) en v1; Android en fases posteriores.
- **Localización**: interfaz web e iOS en castellano, inglés y euskera.

Fuera de alcance de v1.0 (fases planeadas): adapter Claude Code (v1.1), adapters Codex y OpenCode (v1.2), app Android, multi-host, proveedor de identidad externo (OIDC).

## Capabilities

### New Capabilities

- `agent-lifecycle`: creación, detención, arranque, recreación y borrado de agentes; estado 24/7 con auto-reinicio; recursos por agente (sistemas de archivos, red/internet, CPU/memoria); persistencia de workspace y datos.
- `agent-chat`: chat de texto por agente con streaming, cola de turnos, aprobación de acciones y línea de tiempo de actividad; coherencia multi-dispositivo.
- `agent-runtimes`: registro de runtimes (v1: Pi, Hermes), adapters que consumen las superficies nativas de cada runtime, normalización a `AgentEvent` y plano de configuración por runtime; costura estable para runtimes futuros.
- `model-management`: definición de proveedores de modelos (OpenAI-compatible y Anthropic) por el admin, selección de modelo por agente y Model Proxy como único punto de egress de inferencia.
- `mcp-management`: catálogo de servidores MCP gestionado por el admin y conexión/desconexión por agente a petición del usuario.
- `users-and-roles`: cuentas locales, roles admin/usuario, gestión de usuarios y propiedad privada de agentes.
- `localization`: interfaz completa en castellano, inglés y euskera (web e iOS) con selección de idioma por usuario.
- `ios-client`: aplicación nativa iOS (SwiftUI) v1 con paridad funcional sobre agentes, chat y configuración.

### Modified Capabilities

- (Ninguna: este es el primer cambio del proyecto; `openspec/specs/` está vacío.)

## Impact

- **Repositorio**: greenfield (solo README + OpenSpec). El cambio crea todo el código del servicio: orquestador (Laravel), UI web (Livewire 4 + Bootstrap 5), imágenes/contenedores de agentes, app iOS (SwiftUI) y despliegue Docker Compose.
- **Host**: requiere Docker Engine + Docker Compose. El orquestador monta el socket de Docker para gestionar los contenedores de agentes. Dos redes Docker: `taap-internal` (sin default route) y `taap-egress` (proxy allowlist). Contenedor Postgres.
- **Dependencias externas**: endpoints de modelos configurados por el admin (OpenAI-compatible / Anthropic) y los runtimes de agente (Pi, Hermes) como imágenes de contenedor.
- **Sin código existente afectado**: el cambio establece la línea base de todas las fases futuras (v1.1, v1.2).

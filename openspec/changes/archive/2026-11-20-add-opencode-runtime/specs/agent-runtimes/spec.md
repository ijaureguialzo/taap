# Spec Delta

## MODIFIED Requirements

### Requirement: Runtime registry
El sistema SHALL mantener un registro de runtimes de agente disponibles para crear agentes. Cada entrada SHALL incluir nombre para mostrar, versión fijada, imagen y capacidades (formatos de alambre que el runtime consume, modelo de aprobaciones y mecanismo de aplicación de configuración). Esta versión SHALL incluir cinco runtimes: `pi`, `hermes`, `claude`, `codex` y `opencode`. El registro SHALL ser la única fuente de verdad del flujo de creación de agentes y los formularios de creación de agente SHALL ofrecer exactamente los runtimes del registro.

#### Scenario: Choose runtime at creation
- **WHEN** un usuario crea un nuevo agente
- **THEN** la selección de runtime ofrece exactamente los runtimes del registro (Pi, Hermes, Claude Code, Codex y OpenCode)

## ADDED Requirements

### Requirement: OpenCode adapter
El sistema SHALL integrar el runtime OpenCode a través de su servidor API (`opencode serve`) dentro del contenedor: comandos por HTTP y recepción de eventos a través del stream de eventos del servidor, gestionando una sesión explícita por agente que se reanuda tras reinicios del contenedor y resolviendo las solicitudes de permiso del runtime con las decisiones del usuario.

#### Scenario: Message via OpenCode
- **WHEN** un mensaje del usuario se entrega a un agente OpenCode
- **THEN** el adapter lo envía a la sesión del agente y transmite la actividad (mensajes, herramientas, permisos) del stream a la pipeline de chat

#### Scenario: Session resume after restart
- **WHEN** el contenedor de un agente OpenCode se reinicia
- **THEN** el agente reanuda su sesión previa con su contexto intacto

#### Scenario: Permission resolved via API
- **WHEN** el stream del agente emite una solicitud de permiso y el usuario decide
- **THEN** el adapter resuelve el permiso por la API del runtime y el agente continúa o se detiene según la decisión

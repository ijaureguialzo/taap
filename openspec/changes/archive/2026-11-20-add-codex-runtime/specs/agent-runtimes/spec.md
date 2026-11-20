# Spec Delta

## MODIFIED Requirements

### Requirement: Runtime registry
El sistema SHALL mantener un registro de runtimes de agente disponibles para crear agentes. Cada entrada SHALL incluir nombre para mostrar, versión fijada, imagen y capacidades (formatos de alambre que el runtime consume, modelo de aprobaciones y mecanismo de aplicación de configuración). Esta versión SHALL incluir cuatro runtimes: `pi`, `hermes`, `claude` y `codex`. El registro SHALL ser la única fuente de verdad del flujo de creación de agentes y los formularios de creación de agente SHALL ofrecer exactamente los runtimes del registro.

#### Scenario: Choose runtime at creation
- **WHEN** un usuario crea un nuevo agente
- **THEN** la selección de runtime ofrece exactamente los runtimes del registro (Pi, Hermes, Claude Code y Codex)

## ADDED Requirements

### Requirement: Codex adapter
El sistema SHALL integrar el runtime Codex a través de su app server (interfaz JSON-RPC de larga vida sobre los flujos estándar del contenedor): entregar mensajes del usuario, recibir eventos normalizados y reanudar el hilo de trabajo persistido del agente tras reinicios del contenedor.

#### Scenario: Message via Codex
- **WHEN** un mensaje del usuario se entrega a un agente Codex
- **THEN** el adapter lo traslada al app server y transmite los eventos del agente a la pipeline de chat

#### Scenario: Session resume after restart
- **WHEN** el contenedor de un agente Codex se reinicia
- **THEN** el agente reanuda el hilo de trabajo previo con su contexto intacto

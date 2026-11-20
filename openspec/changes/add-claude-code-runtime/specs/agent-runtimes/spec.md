# Spec Delta

## MODIFIED Requirements

### Requirement: Runtime registry
El sistema SHALL mantener un registro de runtimes de agente disponibles para crear agentes. Cada entrada SHALL incluir nombre para mostrar, versión fijada, imagen y capacidades (formatos de alambre que el runtime consume, modelo de aprobaciones y mecanismo de aplicación de configuración). Esta versión SHALL incluir tres runtimes: `pi`, `hermes` y `claude`. El registro SHALL ser la única fuente de verdad del flujo de creación de agentes y los formularios de creación de agente SHALL ofrecer exactamente los runtimes del registro.

#### Scenario: Choose runtime at creation
- **WHEN** un usuario crea un nuevo agente
- **THEN** la selección de runtime ofrece exactamente los runtimes del registro (Pi, Hermes y Claude Code)

## ADDED Requirements

### Requirement: Claude Code adapter
El sistema SHALL integrar el runtime Claude Code a través de un proceso residente en el contenedor del agente basado en su Agent SDK oficial, comunicándose con él por la interfaz JSONL de los flujos estándar del contenedor: entregar mensajes del usuario, recibir eventos normalizados (emitidos por el propio proceso residente) y reanudar la sesión persistida del agente tras reinicios del contenedor.

#### Scenario: Message via Claude Code
- **WHEN** un mensaje del usuario se entrega a un agente Claude Code
- **THEN** el adapter lo traslada al runtime y transmite los eventos del agente a la pipeline de chat

#### Scenario: Session resume after restart
- **WHEN** el contenedor de un agente Claude Code se reinicia
- **THEN** el agente reanuda la sesión previa con su contexto intacto

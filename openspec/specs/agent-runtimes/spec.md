# agent-runtimes Specification

## Purpose

Define el registro de runtimes de agente soportados y la integración de cada runtime (v1: Pi y Hermes) a través de una interfaz de adapter común que normaliza sus eventos y aplica su configuración nativa.

## Requirements

### Requirement: Runtime registry
El sistema SHALL mantener un registro de runtimes de agente disponibles para crear agentes, con nombre para mostrar, versión fijada, imagen y capacidades. La v1.0 SHALL incluir exactamente dos runtimes: `pi` y `hermes`. El registro SHALL ser la única fuente de verdad del flujo de creación de agentes.

#### Scenario: Choose runtime at creation
- **WHEN** un usuario crea un nuevo agente
- **THEN** la selección de runtime ofrece exactamente los runtimes del registro

### Requirement: Pi adapter
El sistema SHALL integrar el runtime Pi a través de su interfaz RPC de larga vida (JSON sobre stdin/stdout), conduciendo el proceso del agente dentro del contenedor: entregar mensajes del usuario, recibir eventos normalizados y reanudar la sesión persistida del agente tras reinicios del contenedor.

#### Scenario: Message via Pi
- **WHEN** un mensaje del usuario se entrega a un agente Pi
- **THEN** el adapter lo traslada por la interfaz RPC y transmite los eventos del agente a la pipeline de chat

#### Scenario: Session resume after restart
- **WHEN** el contenedor de un agente Pi se reinicia
- **THEN** el agente reanuda la sesión previa con su contexto intacto

### Requirement: Hermes adapter
El sistema SHALL integrar el runtime Hermes a través de su API server compatible con OpenAI, autenticándose con una clave por agente y conservando la continuidad de sesión entre reinicios gracias al directorio de datos persistente del agente.

#### Scenario: Message via Hermes
- **WHEN** un mensaje del usuario se entrega a un agente Hermes
- **THEN** el adapter llama al API server y transmite la respuesta y la actividad a la pipeline de chat

### Requirement: Normalized agent events
Todos los runtimes SHALL exponerse a la pipeline de chat a través de un modelo común de eventos que incluya al menos: delta de mensaje, mensaje final, inicio de herramienta, resultado de herramienta, solicitud de aprobación, cambio de estado, inicio de ejecución, fin de ejecución y error. Las aplicaciones cliente SHALL consumir solo el modelo normalizado, nunca formatos específicos de cada runtime.

#### Scenario: Identical event shape
- **WHEN** un agente Pi y un agente Hermes realizan la misma clase de acción (por ejemplo, ejecutar un comando)
- **THEN** la pipeline de chat recibe eventos de los mismos tipos normalizados y la interfaz los renderiza de forma idéntica

### Requirement: Per-runtime configuration delivery
El sistema SHALL renderizar la configuración nativa de cada runtime (endpoint de modelo, servidores MCP, credenciales) en el directorio persistente del agente y aplicar los cambios de configuración por el mecanismo que el runtime soporte, sin perder el estado de la sesión del agente.

#### Scenario: Model change without session loss
- **WHEN** un usuario cambia el modelo de un agente
- **THEN** la nueva configuración se aplica al agente y el historial de la conversación y la sesión se conservan

### Requirement: Runtime health and versioning
El sistema SHALL reportar por agente si su runtime está sano y SHALL fijar la versión del runtime en las imágenes de agente. La actualización de un runtime SHALL ser una acción explícita del administrador que recrea los agentes afectados conservando su estado persistido.

#### Scenario: Runtime upgrade
- **WHEN** el admin actualiza la versión de la imagen de Pi
- **THEN** el admin puede recrear los agentes Pi afectados y estos reanudan con sus sesiones intactas

#### Scenario: Runtime unhealthy
- **WHEN** el proceso de un agente no está sano
- **THEN** el agente muestra estado «error» y el sistema intenta su recuperación automática

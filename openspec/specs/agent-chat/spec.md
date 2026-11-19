# agent-chat Specification

## Purpose

Permite a los usuarios chatear con sus agentes mediante texto, con streaming de la actividad del agente, cola de turnos, aprobación de acciones y línea de tiempo de actividad, de forma coherente en varios dispositivos.

## Requirements

### Requirement: Text chat with agent
El usuario SHALL poder enviar mensajes de texto a sus agentes y recibir las respuestas del agente con streaming. Todos los mensajes del usuario y las respuestas del agente SHALL persistirse y ser visibles como historial del chat.

#### Scenario: Send and receive
- **WHEN** un usuario envía un prompt a un agente en ejecución
- **THEN** la respuesta del agente se muestra con streaming en tiempo real y tanto el prompt como la respuesta aparecen en el historial persistido

### Requirement: Turn queueing
Mientras el agente está procesando trabajo, los nuevos mensajes del usuario SHALL encolarse y procesarse en orden FIFO al terminar el trabajo actual, sin pérdidas ni reordenados.

#### Scenario: Multiple messages while busy
- **WHEN** un usuario envía tres mensajes mientras el agente está trabajando
- **THEN** los tres se procesan en el orden enviado y el usuario ve un indicador de encolado para cada uno

### Requirement: Agent activity streaming
El sistema SHALL transmitir la actividad del agente (cambios de estado, invocaciones de herramientas con su resultado, eventos relevantes) a los clientes autorizados conectados mientras ocurre.

#### Scenario: Tool invocation visible
- **WHEN** el agente ejecuta un comando o edita un fichero mientras trabaja
- **THEN** los clientes conectados muestran la actividad (qué herramienta y con qué resultado) en la vista del chat

### Requirement: Action approval
Cuando el agente solicite aprobación para una acción, el sistema SHALL presentar la solicitud en el chat con opciones de aceptar y rechazar (y «permitir siempre» cuando el runtime lo soporte). El agente SHALL continuar su trabajo según la decisión del usuario.

#### Scenario: Approval denied
- **WHEN** el agente solicita aprobación para ejecutar un comando y el usuario la rechaza
- **THEN** el agente continúa sin ejecutar la acción y reporta el desenlace en el chat

#### Scenario: Approve from mobile
- **WHEN** el usuario está en la aplicación móvil y el agente solicita una aprobación
- **THEN** el usuario puede aceptarla desde la app y el agente prosigue

### Requirement: Activity timeline
El sistema SHALL registrar una línea de tiempo del trabajo del agente (cambios de estado, eventos clave, resúmenes de trabajo completado) para que un usuario ausente pueda revisar lo sucedido a su vuelta.

#### Scenario: Return after absence
- **WHEN** un usuario abre un agente tras dos horas de ausencia
- **THEN** ve un resumen de lo que hizo el agente mientras no estaba, rematando en el estado actual del agente

### Requirement: Multi-device coherence
Los mensajes enviados desde, y la actividad vista en, cualquier cliente (web o móvil) SHALL aparecer en todos los clientes conectados del usuario para ese agente, en el mismo orden.

#### Scenario: Sync across devices
- **WHEN** un usuario envía un mensaje desde la aplicación móvil
- **THEN** el mensaje y la respuesta del agente también aparecen en su cliente web abierto

### Requirement: Chat access control
Solo el propietario del agente y los administradores SHALL poder ver o enviar mensajes en el chat de un agente.

#### Scenario: Foreign user denied
- **WHEN** un usuario que no es el propietario ni admin intenta abrir el chat de un agente
- **THEN** el sistema deniega el acceso

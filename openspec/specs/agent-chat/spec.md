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

### Requirement: Chat viewport layout
El editor de chat en la página de detalle del agente SHALL ajustarse a la altura del viewport del navegador: la página no SHALL hacer scroll vertical, el historial de mensajes SHALL expandirse dentro del espacio restante del viewport con scroll interno propio, y el área de entrada con su botón de envío SHALL permanecer siempre visible. La columna lateral (línea de tiempo) SHALL usar scroll interno propio.

#### Scenario: Conversación larga
- **WHEN** la conversación con el agente tiene suficientes mensajes como para exceder la altura del viewport
- **THEN** la página no hace scroll, el historial se desplaza internamente y el campo de entrada permanece visible en la parte inferior de la pantalla

#### Scenario: Columna lateral
- **WHEN** la línea de tiempo de actividad del agente excede la altura disponible
- **THEN** la línea de tiempo se desplaza dentro de su propia columna sin desplazar el resto de la página

### Requirement: Message send triggers
Un usuario autorizado del chat de un agente SHALL poder enviar un mensaje escribiéndolo en el campo y pulsando **Enter**, sin necesidad de pulsar el botón con el ratón. Tras un envío exitoso, el campo SHALL vaciarse y volver a enfocarse para permitir envíos consecutivos.

#### Scenario: Enviar con Enter
- **WHEN** el usuario escribe un mensaje y pulsa Enter con el agente en ejecución
- **THEN** el mensaje se envía, el campo queda vacío y enfocado, y el mensaje aparece en el historial

### Requirement: Message send states
El botón de envío SHALL estar activo si y solo si el campo tiene contenido y el agente puede aceptar mensajes; cuando el agente espera una aprobación, la interfaz SHALL deshabilitar el envío e indicarlo en lugar de aceptar mensajes que el sistema rechazaría. Cuando el usuario envíe a un agente detenido o desconectado, el sistema SHALL arrancarlo y encolar el mensaje para que se procese cuando el agente esté listo.

#### Scenario: Enviar a un agente detenido
- **WHEN** el agente está detenido y el usuario envía un mensaje
- **THEN** el agente se arranca, el mensaje queda encolado y, al estar el agente listo, se procesa y su respuesta se muestra en el chat

#### Scenario: Agente esperando aprobación
- **WHEN** el agente está en estado de espera de aprobación
- **THEN** el botón de envío está deshabilitado con un aviso explicativo y el usuario puede aprobar o denegar la acción pendiente desde el chat

### Requirement: Live events to web client
El cliente web SHALL recibir los eventos en vivo del agente (streaming, cambios de estado, aprobaciones y mensaje final) a través del mismo canal que el orquestador emplea para difundirlos (canal público `agent.{id}`), el mismo que usan el resto de clientes, de modo que la respuesta del agente se muestre en el chat sin necesidad de recargar la página.

#### Scenario: Respuesta final sin recargar
- **WHEN** el agente termina de procesar un mensaje del usuario
- **THEN** el cliente web sustituye la burbuja «escribiendo…» por el mensaje finalizado y el historial se actualiza, sin que el usuario tenga que recargar la página

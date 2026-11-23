# Spec Delta — agent-chat

## MODIFIED Requirements

### Requirement: Text chat with agent
El usuario SHALL poder enviar mensajes de texto a sus agentes y recibir las respuestas del agente con streaming en tiempo real (texto y razonamiento por separado). Todos los mensajes del usuario y las respuestas del agente SHALL persistirse y ser visibles como historial del chat, incluyendo el razonamiento del turno como sección asociada al mensaje.

#### Scenario: Send and receive
- **WHEN** un usuario envía un prompt a un agente en ejecución
- **THEN** la respuesta del agente se muestra con streaming en tiempo real y tanto el prompt como la respuesta aparecen en el historial persistido

#### Scenario: Reasoning visible in history
- **WHEN** el modelo del agente emite razonamiento durante el turno y el mensaje se asienta en el historial
- **THEN** el mensaje del agente muestra el razonamiento como sección diferenciada del texto final (colapsable por defecto), no fundida con él

### Requirement: Agent activity streaming
El sistema SHALL transmitir la actividad del agente (cambios de estado, invocaciones de herramientas con su resultado, errores y eventos relevantes) a los clientes autorizados conectados mientras ocurre, y el chat SHALL mostrar esa actividad como feed de interacción dentro del flujo de la conversación (no solo como indicador de estado).

#### Scenario: Tool invocation visible
- **WHEN** el agente ejecuta un comando o edita un fichero mientras trabaja
- **THEN** los clientes conectados muestran la actividad (qué herramienta y con qué resultado) en la vista del chat

#### Scenario: Activity recoverable after the run
- **WHEN** un turno termina y el usuario abre o refresca el chat
- **THEN** la actividad del turno (herramientas usadas y sus resultados, errores) sigue siendo visible en el mensaje correspondiente, reconstruida a partir de lo que el sistema persistió

#### Scenario: Error visible
- **WHEN** el agente o su modelo reporta un error durante un turno
- **THEN** el error se muestra como entrada de actividad en el chat y el estado del agente refleja el fallo

### Requirement: Action approval
Cuando el agente solicite aprobación para una acción, el sistema SHALL presentar la solicitud en el chat con opciones de aceptar y rechazar (y «permitir siempre» cuando el runtime lo soporte). El agente SHALL continuar su trabajo según la decisión del usuario. Si la solicitud se emitió cuando ningún cliente estaba abierto, el sistema SHALL volver a presentarla al abrirse o re-sincronizarse el chat, de modo que nunca quede una espera sin forma de responder.

#### Scenario: Approval denied
- **WHEN** el agente solicita aprobación para ejecutar un comando y el usuario la rechaza
- **THEN** el agente continúa sin ejecutar la acción y reporta el desenlace en el chat

#### Scenario: Approve from mobile
- **WHEN** el usuario está en la aplicación móvil y el agente solicita una aprobación
- **THEN** el usuario puede aceptarla desde la app y el agente prosigue

#### Scenario: Pending approval recovered on open
- **WHEN** el agente pidió una aprobación con la web cerrada y el usuario abre después el chat
- **THEN** el chat muestra la tarjeta de la aprobación pendiente con sus opciones y el agente no queda bloqueado sin forma de resolverla

### Requirement: Live events to web client
El cliente web SHALL recibir los eventos en vivo del agente a través del mismo canal que el orquestador emplea para difundirlos (canal público `agent.{id}`), el mismo que usan el resto de clientes: deltas de streaming (texto y razonamiento), cambios de estado, actividad de herramientas, aprobaciones y mensaje final, de modo que la respuesta del agente se muestre en el chat sin necesidad de recargar la página.

#### Scenario: Respuesta final sin recargar
- **WHEN** el agente termina de procesar un mensaje del usuario
- **THEN** el cliente web sustituye la burbuja «escribiendo…» por el mensaje finalizado y el historial se actualiza, sin que el usuario tenga que recargar la página

#### Scenario: Token streaming
- **WHEN** el agente está generando su respuesta
- **THEN** el texto (y el razonamiento) aparece progresivamente en la burbuja conforme se genera, sin esperar al mensaje final

#### Scenario: Status change without reload
- **WHEN** el agente cambia de estado (arrancar, trabajar, esperar aprobación, error, detenerse)
- **THEN** los clientes web conectados actualizan su indicador de estado (texto e icono) sin recargar la página, en la lista y en el detalle del agente

### Requirement: Horas locales del visor
Las marcas de tiempo que la interfaz web muestra (mensajes del chat y ventanas
de la línea de tiempo) SHALL representarse en la zona horaria del visor: el
servidor transmite el instante como valor absoluto (ISO 8601 o Unix) y el
cliente lo formatea; el servidor NO SHALL formatear la hora en su propia zona
horaria como texto final. El instante nunca SHALL pintarse de forma visible en
su formato absoluto: el formateo local se aplica antes de la pintura o el
instante permanece oculto.

#### Scenario: Visor con UTC+1
- **WHEN** un visor en zona UTC+1 envía un mensaje a las 23:56 locales y
  ve el chat
- **THEN** la marca de tiempo de su propio mensaje muestra 23:56 (la hora
  de su reloj), no la hora UTC del servidor

#### Scenario: Dos visores en zonas distintas
- **WHEN** dos usuarios en zonas horarias distintas ven el mismo mensaje
- **THEN** cada uno ve la hora equivalente a su zona local para el mismo
  instante absoluto

#### Scenario: Cambio de zona horaria sin recargar el orquestador
- **WHEN** el visor cambia de zona horaria (viaje, cambio de sistema) y
  vuelve a abrir el chat
- **THEN** las marcas de tiempo se muestran en la nueva zona sin cambio
  alguno en el servidor

#### Scenario: Sin parpadeo al aparecer un mensaje
- **WHEN** un mensaje nuevo (usuario o agente) aparece en el chat
- **THEN** su marca de tiempo se muestra directamente formateada en zona local, sin un fotograma intermedio con el valor absoluto

## ADDED Requirements

### Requirement: Chat scroll behavior
El historial del chat en la página de detalle SHALL mantener anclada la vista al final de la conversación cuando llegue contenido nuevo si el usuario estaba cerca del final antes del cambio. Si el usuario ha desplazado la vista hacia arriba para leer historial, la llegada de contenido nuevo SHALL conservar su posición. Un mensaje nuevo nunca SHALL desplazar la vista al principio del historial, ni aunque el mecanismo de renderizado sustituya el contenedor del historial.

#### Scenario: Agent replies while user is at the end
- **WHEN** el agente responde (streaming o mensaje final) y el usuario estaba leyendo el final de la conversación
- **THEN** la vista permanece en el final y el contenido nuevo es visible sin intervención del usuario

#### Scenario: User reading history during a reply
- **WHEN** el agente sigue respondiendo mientras el usuario ha subido a leer mensajes anteriores
- **THEN** la posición de lectura se conserva y el contenido nuevo no desplaza la vista

#### Scenario: Long history does not jump to top
- **WHEN** un mensaje largo llega a un historial que excede la altura del viewport y la vista estaba en el final
- **THEN** la vista no salta al principio del historial

### Requirement: Markdown rendering in chat
El contenido de las burbujas del chat (agente y usuario) SHALL renderizarse como Markdown sanitizado (código, listas, tablas, enlaces, énfasis) en el historial y durante el streaming, en los dos temas. El contenido sin constructos Markdown se pintará como texto plano. El HTML resultante SHALL estar sanitizado: el contenido del agente no SHALL poder inyectar o ejecutar HTML (scripts, atributos de evento). La sección de razonamiento se mantiene como texto plano.

#### Scenario: Code block in an answer
- **WHEN** el agente responde con un bloque de código delimitado por triple tilde
- **THEN** la burbuja lo muestra como bloque de código formateado (no como texto con tildes), legible en tema claro y oscuro

#### Scenario: Plain text stays plain
- **WHEN** el agente responde con texto sin constructos de Markdown
- **THEN** la burbuja lo muestra idéntico al texto plano actual (saltos de línea y sangrías respetados)

#### Scenario: Sanitized output
- **WHEN** la respuesta del agente contiene HTML crudo (por ejemplo `<script>` o atributos de evento)
- **THEN** se muestra como texto escapado y nada se ejecuta en la interfaz

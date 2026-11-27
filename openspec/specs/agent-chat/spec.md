# agent-chat Specification

## Purpose

Permite a los usuarios chatear con sus agentes mediante texto, con streaming de la actividad del agente, cola de turnos, aprobación de acciones y línea de tiempo de actividad, de forma coherente en varios dispositivos.

## Requirements

### Requirement: Text chat with agent
El usuario SHALL poder enviar mensajes de texto a sus agentes y recibir las respuestas del agente con streaming en tiempo real (texto y razonamiento por separado). Todos los mensajes del usuario y las respuestas del agente SHALL persistirse y ser visibles como historial del chat, incluyendo el razonamiento del turno como sección asociada al mensaje.

#### Scenario: Send and receive
- **WHEN** un usuario envía un prompt a un agente en ejecución
- **THEN** la respuesta del agente se muestra con streaming en tiempo real y tanto el prompt como la respuesta aparecen en el historial persistido

#### Scenario: Reasoning visible in history
- **WHEN** el modelo del agente emite razonamiento durante el turno y el mensaje se asienta en el historial
- **THEN** el mensaje del agente muestra el razonamiento como sección diferenciada del texto final (colapsable por defecto), no fundida con él

### Requirement: Turn queueing
Mientras el agente está procesando trabajo, los nuevos mensajes del usuario SHALL encolarse y procesarse en orden FIFO al terminar el trabajo actual, sin pérdidas ni reordenados.

#### Scenario: Multiple messages while busy
- **WHEN** un usuario envía tres mensajes mientras el agente está trabajando
- **THEN** los tres se procesan en el orden enviado y el usuario ve un indicador de encolado para cada uno

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

### Requirement: Configuración del endpoint de streaming
El endpoint del websocket por el que los clientes se conectan al servidor de eventos (host, esquema TLS y puerto) SHALL ser configurable por despliegue mediante variables de entorno del orquestador, sin cambios de código. El orquestador SHALL comunicar ese endpoint al cliente web y al cliente móvil, y SHALL mantener los defectos de desarrollo local (host local, sin TLS, puerto del servidor de eventos) cuando no se configure nada.

#### Scenario: Despliegue con endpoint público configurado
- **GIVEN** el despliegue define en el entorno del orquestador el host público `ws.taap.test`, esquema `https` y puerto `443`
- **WHEN** un usuario autenticado abre el cliente web o la aplicación móvil
- **THEN** ambos reciben ese endpoint y se conectan a `wss://ws.taap.test:443` y no a la dirección interna del servidor de eventos

#### Scenario: Cambio a otro servidor
- **GIVEN** un despliegue reconfigurado por variables de entorno al host público `ws.taap.itlent.eus` (esquema `https`, puerto `443`)
- **WHEN** los clientes obtienen la configuración del orquestador
- **THEN** se conectan al nuevo endpoint sin ningún cambio de código en el orquestador ni en los clientes

#### Scenario: Desarrollo local sin proxy inverso
- **GIVEN** un arranque local del orquestador sin variables de endpoint público definidas
- **WHEN** un cliente local obtiene la configuración del orquestador
- **THEN** usa los defectos de desarrollo (host `127.0.0.1`, esquema sin TLS y puerto del servidor de eventos) y el streaming local sigue funcionando

### Requirement: Endpoint público de eventos con TLS
En el despliegue con proxy inverso, el servidor de eventos SHALL ser alcanzable públicamente a través de un host dedicado, distinto del del orquestador, sobre TLS en el puerto 443, usando el certificado del despliegue. Los clientes conectados al frontend HTTPS del orquestador SHALL poder establecer la conexión del websocket y recibir los eventos del agente sin bloqueo por contenido mixto.

#### Scenario: Streaming de eventos a través del host público
- **GIVEN** el despliegue por defecto con un certificado válido que cubre el host del websocket (`ws.taap.test`, vía comodín `*.taap.test`)
- **WHEN** un usuario abre el chat de un agente desde el cliente web o la aplicación móvil servidos por HTTPS
- **THEN** el cliente establece una conexión de websocket con TLS en el puerto 443 y recibe en tiempo real los eventos del agente (cambios de estado, actividad)

#### Scenario: Certificado para el nuevo host
- **GIVEN** un despliegue reconfigurado a un dominio nuevo cuyo certificado actual no cubre el nuevo host del websocket
- **WHEN** se regenera el certificado del despliegue incluyendo el nuevo host del websocket
- **THEN** los clientes logran establecer la conexión TLS y el streaming funciona en el dominio nuevo

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

### Requirement: Chat search
El sistema SHALL permitir buscar los mensajes del chat de un agente (texto del mensaje, user y agent), con paginación y ranking por relevancia, sobre la web y vía API v1. Los resultados SHALL restringirse al propietario y a los admins (la misma regla de acceso que el chat) y SHALL no exponer otros datos del mensaje más allá del texto y la fecha.

#### Scenario: Search on web
- **WHEN** un usuario autorizado escribe un término en el buscador del chat
- **THEN** la lista de resultados muestra los mensajes que contienen el término (ranking por relevancia) con fecha y rol

#### Scenario: Search by API
- **WHEN** un usuario autorizado llama a `GET /api/v1/agents/{id}/messages?q=<término>`
- **THEN** la API responde con la paginación de los mensajes coincidentes, en orden de relevancia, y 403 para no autorizados

### Requirement: Chat export
El sistema SHALL exportar el historial completo de mensajes de un agente en Markdown y en JSON (`GET /api/v1/agents/{id}/messages/export?format=md|json`), para que el usuario autorizado lo revise o comparta fuera de la app. La exportación SHALL respetar la misma regla de acceso que el chat.

#### Scenario: Export Markdown
- **WHEN** un usuario autorizado solicita la exportación en Markdown
- **THEN** la respuesta es un archivo .md con título, autor, fecha y texto de cada mensaje en orden cronológico

#### Scenario: Export JSON
- **WHEN** un usuario autorizado solicita la exportación en JSON
- **THEN** la respuesta es un documento JSON con el historial completo (mensajes, roles, timestamps y razonamiento asociado) en orden cronológico

#### Scenario: Export restricted
- **WHEN** un usuario no autorizado solicita la exportación del chat de un agente
- **THEN** el sistema responde con error 403

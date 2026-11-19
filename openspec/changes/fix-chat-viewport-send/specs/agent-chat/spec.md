# Spec Delta — agent-chat

## ADDED Requirements

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

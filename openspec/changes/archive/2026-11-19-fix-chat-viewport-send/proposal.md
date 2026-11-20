# Proposal

## Why

En la vista de detalle del agente, el chat no se comporta como un chat:

1. **No se ajusta al viewport**: el contenedor del historial (`#chat-box`) tiene `overflow-auto` y un mínimo de altura, pero crece con el contenido — la página entera hace scroll y el área de entrada se sale de la pantalla en cuanto la conversación alarga.
2. **No se pueden enviar mensajes**: con el agente detenido o desconectado, el campo y el botón están deshabilitados y no hay forma de enviar desde el chat (ni por tanto de arrancarlo). En espera de aprobación, el botón parece activo pero el envío no produce ningún efecto visible.
3. **Los eventos en vivo no llegan a la web**: el orquestador difunde por el canal público `agent.{id}` (el mismo que usa la app iOS y los tests), pero el cliente web se suscribe a `private-agent.{id}` — la burbuja de respuesta queda congelada en «escribiendo…» hasta recargar la página, lo que refuerza la impresión de que el envío no funciona.

## What Changes

- **Layout del chat**: en la página de detalle, la página deja de hacer scroll; el chat ocupa el espacio que queda del viewport (cadena flex: el historial crece con scroll interno y el área de entrada permanece siempre visible, a partir de `lg` hacia arriba) y la columna lateral (línea de tiempo) usa scroll interno propio.
- **Envío**: el botón y el campo solo se deshabilitan cuando el agente espera aprobación (con aviso en pantalla y la tarjeta de aprobar/denegar como acción). Enviar a un agente detenido lo **arranca** y encola el mensaje: en Pi el prompt sobrevive en el fichero IPC del bridge y se procesa al arrancar; en Hermes el coproceso espera a que el gateway salga del arranque.
- **Enter para enviar**: al pulsar Enter se envía y el campo se vacía y reenfoca en el cliente (sin texto «fantasma» tras el envío).
- **Eventos en vivo**: el cliente web (chat y badge del detalle) se suscribe al canal público `agent.{id}` — el mismo que emite el difusor y usa iOS — para que la respuesta final sustituya a la burbuja en streaming sin recargar; se corrige además la URL del endpoint de auth inyectada en el layout (`/api/v1/broadcasting/auth`).

Fuera de alcance: el lector del bridge que se re-adjunta cada ~60 s («lector stale», que hace reemitir `message.final` y el sink lo deduplica) — robustez del demonio de bridge, cambio por separado.

## Capabilities

### Modified Capabilities

- `agent-chat`: tres requisitos nuevos (layout del chat al viewport, experiencia de envío con Enter/autolarranque/estado, eventos en vivo en el cliente web). Ningún requisito existente cambia de comportamiento.

## Impact

- **Orquestador (web)**: `resources/css/app.css` (cadena flex del viewport), `resources/js/app.js` (cliente Echo: instancia Pusher compartida, `cluster` exigido por pusher-js y `namespace: ''` para que `.listen()` empareje el evento crudo `agent-event`), `resources/views/livewire/agents/chat.blade.php` (estados, avisos, canal público, campo tras enviar, re-suscripción resiliente con watchdog), `resources/views/livewire/agents/detail.blade.php` (canal + altura del chat), `resources/views/layouts/app.blade.php` (auth endpoint), `app/Enums/AgentStatus.php` (`acceptsMessages()`), `app/Agents/ChatService.php` (autolarranque), `app/Livewire/Agents/Chat.php` (evento `message-sent`), `app/Console/Commands/TaapHermesTurnCommand.php` (espera al gateway), `lang/{es,en,eu}/chat.php` (avisos nuevos).
- **iOS**: sin cambios (ya se suscribe al canal público).
- **Tests**: feature test del envío por estados (detenido → arranca y encola; esperando → bloqueado) y unitario de `AgentStatus`.

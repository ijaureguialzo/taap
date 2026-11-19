# Design

## Context

La página de detalle del agente (`livewire/agents/detail`) contiene el chat (`livewire/agents/chat`), la ficha y la línea de tiempo dentro de un layout `min-vh-100 d-flex flex-column` (navbar + `main.flex-grow-1` + footer). El layout medido en vivo (viewport 1280×577): `#chat-box` medía 808 px (creció con el contenido, `scrollHeight == clientHeight`) y el documento 1061 px — es decir, **la página hace scroll** y el scroll interno del chat nunca aparece.

Durante la investigación se identificó además un tercer defecto: el cliente web se suscribe a `Echo.private('agent.{id}')` (`private-agent.{id}`) pero el difusor (`AgentEventBroadcast::broadcastOn`) emite al canal público `agent.{id}` — el mismo que suscribe la app iOS (socket Reverb directo) y que autentican los tests (`/api/v1/broadcasting/auth`). La burbuja de respuesta queda así congelada en «escribiendo…» hasta recargar, lo que agrava la percepción de «no puedo enviar».

La verificación en vivo reveló dos causas adicionales del mismo síntoma (burbuja congelada en «escribiendo…»): (a) `laravel-echo` aplica por defecto `namespace: "App.Events"` a `.listen()`, de modo que el callback se registra como `App\Events\agent-event` y nunca coincide con el nombre del evento en el cable (`agent-event`, `broadcastAs`); y (b) el cliente no re-suscribe el canal cuando el socket cambia (reinicio de Reverb o cambio de transporte de pusher-js): el servidor pierde la suscripción, el canal muere en silencio y ningún evento vuelve a llegar hasta recargar.

## Decisions

### D1 — Viewport: cadena flex limitada a la página de detalle con `:has()`, sin tocar el layout

- `body:has(.agent-detail) { height: 100vh; overflow: hidden }` (con fallback `100dvh`) convierte la página en una caja de altura fija: navbar y footer conservan su altura, `main` (ya `flex-grow-1`) recibe el resto.
- `.agent-detail` pasa a `flex-column` con `height: 100%; min-height: 0`; su `.row` y las columnas `min-height: 0`; la columna del chat rellena por flex y la columna lateral (timeline) gana `overflow-y: auto`.
- `#chat-box` conserva `overflow: auto` y `flex-grow: 1`, pero el `min-height: 320px` inline y el `min-height: 480px` de la etiqueta `<livewire>` se retiran (rompen el shrink): el mínimo pasa a ser un `min-height` moderado sobre `.agent-chat`.
- Todo acotado a `@media (min-width: 992px)`: en pantallas estrechas (columnas apiladas) se conserva el comportamiento actual (la página hace scroll) para no inventar una UX móvil no pedida.
- **Fallback**: en navegadores sin `:has()` la regla de altura no aplica y el layout queda exactamente como hoy. Sin cambios en `layouts/app.blade.php` ni en el resto de páginas.

Alternativa descartada: `height: calc(100vh - N px)` — el bloque de cabecera (título + descripción) tiene altura variable y el valor dejaría huecos o recortes; la cadena flex lo absorbe.

### D2 — Estado de envío: un único criterio, `AgentStatus::acceptsMessages()`

`ChatService::send()` hoy rechaza en dos estados: `WaitingApproval` (excepción) y `!isRunning()` (excepción). La nueva semántica:

- `acceptsMessages()`: todo estado **salvo `WaitingApproval`** (el agente bloqueado en una aprobación no acepta prompts nuevos; la acción del usuario es aprobar/denegar).
- `!isRunning()` (detenido/desconectado) **ya no bloquea**: el envío lo arranca (D3).

En la vista: campo `@disabled(! $agent->status->acceptsMessages())`; botón `@disabled(! acceptsMessages() || contenido vacío)`. Avisos `form-text` por estado: detenido → «se arrancará al enviar»; esperando → «espera tu aprobación». El criterio se comparte entre campo y botón (nada de condiciones divergentes como las actuales).

### D3 — Autolarranque desde el chat

En `ChatService::send()`, tras la comprobación de permisos y el chequeo de aprobación, si `! $agent->status->isRunning()` se llama a `AgentManager::start($agent)` (síncrono, igual que lo hace la UI de ciclo de vida) y se continúa.

- **Pi**: `start()` pone `Starting` y arranca el contenedor. El `BridgeSendMessage` (cola `taap-bridge`, 2 intents) escribe el prompt en el fichero IPC del host (`rpcDir`, que `File::ensureDirectoryExists` garantiza): sobrevive a que el contenedor aún no esté adjunto y el bridge lo entrega al arrancar el runtime. Sin cambios en el adapter.
- **Hermes**: `spawnHermesTurn` lanza el coproceso `taap:hermes-turn`, cuyo POST fallaría si el gateway aún no está arriba. El comando espera (poling de `Agent::status`, hasta ~60 s) a que salga de `Starting` antes de llamar al adapter.
- Si el arranque falla (Docker, imagen), la excepción viaja a `Chat::send()` → flash de error actual; el mensaje no se persiste (el arranque se hace antes de persistir).

### D4 — Canal en vivo: la web usa el canal público, como el difusor y iOS

`chat.blade.php` y `detail.blade.php` pasan de `TaapEcho.private('agent.{id}')` a `TaapEcho.channel('agent.{id}')`. El acceso se sigue garantizando a nivel de página (el componente devuelve 404 al no autorizado antes de suscribirse). La URL de `authEndpoint` inyectada en el layout se corrige a `url('/api/v1/broadcasting/auth')` (ruta real; hoy apunta a `/broadcasting/auth`, inexistente) — hoy no la usa la web (canal público) pero queda correcta para canales privados futuros.

Verificado en vivo antes de cambiar: un `Broadcast::event(AgentEventBroadcast)` recibido por `pusher.subscribe('agent.1')` (público) y **no** por `pusher.subscribe('private-agent.1')`.

### D5 — Campo tras el envío: evento `message-sent`

Livewire no sobreescribe el DOM de un input enfocado (protege el cursor), así que tras el envío el valor viejo sigue visible aunque el modelo se haya limpiado. `Chat::send()` dispatchea `message-sent` solo si el envío tuvo éxito; un script en `chat.blade.php` lo recibe, pone `input.value = ''` y reenfoca. El botón queda deshabilitado (contenido vacío) de forma coherente.

### D6 — Echo sin namespace: el nombre del evento en el cable es crudo

El default de `laravel-echo` (`_defaultOptions.namespace = "App.Events"`) hace que `TaapEcho.channel(...).listen('agent-event', cb)` registre el callback bajo `App\Events\agent-event`, mientras el emisor difunde como `agent-event` (`AgentEventBroadcast::broadcastAs()`). En pusher-js el emparejamiento se hace por el nombre crudo del evento (`channel.emit(event.event)`), así que el listener de `.listen()` **nunca** disparaba.

- En `app.js` (`initEcho`): `new Echo({ broadcaster: 'reverb', client: TaapPusher, namespace: '', ...cfg })`. El spread de `cfg` (data-attrs del layout) no incluye `namespace`, así que el default deja de aplicarse.
- Afecta a cualquier `.listen()` en la web (chat y badge del detalle). La app iOS (socket directo, sin laravel-echo) y el difusor no cambian.

Alternativa descartada: cambiar `broadcastAs()` a `App\Events\agent-event` — impondría el prefijo también a iOS y a cualquier otro consumidor del canal.

### D7 — Suscripción resiliente en el script del chat

El script inline de `chat.blade.php` garantiza que el canal `agent.{id}` quede suscrito y escuchando, independientemente de los reemplazos que sufra el cliente:

- **Re-suscripción forzada**: en `TaapPusher.connection` `connected` y con un watchdog (30 s, idempotente: `channel.subscribed = false; channel.subscribe()`), porque pusher-js no garantiza re-suscribir un canal que ya está en `subscribed === true` cuando el socket se sustituye (reinicio de Reverb, half-open TCP). El servidor solo re-registra la conexión: coste de un mensaje pequeño por tick.
- **Instancia resuelta en cada evento**: el listener del canal resuelve el componente con `Livewire.find(wire:id)` **en el momento del evento** (no en el setup): cuando el morph del padre re-hidrata el componente anidado, `find()` devuelve una instancia nueva y una instancia capturada quedaría muerta (los updates morían en silencio).
- **Flag a nivel `window`** (`__taapChatSetup_{id}`) y no en el nodo: el morph re-sincrona los atributos del nodo raíz con el HTML del servidor y borra cualquier `data-*` client-only; un flag en el nodo se perdía en el primer update. El setup es idempotente y tolera errores (si lanza, el retry sigue; el flag indica éxito, no intento).
- **Autoscroll con re-anclaje**: el `MutationObserver` se (re)ancla en el `#chat-box` actual cada 30 s (el nodo puede ser sustituido por el morph del padre).

## Risks / trade-offs

- `:has()` es universal en navegadores actuales (2026); el fallback sin soporte es el layout actual, no un estado roto.
- El autolarranque hace la petición HTTP de envío bloqueante unos segundos (docker start / provision si el contenedor no existe). Aceptable: la UI de ciclo de vida ya lo hace igual, y el usuario ve el badge pasar a «arrancando» vía canal en vivo.
- La columna lateral con scroll interno es un cambio de UX (antes crecía con la página); es el precio de que la página no haga scroll y coherente con un «modo chat» a pantalla completa.
- El watchdog de re-suscripción (30 s) envía un `pusher:subscribe` por página de chat abierta: coste despreciable (un mensaje pequeño; el servidor re-registra la conexión), y es lo que hace el canal auto-reparable ante reinicios de Reverb y zombis de TCP half-open.
- `namespace: ''` en Echo web: si en el futuro se quieren eventos namespaced en la web, hay que actualizar el `broadcastAs` del emisor (o el namespace) de forma conjunta; hoy solo el canal `agent.{id}` usa `.listen()`.

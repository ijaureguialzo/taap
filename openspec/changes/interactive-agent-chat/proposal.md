# Proposal

## Why

El chat de la web no muestra la actividad real de los agentes: el indicador de estado se congela en «arrancando» (el index suscribe un canal `private-` que nadie difunde y el detalle no sobrevive una reconexión), el razonamiento del modelo nunca se ve (el contrato normalizado no tiene `thinking` y cada runtime lo trata de forma distinta: Pi lo mezcla con el texto, Claude lo descarta, OpenCode lo funde en el texto final), los `message.delta` no se difunden (la burbuja «pensando…» es una etiqueta congelada hasta el `message.final`), una aprobación pendiente deja el input deshabilitado sin tarjeta visible tras un refresco (callejón sin salida), el chat salta al principio cuando el agente responde y las marcas de tiempo parpadean (ISO visible hasta que el JS las reformatea). Además, las respuestas con Markdown se pintan como texto plano.

## What Changes

- **Contrato de eventos (backend)**: se añade `thinking.delta` al modelo normalizado, `message.final` lleva el razonamiento completo del turno y los deltas (texto y razonamiento) **se difunden por el canal Reverb `agent.{id}`** (hoy se saltan expresamente).
- **Mapeo de razonamiento por runtime**: los cinco adaptadores exponen su superficie nativa de reasoning a través del nuevo tipo (Pi `thinking_delta`/bloques, Claude sidecar, Codex items de razonamiento, OpenCode partes `reasoning`, Hermes `reasoning_content`), corrigiendo las mezclas actuales de thinking con el texto.
- **Chat web (streaming)**: la burbuja de streaming se rellena en el cliente (sin round-trip por token) con texto y sección «Pensando…»; al asentarse, el mensaje final muestra secciones colapsables «Razonamiento» y «Actividad» (reconstruida desde `agent_events` ya persistidos), estilo Claude Code/TUI.
- **Actividad visible**: las invocaciones de herramientas (inicio, resultado, errores) se renderizan en el feed de la burbuja en vivo.
- **Aprobaciones**: las pendientes se reconstruyen al abrir o re-sincronizar el chat (tarjeta con opciones; sin el estado de input deshabilitado sin tarjeta).
- **Indicador de estado**: el index usa el canal público, el badge (text+icono) se actualiza para cualquier estado, el detalle y el chat sobreviven reconexiones (watchdog de re-suscripción) y `start()` emite su transición como ya hace `stop()`.
- **UX de scroll y tiempos**: el chat permanece anclado al final cuando el agente responde si el usuario estaba cerca del final (sin saltar al principio; sin arrastrar al usuario que lee historial); las marcas de tiempo nunca se pintan en formato no local (sin parpadeo).
- **Markdown**: las burbujas (agente y usuario) renderizan Markdown sanitizado en historial y streaming, en los dos temas.

No breaking: es una extensión aditiva del contrato (los clientes existentes que ignoran tipos desconocidos siguen funcionando); no hay cambios de API REST.

## Capabilities

### New Capabilities

(ninguna: los comportamientos nuevos viven en capacidades existentes)

### Modified Capabilities

- `agent-chat`: streaming real de texto+razonamiento, actividad de herramientas visible en el chat, secciones colapsables en el mensaje final, reconstrucción de aprobaciones pendientes, scroll del viewport, marcas de tiempo sin parpadeo y renderizado Markdown.
- `agent-runtimes`: el modelo normalizado añade `thinking.delta` (y `thinking` en `message.final`); cada uno de los cinco adaptadores SHALL exponer el razonamiento nativo a través de él (paridad entre runtimes).
- `agent-lifecycle`: la operación de arranque emite la transición de estado a los clientes (paridad con las demás transiciones).

## Impact

- **Backend**: `AgentEvent`, `EventSink` (difusión de deltas + persistencia del thinking en `chat_messages.meta`), `AgentManager` (emisión en `start()`), `ChatService`/`ChatController` (aprobaciones pendientes), los 5 adaptadores + sidecar Claude (mapeo reasoning), `TaapBridgeCommand` (sin cambios esperados, por verificar), `Chat`/`Detail`/`Index` Livewire.
- **Frontend web**: `chat.blade.php` + JS inline (streaming en cliente, feed, scroll, tiempos, badge), `detail.blade.php`/`index.blade.php` (estado en vivo), `app.css` (secciones, Markdown, temas), nueva dependencia `marked` + `dompurify` (vite).
- **Contrato/specs**: deltas en `agent-chat`, `agent-runtimes`, `agent-lifecycle`.
- **Fuera de alcance (change aparte)**: cliente iOS (consumirá el contrato extendido en su propio change: streaming, razonamiento, paridad de aprobaciones); la app ya ignora tipos desconocidos sin romperse.
- **Riesgo**: volumen de deltas por canal Reverb (mitigado con aplicación en cliente; ver design.md); el mapeo de razonamiento de Codex exige un spike contra el app-server 0.171 antes de fijar su shape.

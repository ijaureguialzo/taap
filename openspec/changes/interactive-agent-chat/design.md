# Design

## Context

Pipeline actual (ver proposal.md para la motivación): cada runtime expone su superficie nativa (`pi --mode rpc`, `codex app-server` JSON-RPC, sidecar Node de Claude, `opencode serve` + stream SSE, gateway HTTP de Hermes). El daemon `TaapBridgeCommand` drena y normaliza a `AgentEvent` (10 tipos); `EventSink` persiste (`agent_events`), aplica side-effects (estado, `chat_messages`, aprobaciones) y difunde por Reverb al canal público `agent.{id}` — **salvo `message.delta`**, que se omite expresamente. La web (Livewire 4: `Detail` + `Chat` + JS inline con Echo) y la app iOS consumen ese canal.

Restricciones relevantes:

- El contrato `AgentEvent` es el único acoplamiento con los clientes: es aditivo (los clientes ignoran tipos desconocidos), por lo que extenderlo no rompe a iOS.
- El chat web tiene una historia de cicatrices con el morph de Livewire 4 (nodos sustituidos, `wire:ignore`, hooks `morph.*`, re-suscripción del canal): todo lo que toque el DOM del chat debe respetar esos patrones.
- El sidecar Claude valida el protocolo v1 (`EVENT_TYPES` en `ClaudeAdapter`); la imagen `taap/agent-claude` se rebuild con el orquestador en el despliegue.
- `agent_events` persiste ya todos los eventos no-delta (incluidos `run_id` en payload); `chat_messages.meta` es JSON (PG JSONB) y ya viaja por la API.
- No hay librería de Markdown en el frontend; el contenido se pinta con `white-space: pre-wrap`.

## Goals / Non-Goals

**Goals:**

- Streaming real en web (texto y razonamiento) con paridad de *contrato* para los 5 runtimes.
- La actividad del turno (herramientas, errores) visible en el chat, en vivo y en el historial.
- Aprobaciones siempre resolvables desde el chat (sin callejón sin salida).
- Indicador de estado correcto y vivo en lista y detalle.
- Scroll y marcas de tiempo sin saltos ni parpadeos; Markdown renderizado y sanitizado.

**Non-Goals:**

- Cliente iOS (change aparte; el contrato le deja el camino preparado).
- Batching de deltas en el servidor (mitigación de la que, solo si la medida lo pide).
- Nuevas tablas o migraciones (se reutiliza `meta` JSON y `agent_events`).
- Cronología entrelazada thinking/texto dentro de la burbuja (sección única de razonamiento por mensaje).

## Decisions

### D1 — Contrato: `thinking.delta` + `thinking` en el final

- Nuevo tipo `thinking.delta` (payload `{run_id, content}`) en `AgentEvent`; `message.final` añade `thinking` (string, puede ser vacío) separado de `content`.
- `EventSink`: difunde `message.delta` **y** `thinking.delta` (se retira la exclusión); los deltas siguen sin persistirse como fila (lo persistente es el final con `meta.thinking`).
- Mapeo por runtime (el punto de paridad de la spec `agent-runtimes`):

| Runtime | Superficie nativa | Mapeo |
|---|---|---|
| Pi | `message_update.assistantMessageEvent` (`text_delta`/`thinking_delta`); bloques `text`/`thinking` en `message_end` | `thinking_delta` → `thinking.delta` (hoy se mezcla en `message.delta`: se corrige); bloques `thinking` de `message_end` → `thinking` del final (hoy se descartan) |
| Claude | bloques del SDK en el sidecar | bloques `thinking` → `thinking.delta` (hoy descartados); el sidecar acumula el turno y lo emite en `result` como `thinking` del final; `ClaudeAdapter` añade `thinking.delta` a `EVENT_TYPES` (protocolo v1 se mantiene: la extensión es aditiva) |
| Codex | **spike pendiente** contra app-server 0.171 | lo que el spike identifique (items/deltas de razonamiento) → `thinking.delta`; si el binario no expone razonamiento, `thinking` = vacío (el contrato lo permite) |
| OpenCode | stream: partes `text` vs `reasoning` (`message.part.delta`/`updated`) | parte `reasoning` → `thinking.delta`; `finishTurn` separa: `content` = partes text, `thinking` = partes reasoning (corrige la fusión actual) |
| Hermes | SSE `delta.content` / `delta.reasoning_content` | `reasoning_content` → `thinking.delta` (hoy solo como fallback cuando `content` es vacío) |

Alternativa descartada: codificar el thinking como `message.delta` con un flag — mezclaría los flujos en el cliente y obligaría a re-pintar lo ya mostrado; dos tipos es más claro y es lo que la spec exige.

### D2 — Streaming en el cliente, sin round-trip por token

Hoy cada evento del canal hace `wire.agentEvent()` → round-trip Livewire → re-render completo (con re-consulta de 200 mensajes). Con deltas difundidos, un round-trip por token es inviable.

- `message.delta`/`thinking.delta`: el JS los aplica **localmente** (append a nodos de texto dentro del contenedor de la burbuja de streaming), sin llamar a Livewire.
- `run.*`, `tool.*`, `approval.*`, `status.change`, `error`, `message.final`: siguen por `wire.agentEvent()` (baja frecuencia).
- La burbuja de streaming la **ancla** el componente (`@if ($streaming !== null)` renderiza el esqueleto: etiqueta + contenedor), pero el contenedor de contenido va `wire:ignore`: el morph lo salta y el append del cliente sobrevive a cualquier re-render. En `message.final`, el round-trip limpia `streaming` y recarga el historial (comportamiento existente); el JS retira el contenedor que mantenía.
- Mismo modelo que `ChatSession` de iOS (delta en memoria local, final por API): paridad de mecanismo entre clientes.

Alternativa descartada: round-trip por delta con `wire:text` — simple pero RTT+re-render por token; y SSE de largo plazo — duplicaría el canal existente.

### D3 — Feed de actividad y secciones colapsables

- **En vivo**: `tool.start`/`tool.result`/`error` se pintan como líneas compactas en el mismo contenedor `wire:ignore` de la burbuja (⚙ `tool: detalle`, ✓/✗ + resumen truncado), aplicadas por el JS (la actividad es parte de la superficie en streaming). La propiedad `events[]` del componente (mantenida pero nunca renderizada) se elimina.
- **En el mensaje final**: dos secciones `<details>`-estilo, colapsadas por defecto:
  - `Razonamiento` — `meta.thinking`, texto plano atenuado (sin Markdown), con su contador de tamaño; solo si no es vacío.
  - `Actividad (N)` — reconstruida por `history()` con **una** consulta por lote: `AgentEventRow` donde `payload->run_id ∈ run_ids de los mensajes visibles` y tipo ∈ {`tool.start`, `tool.result`, `error`}, ordenada; solo se renderiza si N>0. Sin nueva persistencia (todo ya está en `agent_events`).

### D4 — Aprobaciones: reconstrucción al abrir + defensa en vivo

- `Chat::mount` (y `agentEvent` al recibir `status.change` a `waiting-approval` sin tarjeta): consulta las filas `approval.request` no resueltas (misma semántica de NULL que usa `ChatController::messages` para `pending_approvals`: la app iOS ya lo consume; la web pasa a usar la misma lógica) y rellena `pendingApproval`/`pendingApprovalInfo`.
- Con esto, el estado «input deshabilitado» coexiste siempre con la tarjeta visible (la spec lo exige). La API REST no cambia.

### D5 — Estado en vivo: badge, botones, input

- **Index**: `Echo.private('agent.{id}')` → `Echo.channel(...)` (el canal es público; `private-agent.{id}` nunca recibe nada — la causa principal del badge congelado).
- El badge (componente `status-badge`) recibe un **mapa completo** estado→etiqueta renderizado por el servidor (JSON) y el JS actualiza **texto e icono** para cualquier estado; el hook de re-suscripción del canal (30 s + `connected`) se extrae a un helper compartido por index/detalle/chat (hoy triplicado con variantes desiguales: el detalle ni lo tenía).
- `Detail`: los botones start/stop se renderizan desde `liveStatus` (ya existe) en lugar del modelo en memoria, para que coincidan con el badge.
- `Chat`: sigue un `liveStatus` propio vía `agentEvent(status.change)`; el `disabled` del input y el aviso de ayuda se basan en él (fallback: el estado del modelo).
- `AgentManager::start()` y `provision()` emiten `statusChange('starting')` (hoy solo `stop()` emite; la spec `agent-lifecycle` exige notificar toda transición). `EventSink::setStatus` ya difunde los cambios posteriores.

### D6 — Scroll: «pin si estaba cerca del final», inmune al morph

- Antes de cada morph (`hook updated`/`morph.updated`): registrar `nearBottom = scrollHeight - scrollTop - clientHeight < 80px` del `#chat-box` actual.
- Al completar el morph (`morphed`/`island.morphed`, que ya se usan para las fechas): si `nearBottom` (o acaba de llegar un append de streaming) → `scrollTop = scrollHeight`; si el nodo fue sustituido, re-anclar el `MutationObserver`.
- El `MutationObserver` sigue vivo solo como mecanismo de crecimiento *dentro* del nodo (los appends de streaming), con la misma guarda `nearBottom` (no arrastra a quien ha subido a leer). Se elimina el re-ancrado por `setInterval` de 30 s (el hook lo cubre síncrono).
- Esto corrige el salto al principio: hoy el morph copia el `scrollTop` bruto del nodo viejo (con contenido más alto, la vista termina arriba) y el re-ancrado tarda hasta 30 s.

### D7 — Fechas sin parpadeo

- El script inline del `<head>` (que ya resuelve el tema antes del primer pintado) añade la clase `js` a `<html>`.
- CSS: `html.js time.chat-time, html.js time.tl-time { visibility: hidden }` hasta que el JS añade `.is-ready` tras formatear (`visibility`, no `display`: se reserva el espacio, sin salto de ancho). Sin JS: se ve el ISO (fallback actual, sin parpadeo porque no hay reformateo).
- Los hooks existentes de formateo (idempotentes, `wire:ignore` en los nodos) se conservan; solo se añade el marcado `is-ready`.

### D8 — Markdown

- Dependencias: `marked` + `dompurify` (npm, vía vite en `app.js`; sin build extra).
- Renderizado en: burbujas de historial (agente **y** usuario, para consistencia) y burbuja de streaming (re-render del Markdown con throttle ~100 ms durante el stream; entre renders, append plano de texto para no perder tokens). La sección de razonamiento queda como texto plano (`pre-wrap`), sin Markdown.
- `DOMPurify` con config saneada (sin `script` ni atributos de evento; enlaces con `rel`/`target`); GFM activado (tablas, tachado, listas).
- CSS `.md` para los dos temas (bloques de código con fondo, código inline, tablas, enlaces) usando tokens de marca; se conserva `word-break: break-word` para strings largas.

## Risks / Trade-offs

- [Volumen de deltas por canal Reverb] → la aplicación es en cliente (append O(1)/evento) y los suscriptores por canal son pocos (dueño/admin); se mide en la verificación e2e y, solo si hay señal, se añade batching en `EventSink` (follow-up, ya anotado en proposal).
- [Superficie de razonamiento de Codex desconocida] → spike contra el app-server 0.171 **antes** de tocar el adapter; si no expone razonamiento, Codex emite `thinking` vacío (el contrato lo permite; se documenta en el spike y en release notes).
- [Burbuja sustituida por morph a mitad de stream] → contenedor `wire:ignore` + re-ancrado en los hooks + el paso e2e por runtime lo cubre; si aparece un caso residual, el round-trip de `message.final` reconstruye el estado final (peor caso: se pierde parte del texto *en streaming*, nunca el mensaje asientado, que viene de la BD).
- [`meta` JSON con razonamientos largos (decenas de KB)] → PG JSONB lo absorbe; la API lo devuelve entero (la web lo necesita); iOS lo ignora de momento. Se trunca el *render* (no el dato) en el bloque colapsado.
- [Markdown en contenido con `pre-wrap` actual] → el render reemplaza el layout; se mitiga con `word-break` y con que solo se aplica a `.message-content` (el resto de la UI no cambia).
- [Imágenes/contenidos viejos sin el nuevo mapeo (p. ej. sidecar Claude anterior al rebuild)] → simplemente no emiten `thinking`/deltas: degradan a la conducta actual, sin rotura.

## Migration Plan

1. Sin migración de BD (JSON `meta` + `agent_events` existentes) y sin cambio de API REST.
2. Despliegue: orquestador (backend + assets) y rebuild de `taap/agent-claude` **juntos**; los demás runtimes no requieren imagen nueva (la normalización vive en el orquestador).
3. Degradación al desplegar solo parte: web nueva + bridge viejo = deltas no difundidos → la burbuja espera al final (conducta actual); bridge nuevo + web vieja = tipos desconocidos ignorados. Rolloback = revertir el despliegue; no hay estado irreversiblemente nuevo.

## Open Questions

- Shape exacto del razonamiento en Codex app-server 0.171 (lo resuelve el spike; no cambia specs ni tarea, solo el mapeo del adapter).
- Umbral y throttle concretos (80 px de pin, 100 ms de re-render Markdown): se ajustan en verificación sin tocar specs.

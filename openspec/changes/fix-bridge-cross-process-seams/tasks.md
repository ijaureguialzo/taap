# Tasks

## 1. Cola durable compartida en el área IPC (D1)

- [x] 1.1 Helper del área IPC para cola durable `turns` (append con `LOCK_EX` desde cualquier proceso; consume-y-trunca para el único consumidor; línea inválida → descartada), en `DockerClient` o `Support`, con los nombres/paths documentados junto a `in`/`out`
- [x] 1.2 Tests unitarios del helper: append desde un proceso y consume desde otro, consume vacío, dos appends en orden FIFO, línea corrupta, contienda append/consume con lock

## 2. Codex: entrega desde el worker y recuperación de hilo (D2, D3)

- [x] 2.1 `CodexAdapter::writeMessage` sin sesión: `thread_id` desde `$agent->config['thread_id']`; si existe → `turn/start` directo en `in`; si es `null` → apendar el contenido a `turns`; eliminar toda lectura de `$this->sessions[$agent->id]` del path worker (fín del *Undefined array key*)
- [x] 2.2 Flujo del bridge: `rememberThread` y el tick de drenado drenan `turns` y emiten los `turn/start` encolados (exactamente una vez, en orden)
- [x] 2.3 Recuperación ante `thread/resume` fallido: respuesta con `error` al request `taap-thread-*` → limpiar `thread_id`/`thread_established` en memoria (la siguiente secuencia hará `thread/start` nuevo y `rememberThread` persistirá el id)
- [x] 2.4 Tests unitarios: worker sin sesión con/ sin `thread_id` en config, cola `turns` emitida por el bridge, resume fallido → `thread/start` nuevo, sin *Undefined array key* en ningún caso

## 3. OpenCode: correlación del turno entre procesos (D4, D5)

- [x] 3.1 `OpenCodeAdapter::writeMessage`: apendar el `run_id` a `turns` tras dispatchar `run.start`; sustituir `ensureAttached()` por comprobación ligera de viabilidad (contenedor `running` + área IPC existente) sin spawneo/kill de coprocesos ni truncado de `out`
- [x] 3.2 `normalize`: el primer evento del turno (`session.status busy` o primer delta/parte) consume la línea de `turns` → `run_id` de la sesión (FIFO si hay varios)
- [x] 3.3 `finishTurn`: consumir/marcar la línea de `run_id`; limpiar SIEMPRE `parts`/`thinking_parts`/`msg_roles`/`tool_seen`; sin `run_id` consumible → generar uno propio y emitir igualmente `message.final`/`run.end` (nunca descartar un turno terminado)
- [x] 3.4 Tests unitarios: worker→bridge con dos instancias (el final se emite una vez con el `run_id` del worker), `finishTurn` sin `run_id`, dos turnos encolados (FIFO de `run_id`), worker sin efectos sobre `out`/coprocesos

## 4. Tests de integración contra la costura (D6)

- [x] 4.1 Test de integración Codex con dos instancias de adapter (una adjunta y drena, otra recién construida): el mensaje entregado por la segunda produce exactamente un `run.start`…`run.end` + `message.final` drenados por la primera, con el `run_id` dispatchado por la segunda, y el historial persistido
- [x] 4.2 Test de integración OpenCode con dos instancias: lo mismo que 4.1, y además verificación de que tras el envío solo existe un lector vivo (sin huérfanos del worker)
- [x] 4.3 Confirmar contra el binario opencode 1.19.6 la emisión de `busy→idle` con dos `prompt_async` en batería (Open Question del design): si es un par por turno, el FIFO de `run_id` de 3.2 es suficiente; si no, ajustar el consumo al par de eventos que el servidor emite

## 5. Verificación en el despliegue real

- [ ] 5.1 Con el agente Codex de producción: enviar un mensaje desde la web/iOS y verificar respuesta en el chat, `run.start`…`run.end` + `message.final` en `agent_events`, mensaje en `chat_messages` y status `idle` al final (sin el error *Undefined array key*)
- [ ] 5.2 Con el agente OpenCode de producción: enviar un mensaje (p. ej. un tutorial largo) y verificar la respuesta visible en web e iOS, persistida, y `run_id` coherente en todos los eventos del turno
- [ ] 5.3 Verificar post-cambio: un único par de coprocesos por agente tras envíos desde el worker y el hilo de Codex sano (el `thread_id` persistido resume sin error)

## Workflow follow-up

- Sincronizar la spec `agent-runtimes` al archivar (deltas en `specs/agent-runtimes/spec.md`).
- Abierto para un cambio posterior (no bloquea este): churn de ciclo de vida de coprocesos + inundación de logs del `stale-check` (`fix-bridge-coprocess-lifecycle`).

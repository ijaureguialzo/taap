# Design

## Context

La costura rota (motivos en proposal.md): el daemon `taap:bridge` y los workers de cola son **procesos distintos** dentro del mismo contenedor; comparten la base de datos, el área de IPC por ficheros (`/tmp/taap-rpc/<id>/`) y el canal Reverb, pero **no comparten memoria**. Los adapters guardan estado de sesión en memoria (`$sessions`), y dos de los cinco runtimes lo leen desde el lado equivorado de la costura:

- `CodexAdapter::writeMessage` (worker) → `writeTurn()` → `$this->sessions[$agent->id]` (siempre vacía en el worker) → PHP 8 *Undefined array key* → "No se pudo entregar el mensaje".
- `OpenCodeAdapter::writeMessage` (worker) escribe `run_id` en su sesión en memoria; el bridge (drenado) lo tiene a `null` → `finishTurn()` hace *early return* y descarta el texto acumulado (sin `message.final` ni `run.end`).
- `OpenCodeAdapter::writeMessage` llama a `ensureAttached()` → en el worker eso hace un `attach()` completo: truncar `out` + spawnear un lector SSE duplicado + matar al lector del bridge vía el `.pid` compartido → cat-fight y re-attaches (verificado: 4 re-attaches encajando 1:1 con los 4 mensajes de usuario).

Evidencia de producción (2026-11-24): 6× "No se pudo entregar el mensaje: Undefined array key 2" (agente 2); agente 5 con 4 mensajes de usuario y 0 del agente a pesar de respuestas de ~10.450 tokens intactas en la BD de opencode y en el fichero `out`; el `in` de Codex contiene solo secuencias de arranque (nunca un `turn/start`).

Los tests de integración actuales corren adapter + drain **en el mismo proceso**, donde la memoria sí es compartida: por eso la costura no se veía.

Limitantes que condicionan el diseño: el IPC entre procesos ya existe por ficheros (`in`/`in.off`/`out`/`err` + `.pid`), el `config` JSON de la BD es el único estado durable rico que ambos procesos leen, y el área de IPC vive en `/tmp` del contenedor del orquestador (ambos procesos, mismo contenedor → comparten el `/tmp`).

## Goals / Non-Goals

**Goals:**
- Que un mensaje enviado a un agente Codex o OpenCode se entregue y se responda aunque la entrega ocurra en un proceso sin sesión IPC (el worker).
- Que el turno OpenCode se correlacione entre procesos: el final se persiste y difunde una vez, con el `run_id` que el cliente recibió.
- Que el worker no tenga efectos colaterales sobre el ciclo de vida del IPC (ni coprocesos duplicados ni stream truncado).
- Que Codex se auto-sanee cuando su hilo persistido no tiene rollout (hilo creado sin turnos).

**Non-Goals:**
- El churn de ciclo de vida de coprocesos del bridge (124 re-adjuntados "lector stale", 2 writers en Claude): sigue el cambio `fix-bridge-coprocess-lifecycle`.
- La inundación de logs del `stale-check` (4.3M líneas).
- Cambios en clientes, API pública o imágenes de agente.
- Pi/Claude/Hermes: sus paths de worker ya son stateless (se mantienen, sin refactor).

## Decisions

### D1. Un solo mecanismo: cola durable en el área IPC (`turns`)
Ambos fixes necesitan "pasar un dato de X de un proceso a otro". En vez de dos artificios, un helper compartido en `DockerClient` (o `Support`): un fichero `turns` en el área IPC del agente, de líneas append-only (el worker **añade**, solo el bridge **consume y trunca**):

- **Codex**: línea = contenido del mensaje pendiente (cuando el worker no tiene `thread_id` persistido). El bridge lo emite como `turn/start` al establecer el hilo (`rememberThread`) y en cada tick de drenado (cubre la race).
- **OpenCode**: línea = `run_id` del turno en curso (el worker lo añade tras el `prompt_async`). El bridge la consume en el primer evento del turno (`session.status busy` / primer delta) y la usa en `finishTurn`.

Razón: el área IPC es el único medio cross-proceso ya establecido (mismo `/tmp`, mismo contenedor), sobrevive a la vida y muerte de cualquier proceso, y el patrón "un productor, un consumidor, consume-y-trunca" evita lecturas concurrentes. Alternativas descartadas: (a) columna en la BD para el `run_id` activo — más escritura caliente y una fuente de verdad doble (el config ya es el patrón para ids de sesión); (b) reutilizar `in` — no sirve: el coproceso writer la consume línea a línea como JSON-RPC y un marcador pendiente se entregaría tal cual al app-server.

### D2. Codex: el worker lee el `thread_id` del config persistido
`writeMessage` sin sesión: `thread_id = $agent->config['thread_id']`. Si existe → escribe `turn/start` directamente en `in` (el writer del bridge lo entrega; no hay que hacer nada más). Si es `null` → añade el contenido a `turns` (D1). `rememberThread` (ya persiste el id en el config) además drena `turns` y emite los turnos encolados. Alternativa descartada: que el worker haga `attach()` completo — reintroduce el cat-fight de D5 y un lector que drena en el proceso equivocado.

### D3. Codex: recuperación ante `thread/resume` fallido
En la respuesta al request de hilo (`taap-thread-*`): si llega `error` (p. ej. *"no rollout found"*) → limpiar `thread_id` + `thread_established` en la sesión **en memoria** → el próximo ciclo de arranque hará `thread/start` nuevo y `rememberThread` persistirá el id nuevo. No se limpia el config: si el bridge muere antes, la siguiente vida re-resumea, falla y se recupera de nuevo (auto-sanado; un resume fallido por ciclo es barato). Alternativa descartada: forzar un `thread/start` inmediato en el mismo `normalize` — el `normalize` no debería escribir en el IPC (violaría la disciplina de que solo la secuencia de arranque escribe comandos de sesión; además el resultado de ese `start` llegaría por el mismo stream y el flujo natural ya lo recuerda).

### D4. OpenCode: `run_id` compartido por `turns` + cierre por evento (D4, 4.3)
El worker añade el `run_id` a `turns` justo tras dispatchar `run.start` (el cliente ya lo tiene; el bridge lo adopta). En `normalize`, el primer evento de un turno (`session.status busy` o primer delta/parte) consume la línea más antigua de `turns` → sesión `run_id`; `finishTurn` siempre limpia `parts`/`thinking_parts`/`msg_roles`/`tool_seen` (hoy el *early return* los dejaba: texto viejo filtraría al turno siguiente si algún día el `run_id` existiera). Si `finishTurn` se llama sin `run_id` consumible (el worker no llegó a añadirlo, p. ej. job muerto a mitad) → el bridge adopta de la cola o genera uno nuevo: el final se emite igualmente (nunca se descarta un turno terminado).

**Límite de turno (4.3, verificado contra el binario 1.19.6 con dos `prompt_async` en batería):** la asunción "cada `busy→idle` consume su línea en FIFO" NO se cumple: el servidor procesa los prompts encolados secuencialemente dentro de UN solo período `busy` y emite un único `idle` para el batch entero (captura: 5× `busy` + 1× `idle` + 1× `session.idle` para 2 prompts). Además, un prompt con pasos de `tool` emite VARIOS mensajes de asistente (uno por paso, cada uno con su `time.completed`). Por tanto el cierre es por evento, con la regla: el `message.updated` de un mensaje de asistente con `time.completed` cierra el run **solo si hay otro prompt pendiente** (cola `turns` no vacía — `turnsPeek` sin consumir — o un mensaje `user` nuevo visto durante el run; los `user` se dedupan por id y el contador se a cero en cada arranque de run). Sin nada pendiente (multi-paso de un solo prompt), el contenido queda en buffers (ahora **por mensaje**: `messageID => [partID => texto]`) y el `idle` de sesion — que solo llega una vez por batch — cierra el turno entero de golpe. Esto da: batch de N prompts → N finales con sus N `run_id` (FIFO); prompt multi-paso → un final agregado con el `run_id` del worker, nunca dobles `run.end` ni runs colgados.

### D5. OpenCode: `writeMessage` sin `attach()`
El worker no hace `ensureAttached()`/`attach()`: no spawnea ni mata coprocesos ni trunca `out` (es el bridge quien drena). La viabilidad de la entrega se comprueba de otra forma: contenedor `running` (inspección ligera, como hoy) + área IPC existente. Las llamadas HTTP (`ensureSession`, `prompt_async`) no dependen del lector. Si el contenedor no va → mismo error de siempre ("agente no disponible"); el arranque ya lo provoca `ChatService::send` antes de encolar.

### D6. Tests contra la costura
Los tests de integración actuales (un proceso) no la cubren. Se añade: (a) unitarios de las ramas nuevas (worker sin sesión: thread del config / cola `turns`; `finishTurn` sin `run_id`; resume fallido); (b) un test de integración por runtime con **dos instancias de adapter** (una adjunta y drena — "bridge"; la otra, recién construida — "worker") contra un contenedor real: el mensaje entregado por la segunda produce exactamente un `message.final`/`run.end` drenados por la primera, con el `run_id` que la segunda dispatchó, y sin coprocesos duplicados tras el envío.

## Risks / Trade-offs

- [`turns` en `/tmp` se borra con el contenedor del orquestador] → Un mensaje pendiente en `turns` se perdería con un recreado del orquestador; mitigado: es el mismo riesgo que ya tiene `in`/`out` (los prompts en vuelo) y `ChatService` re-encola el job con `tries=2` en fallos de entrega. No lo empeoramos.
- [Race: el worker añade a `turns` y el bridge consume/trunca en el mismo instante] → Escritura de línea corta (≤ 2 KB) con `LOCK_EX` al añadir; el consume-y-trunca lo hace solo el bridge (un proceso) con el mismo lock; una línea leída a medias se descarta por formato inválido y, como el turno ya está en vuelo, el contenido se recupera de los eventos del stream (OpenCode) o el job reintenta (Codex, `tries=2`).
- [El `busy` de opencode llega tarde o el stream lo pierde (re-attach)] → Si el primer evento visto es directamente `idle`/`session.idle` con `run_id` sin consumir, `finishTurn` la consume igualmente: el final se emite (el texto de los deltas, si llegó, ya está en `parts`).
- [Dobles `thread/start` por churn de arranque (fuera de alcance)] → El churn puede seguir creando hilos vacíos que el bridge re-resumea; D3 convierte ese fallo en recuperación en vez de estado muerto, pero el churn en sí sigue hasta el cambio de coprocesos.
- [Comportamiento "run_id null aceptado" vs. spec] → El spec exige el mismo id que recibió el cliente; la rama defensiva (bridge genera id propio) solo se activa si el worker no escribió `turns`, y prefiere emitir el final con un id nuevo a descartarlo.

## Migration Plan

Sin migración de datos ni de esquema: `turns` es un fichero nuevo en el área IPC (inexistente = vacío) y `config['thread_id']` ya existe. Despliegue = imagen del orquestador; sin reiniciar agentes (el bridge adopta el comportamiento nuevo al arrancar; los agentes siguen vivos). Rollback = re-desplegar la imagen anterior (los estados creados por la nueva versión — `turns`, ids de hilo nuevos — son inofensivos para la vieja: `turns` la ignora y los ids de hilo los resume con normalidad).

## Open Questions

- ~~El `session.status busy` de opencode llega a veces después del fin del primer paso…~~ **RESUELTO (4.3, 2026-11-24):** contra el binario 1.19.6 con dos `prompt_async` en batería, el servidor emite UN solo par `busy→idle` para el batch entero (no uno por turno) y cada prompt con pasos de `tool` genera varios mensajes de asistente (uno por paso) con su propio `time.completed`. El consumo se ajustó en consecuencia (D4): cierre por `time.completed` solo con otro prompt pendiente; el `idle` único cierra lo que queda. Fijado por tests: unitarios (`test_batch_two_prompts_single_idle_closes_both_runs_fifo`, `test_multistep_single_turn_closes_once_on_idle`) e integración con el contener real (`test_opencode_batch_two_prompts_close_each_run_fifo`, que además asienta la observación del binario contando los `idle` en el stream crudo).

## Verificacion en el despliegue real (5.x, 2026-11-24)

Despues de re-desplegar la imagen del orquestador (con 4.3 incluida), contra la pila local completa (traefik + postgres + 5 agentes + Reverb):

- **5.1 Codex** (`taap-agent-2-codex`): mensaje via `POST /api/v1/agents/2/messages` (la misma ruta que usan web/iOS) → el worker lo entrego desde el `thread_id` del config → `run.start…run.end` + `message.final` con UN run_id, respuesta persistida en `chat_messages`, sin un solo error de costura (el "Undefined array key 2" de produccion desaparecio) y el agente quedo `idle`.
- **5.2 OpenCode** (`taap-agent-5-opencode`): tutorial largo via API → respuesta (2086 chars) visible por REST Y en vivo por WSS (probe pusher suscrito a `agent.5`, la misma ruta del cliente web): `run.start` (worker) → `thinking.delta`/`message.delta`/`message.final`/`run.end` (bridge) todos con el MISMO run_id `ed071453-4cf1…`, y persistido. La correlacion cross-proceso funciona en produccion. (Nota: el endpoint pusher de Reverb va por la app KEY — `/app/{key}` — no por el id; con el id da "Application does not exist".)
- **5.3**: despues de los envios, exactamente UN par de coprocesos por agente (todos con ppid = el bridge `taap:bridge`; OpenCode solo lector; Hermes nada) — cero hueros del worker. Hilo de Codex sano: se reinuncio el contenedor del agente (app-server nuevo) y el bridge resume el `thread_id` persistido DES DE DISCO sin error, con memoria del turno anterior (el agente la recupero en la respuesta).

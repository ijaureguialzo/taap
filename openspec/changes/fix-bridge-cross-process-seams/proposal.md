# Proposal

## Why

Dos de los cinco runtimes configurados rompen la entrega del chat por la costura entre procesos del bridge. El daemon `taap:bridge` (larga vida) mantiene las sesiones IPC en memoria; el job `BridgeSendMessage` corre en un worker de cola (proceso separado) cuyo adapter no tiene ninguna sesión en memoria. Los adaptadores asumen que comparten estado que no comparten:

- **Codex** (`taap-agent-2-codex`): `writeMessage` referencia `$sessions[$agent->id]`, inexistente en el worker → PHP 8 *Undefined array key 2* → cada mensaje falla con "No se pudo entregar el mensaje: Undefined array key 2" (verificado en producción: 7 mensajes de usuario, 0 respuestas del agente; el error apareció 6 veces en el log).
- **OpenCode** (`taap-agent-5-opencode`): el `run_id` del turno nace en la sesión en memoria del worker; el bridge (que drena el stream SSE) tiene `run_id=null` y `finishTurn()` descarta silenciosamente el texto acumulado al terminar el turno: sin `message.final`, sin `run.end`, la respuesta nunca se persiste (verificado: el LLM generó una respuesta de ~10.450 tokens, la BD de opencode conserva los 4 turnos, la BD de chat tiene 0 mensajes del agente y el stream `out` contiene todo el turno).

Ambos fallos eran invisibles a los tests de integración porque estos corren todo en un solo proceso (adapter + drain), donde la memoria sí es compartida.

## What Changes

- **Codex**: la entrega desde el worker usa solo estado durable — el `thread_id` se lee del config persistido del agente (lo que `rememberThread` ya guarda) en vez de la sesión en memoria; el primer mensaje antes de establecerse el hilo queda en una cola durable en el área IPC que el bridge emite cuando el hilo existe (hoy vive en memoria y es invisible entre procesos); si el `thread/resume` falla porque el hilo no tiene rollout (hilo creado sin turnos), el adapter descarta el hilo muerto y abre uno nuevo, en vez de re-resumearlo en cada attach.
- **OpenCode**: el `run_id` del turno se comparte entre procesos por el área IPC (fichero), de modo que el bridge correlaciona el turno y emite el final y el fin de ejecución con el identificador que recibió el cliente, exactamente una vez; `finishTurn()` limpia sus buffers aunque no haya `run_id` (evita que texto viejo se filtre al turno siguiente); el worker ya no hace un `attach()` completo al entregar un mensaje (eliminada la contienda de lectores SSE duplicados: el attach del worker truncaba `out` y mataba al lector del bridge vía el `.pid` compartido).
- **Fuera de alcance** (siguiente cambio, p. ej. `fix-bridge-coprocess-lifecycle`): el churn de ciclo de vida de coprocesos (124 re-adjuntados "lector stale" en el log; Claude con 2 writers vivos) y la inundación de logs del `stale-check` (4.3M líneas).

## Capabilities

### New Capabilities

(ninguna: el comportamiento durable vive en la capacidad existente de runtimes)

### Modified Capabilities

- `agent-runtimes`: nuevos requisitos de entrega y correlación a través del límite de procesos — entrega de mensajes sin sesión IPC en memoria (worker), correlación del turno entre procesos (`run_id` compartido), recuperación de hilo Codex sin rollout, y prohibido el adjuntado de IPC desde el worker.

## Impact

- `orchestrator/app/Agents/CodexAdapter.php` (worker path de `writeMessage`, cola durable de turnos, recuperación de `thread/resume`).
- `orchestrator/app/Agents/OpenCodeAdapter.php` (compartir `run_id` por el área IPC, `writeMessage` sin attach, higiene de `finishTurn`).
- `orchestrator/app/Agents/StdioRpcAdapter.php` y/o `app/Support/DockerClient.php` (helper del fichero de cola durable en el área IPC, común a ambos runtimes).
- Tests: los tests de integración actuales corren en un proceso; se añaden tests con dos instancias de adapter (simulan los dos procesos) y unitarios de las nuevas ramas.
- Especificación `agent-runtimes` (deltas ADDED/MODIFIED). La spec `agent-chat` no cambia: el comportamiento que exige ("Send and receive": la respuesta se muestra y se persiste) es el que ya declara; este cambio hace que la implementación cumpla.
- Sin cambios de API pública, de clientes ni de imágenes de agente.

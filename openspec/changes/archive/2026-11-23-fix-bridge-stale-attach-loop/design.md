# Design

## Context

El mecanismo de IPC stdio (`StdioRpcAdapter` + `DockerClient`) mantiene por agente un lector (`docker logs -f --tail 0 <contenedor>` → `$dir/out`) y un escritor (bucle `sh` que entrega `$dir/in` al FIFO vía `docker exec -i`), cada uno dentro de un wrapper `sh -c 'trap "kill $(jobs -p)" TERM INT; <job> & wait'` lanzado por `proc_open`. El watchdog `readerStale()` (en `drain()`) re-adjunta si `out` no crece en 60 s y hay entrada pendiente (`in > in.off`) o el agente está activo.

Evidencia recogida el 23/11 (orquestador en producción local, agentes 7-pi y 6-claudia):

- `production.WARNING: ... lector stale en ...; re-adjuntando` cada ~60 s **por agente**, alternando, aunque el agente está `idle` en la DB y `in == in.off`.
- Crecimiento de `docker logs -f` vivos re-adoptados por PID 1: 34 → 58 en 25 min (el wrapper muere; el hijo lector sobrevive). `killOrphans()` solo encaja cmdlines que contienen la carpeta rpc: los escritores sí, los lectores no.
- La trampa `trap "kill $(jobs -p)"` usa comillas dobles: `$(jobs -p)` se expande **al definir** la trampa (0 jobs) → al recibir TERM la trampa es `kill` sin argumento (no-op) → el wrapper sale y deja el hijo vivo.
- Duplicación del evento final (mensajes 148/149): `attach()` trunca `out` y el CLI de docker re-emite la última línea al reconectar (documentado como trade-off en el código) → `drain` lo vuelve a leer → `message.final` duplicado.
- `in.off` llegó a no existir un instante (el writer no había avanzado desde 14:42 con 127 B pendientes): `filesize(null) → 0` → `in > 0` → stale legítimo en ese momento; el bucle *continuó* después de resolverse, lo que apunta a que el disparo persistente es otro (ver D1).

## Goals / Non-Goals

**Goals:**
- 0 re-adjuntados en agentes en reposo sin trabajo (churn eliminado).
- Al menos un par lector+escritor vivo por agente en ejecución, aunque el IPC se re-adjunte (sin huérfanos acumulados).
- Mensaje final único por turno, también si el IPC se re-adjunta a mitad de turno.
- Conservar la recuperación real: lector mudo de verdad con trabajo pendiente ⇒ re-adjuntado en la ventana del watchdog.

**Non-Goals:**
- El gap de ~30 s entre un reinicio real del contenedor y el re-adjuntado (ya aceptado en `fix-pi-applyconfig-hang`).
- Rediseñar la superficie IPC (ficheros vs pipes) o la cadencia del bucle del bridge (`TaapBridgeCommand` no se toca).
- El runtime `api-events` (OpenCode) no tiene writer; solo hereda los cambios de `killOrphans`/trampa.

## Decisions

### D1: Condición exacta de «lector stale» (confirmar por instrumentación)

Dos causas candidatas para el disparo persistente; la tarea 1.1 instrumenta `readerStale()` (edad del último cambio de `out`, `in`, `in.off`, estado en memoria, estado docker) y 5 min de reposo las distinguen:

- **(a) Estado en memoria divergente de la DB.** El bridge conserva `$session['agent']`; si el estado quedó `starting`/`working` en copia (p. ej. tras un `forceFill` en el attach) y la transición a `idle` la hizo otro proceso, el chequeo ve agente «activo» para siempre. **Fix:** el chequeo usa el estado fresco (`$agent->refresh()->status`) o actualiza el modelo de la sesión cuando el agente emite `agent_settled`.
- **(b) `in.off` ausente/0.** `filesize()` de un fichero inexistente → 0 → `in > 0` ⇒ «pendiente». **Fix:** tratar `in.off` ausente como sin-pendiente solo si `in` también está vacío; si `in` tiene contenido y el escritor no está vivo, sí es pendiente legítimo (el re-adjuntado lo resuelve).

Se implementa lo que la evidencia marque (probablemente (a), o (a)+(b)); la condición final debe ser: *stale ⇔ (60 s sin salida) ∧ (entrada pendiente real ∨ agente activo según estado fresco)*.

### D2: Terminación fiable de coprocesos

1. **Trampa con comillas simples** en `attachReader()` y `startWriter()`: `trap 'kill $(jobs -p)' TERM INT` → `$(jobs -p)` se evalúa **al recibir** la señal (con los jobs en pie) → matar el wrapper mata también a `docker logs -f` / el bucle. Compatible con busybox `sh` (imagen actual).
2. **`killOrphans()` por firma de contenedor**: además de la carpeta rpc, matar procesos cuyo cmdline contenga `docker logs -f --tail 0 <contenedor>` y `docker exec -i <contenedor>` (los nombres `taap-agent-N-*` son únicos) → recoge también los hijos re-adoptados por PID 1 que hoy nadie mata.
3. Orden en `killCoprocess()`: TERM al wrapper (ya con trampa eficaz) → esperar brevemente → SIGKILL wrapper → `killOrphans()` como red de seguridad.

### D3: Duplicación del evento final (si procede)

Con D1+D2 el churn desaparece y la re-emisión «última línea al reconectar» solo ocurre en re-adjuntados reales (raros). Si la E2E (tarea 3.2/3.4) sigue mostrando `message.final` duplicado, se añade deduplicación en la persistencia del evento final por `(run_id, responseId)` — guard idempotente, sin cambiar el contrato de eventos.

## Risks / Trade-offs

| Riesgo | Mitigación |
|--------|-----------|
| Matar de más (un coproceso vivo que `killOrphans` encaja por firma) | Seguro: el siguiente ciclo lo recrea; la firma incluye el nombre único del contenedor |
| `refresh()` en cada chequeo añade una query por agente/ciclo | Un `SELECT` por agente cada 30 s es despreciable; alternativa: cachear el estado actualizado por evento |
| Cambiar la condición stale enmascara un lector mudo real | El escenario spec «Lector realmente mudo se recupera» + la E2E 3.2/3.4 cubren la recuperación |
| busybox `sh` y `trap`/`jobs` | Verificado en el contenedor antes de desplegar; fallback: wrapper que mata el hijo explícitamente por `$!` |
| Huérfanos ya existentes al desplegar | Limpieza puntual (pkill por firma) tras reiniciar el bridge (tarea 3.1) |

## Migration Plan

1. Rebuild + reinicio del orquestador (el bridge arranca con el entrypoint; los coprocesos nuevos nacen limpios).
2. Limpieza puntual de huérfanos previos dentro del contenedor (pkill por firmas de D2).
3. Observación ≥10 min (tarea 3.1) antes de la E2E.
4. Rollback: revertir el commit; el estado anterior (churn) es el de hoy, no regresivo.

## Open Questions

- Causa exacta del disparo persistente ((a) vs (b) vs ambas): resuelve la instrumentación 1.1/1.2 antes de fijar el código de D1.

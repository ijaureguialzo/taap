# Proposal

## Why

El daemon bridge (`TaapBridgeCommand`) entra en un bucle de re-adjuntado de IPC: para cada agente stdio (Pi y Claude) dispara el watchdog «lector stale» y re-adjunta el coproceso cada ~60 s aunque el agente esté en reposo sin trabajo pendiente (verificado el 23/11: avisos `re-adjuntando` cada ciclo para `taap-agent-7-pi` y `taap-agent-6-claudia`). Cada re-adjuntado deja un lector `docker logs -f` huérfano re-adoptado por PID 1 (34→58 en 25 min) y, como colateral, el CLI de docker re-emite la última línea al reconectar, lo que produce eventos finales duplicados (mensajes 148/149). El consumo de CPU por spawneos/ciclos y la acumulación lenta de procesos degradan el orquestador con el tiempo.

## What Changes

- Corregir la condición del watchdog `readerStale` (base `StdioRpcAdapter`) para que no se declare un lector stale cuando no hay entrada pendiente de entregar ni el agente está activo; el disparo real se confirmará con instrumentación temporal antes de fijar el criterio exacto (el estado en memoria del bridge puede divergir de la DB, y un `in.off` ausente se lee como 0).
- Hacer que el re-adjuntado matine siempre los coprocesos previos: corregir la trampa del wrapper (`trap "kill $(jobs -p)"` se expande al definirla, con 0 jobs → no hace nada) y ampliar `DockerClient::killOrphans()` para que también encaje la firma del lector (`docker logs -f --tail 0 <contenedor>`), que hoy no lleva la carpeta rpc en el cmdline.
- Garantizar que el evento final de un turno se persista una única vez aunque el IPC se re-adjunte durante el turno (el bucle detenido elimina la duplicación; se añade deduplicación por `responseId` si la E2E sigue mostrando dobles).
- Limpiar los coprocesos ya huérfanos y dejar a cada agente exactamente un par lector+escritor vivo.

## Capabilities

### New Capabilities

(ninguna)

### Modified Capabilities

- `agent-runtimes`: nuevo requisito «Ciclo de vida de la sesión IPC del bridge»: el daemon SHALL mantener un único par de coprocesos vivo por agente en ejecución, el re-adjuntado por lector stale SHALL solo dispararse cuando haya entrada pendiente o el agente activo, y el re-adjuntado SHALL no acumular procesos huérfanos ni duplicar el evento final de un turno.

## Impact

| Aspecto | Detalle |
|---------|---------|
| **Código afectado** | `app/Agents/StdioRpcAdapter.php` (condición `readerStale` + trampa del wrapper en `spawnCoprocesses`), `app/Support/DockerClient.php` (`killOrphans`), eventuales guards de deduplicación en `EventSink` |
| **Sin cambios en** | `TaapBridgeCommand.php` (el bucle principal y su cadencia se conservan), la UI, la DB, las imágenes de agente |
| **Efecto funcional** | Agentes en reposo sin churn de IPC (0 re-adjuntados), sin crecimiento de procesos y sin mensajes finales duplicados; el comportamiento de recuperación real (lector muerto de verdad) se conserva |
| **Riesgo** | Bajo-medio: matar de más un coproceso vivo es seguro (el re-adjuntado siguiente lo recrea); matar de menos sigue siendo el estado actual. Afecta a Pi y Claude (superficie `rpc-stdio`); OpenCode (`api-events`) hereda el cambio de `killOrphans` sin writer |
| **No resuelve** | El gap de ~30 s entre un reinicio real de contenedor y el re-adjuntado (documentado y aceptado en `fix-pi-applyconfig-hang`) |

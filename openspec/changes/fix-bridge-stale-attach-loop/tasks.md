# Tasks

## 1. Diagnóstico: confirmar el disparo del stale

- [ ] 1.1 Instrumentación temporal en `StdioRpcAdapter::readerStale()` (log debug: edad del último cambio de `out`, tamaño `in`, `in.off` (incl. ausencia), estado en memoria, estado docker) y observar ≥5 min con ambos agentes en reposo: registrar qué condición dispara.
- [ ] 1.2 Confirmar la causa (estado en memoria divergente vs `in.off` ausente) y ajustar/fijar el criterio de la tarea 2.1; dejar el log a nivel `debug`.

## 2. Implementar el fix

- [ ] 2.1 Corregir la condición stale en `StdioRpcAdapter::readerStale()` según 1.2 (estado fresco vía `refresh()`; `in.off` ausente ⇒ sin-pendiente solo si `in` está vacío, pendiente real si `in` tiene contenido y el escritor no está vivo).
- [ ] 2.2 Trampa con comillas simples en `DockerClient::attachReader()` y `startWriter()` (`trap 'kill $(jobs -p)' TERM INT`): verificar en el contenedor (busybox `sh`) que al matar el wrapper muere también `docker logs -f`/el bucle escritor.
- [ ] 2.3 Ampliar `DockerClient::killOrphans()` para que también enque la firma del contenedor (`docker logs -f --tail 0 <name>`, `docker exec -i <name>`); orden en `killCoprocess()`: TERM wrapper → espera → SIGKILL → `killOrphans()`.
- [ ] 2.4 (si 3.2/3.4 aún muestran duplicados) Guard de deduplicación del `message.final` por `(run_id, responseId)` en la persistencia.
- [ ] 2.5 `php -l` de los ficheros tocados + suite del orquestador sin nuevas roturas (los 5 fallos de Pint y los tests `*_provider_failure_*` son preexistentes: no añadir más).

## 3. Verificación

- [ ] 3.1 Rebuild + reinicio del orquestador, limpieza puntual de huérfanos previos (pkill por firma); observar ≥10 min: 0 avisos `re-adjuntando` y 1 par lector+escritor vivo por agente en ejecución (cuenta estable).
- [ ] 3.2 E2E chat: mensaje a agente Pi (7) y a agente Claude (6) → respuestas normales, mensaje final único (sin pares duplicados en el historial).
- [ ] 3.3 E2E config: guardar la config del agente Pi → reinicio y vuelta a `idle`; la cuenta de procesos no crece (regresión de `fix-pi-applyconfig-hang`).
- [ ] 3.4 E2E recuperación: pulsar «Reiniciar» en un agente → un único re-adjuntado (1 par vivo tras la recuperación) y el agente responde.

## Workflow follow-up

- Validar el cambio con `openspec validate --strict`
- Archive the change after verification is complete
- Commit y despliegue del fix (Conventional Commits + trailer de coautoría)

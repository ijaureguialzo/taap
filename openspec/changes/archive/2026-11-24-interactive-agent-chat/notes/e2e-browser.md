# e2e browser — chat en vivo por runtime (interactive-agent-chat 6.1)

Transcripción de la verificación con `agent-browser` contra el deploy Docker
(`taap.test` / `ws.taap.test`, Traefik + Reverb vía websocket). Usuario
`e2e@taap.test` (admin). Fecha: 2026-11-24 ~02:00–02:40 CET.

Checklist por paso: arranque (badge), mensaje con razonamiento +
herramientas + aprobación, streaming local, tarjeta de aprobación,
secciones del final (Razonamiento/Actividad), estado en reposo.

## Pi (agente 7, `taap-agent-7-pi`, qwen3.5:0.8b vía Model Proxy)

1. Detalle + chat: badge «en reposo», setup sin errores en consola.
2. Envío (Enter): mensaje optimista, badge «trabajando» en vivo (sin
   round-trip), skeleton de streaming bajo la burbuja del agente.
3. Aprobación: tarjeta aparece en vivo («Ejecutar comando: pwd»); el badge
   pasa a «esperando aprobación»; se aprueba → burbuja «Tú · ✔ Aprobado»,
   tarjeta se limpia en vivo, badge «trabajando». (El modelo pidió la
   aprobación 2 veces; el segundo ciclo igual.)
4. Final: badge «en reposo»; burbuja `Pi` asentada. Primeras vueltas con
   `run_id` nulo (bug de correlación — ver abajo); tras el fix, la vuelta
   `01:57` asienta con **sección `Actividad (3)`**
   (`bash · echo "taap-final"` / `bash · ls: cannot access…`).
5. Stop desde el chat: badge «detenido», tarjeta de aprobación colgada se
   limpia (se resuelve como `superseded`).
6. Reinicio vía botón: vuelve a «en reposo» y acepta mensajes.

Anomalías encontradas y resueltas durante esta e2e (commit `b130a8a`):

- `window.TaapAgents` undefined: `marked.marked` no existe en el build ESM
  de marked v18 (el export nombrado YA es la instancia) → el módulo moría
  antes de exponer el helper. Fix en `resources/js/app.js`.
- `r.off is not a function`: el wrapper de Echo no expone `off` → se usa
  `unbind` del canal pusher.
- `Cannot read … (reading 'unbind')`: `Pusher.channel()` devuelve `null`
  hasta que el canal existe → `subscribe()` para crearlo.
- JSON Blade escapado (`&quot;`) rompia el script inline del chat → `{!! !!}`.
- **Correlación de run**: el re-adjuntado del IPC (detach/attach o re-attach
  por lector stale) dejaba `run_id=null` en los eventos posteriores
  (incluido `message.final`) → el mensaje no mostraba Actividad. Fix:
  `pendingRunIds` en `StdioRpcAdapter` (sobrevive a detach/attach y al
  stale re-attach).
- Tarjeta de aprobación fantasma: las `approval.request` de turnos muertos
  (stop/abort) no se resolvían y la web las remontaba en cada carga →
  `resolvePendingApprovals()` en `run.end` y en `stop()`; `message.final`
  limpia la tarjeta incondicionalmente; un final REEMITIDO ya no se
  re-difunde (dedup en `EventSink`).

Nota de modelo: el modelo del agente 7 (qwen3.5:0.8b) repite rutas de
host inexistentes en el contenedor (habito del historial de la sesión Pi,
que persiste en el volumen). No es un defecto de la UI.

## Claude (agente 6, `taap-agent-6-claudia`)

1. Envío «Ejecuta date…»: badge en vivo, skeleton local.
2. Aprobación `date`: tarjeta en vivo → se resuelve → Claude reanuda.
3. Final: `Claudia · 02:36 La hora actual es…` + **`Actividad (2)`**
   (`Bash · date` / `Bash · Tue Nov 24…`); badge «en reposo»; tarjeta
   limpiada. Razonamiento: el modelo de este agente no emitió bloques de
   thinking en la vuelta (la sección solo aparece si `meta.thinking` no es
   vacío — correcto).

## Hermes (agente 4, `taap-agent-4-hermes`, Qwen3 vía Model Proxy)

1. Envío: working → `terminal` (date) sin aprobación (config del agente) →
   final en 11 s, **run_id consistente en los 6 eventos del run**.
2. Final en web: `Hermes · 02:36` + **sección `Razonamiento 0.3 KB`**
   (el thinking llega como `delta.reasoning_content` del Model Proxy) +
   contenido + Actividad; badge «en reposo».

## Codex y OpenCode

Sin agente en la base de datos local; cubiertos por sus suites de
integración con Docker (flujo completo con aprobación/denegación y
reanudación: `CodexRuntimeIntegrationTest` 3/3 — incluye razonamiento — y
`OpenCodeRuntimeIntegrationTest`, 14/14 en la última corrida estable; 1
fallo de entorno reproducible sin Docker proveedor, preexistente — ver
`git stash` baseline).

## Regresión global (6.2)

- Unit: 89/89. Feature: 136/138 con 12 skips y 2 fallos de TIEMPO
  preexistentes (sensibles a carga; pasan en corrida aislada — verificado
  contra baseline con `git stash`). Sidecar Claude: `node --test` 12/12.
- App iOS: no hay dispositivo en el entorno; el contrato API es aditivo
  (los tipos nuevos se ignoran en clientes v1.0) y el endpoint de chat no
  cambia de forma — se deja sin verificación en dispositivo.

## Consola

Sin errores JS en ninguna página tras los fixes (antes: 3 tipos distintos,
todos resueltos).

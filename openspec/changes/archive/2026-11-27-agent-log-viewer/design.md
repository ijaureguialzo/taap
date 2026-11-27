# Design: Visor de logs del agente

## Context
Ver proposal.md — Why. Estado actual: `DockerClient::logs(string $name, int $tail = 100): string` ya existe (`docker logs --tail N <name>`) pero no tiene superficie en la UI/API. El bridge/streaming corre a través de `$dir/out` (logs -f), no interfaz de consulta.

## Goals / Non-Goals
**Goals:**
- Tail de logs del contenedor en la ficha del agente (web + iOS), read-only y con selector de tamaño.

**Non-Goals:**
- Streaming en vivo; retención de logs; parsing estructurado de logs; streams de admin global.

## Decisions

1. **Reutilizar `DockerClient::logs()`** — sin nuevas rutas de stream: `docker logs --tail N <nombre>`, snapshot en cada consulta. Rationale: la interfaz es idempotente y no necesita infraestructura de streaming para una vista de depuración. Alternativas: streaming en vivo vía WebSocket (rechazado en esta fase — añade complejidad de conexión para un tail), streaming vía Server-Sent Events (rechazado — la app iOS no puede consumirlo limpiamente).
2. **API v1** — `GET /api/v1/agents/{id}/logs?tail=100|200|500` (default 200), accesible owner/admin; respuesta: `{ tail: [{ line: string, timestamp?: string }], status: 'ok'|'no-container'|'unavailable' }`; cache: deshabilitado (todo es best-effort).
3. **Web** — sección «Logs» en la ficha del agente (`Agents\Detail`): nuevo tab/sección con `<pre>` auto-scroll, selector de tamaño (50/200/500), botón «copiar», líneas atenuadas; escape de HTML (texto plano — `{{ }}` sobre una lista de líneas, nunca Raw).
4. **iOS** — `LogsSheet` modal en `AgentDetailView` (mismo endpoint): `ScrollView` con `Text` monospaced, selección por tamaños, copy-to-clipboard.
5. **Sin PII/secrets** — el log es del contenedor; se muestra tal cual; no se ejecuta ningún código del log; el usuario solo lo lee (como en la telemetría del agente).
6. **Edge cases** — contenedor no existente/muerto: la API responde `no-container` (el UI lo muestra como aviso); el timeout en `docker logs` (5s) con `allowFail: true` + aviso.
7. **Consistencia** — el tail se re-consulta cada vez que se abre la sección (no cacheo) — simple y correcto; el refresh es manual.

## Risks / Trade-offs
- [Log grande] → 200 líneas máx en defaults; el usuario puede bajar al selector de 50.
- [Informe de operación mientras el contenedor está corriendo] → el tail es un snapshot (puede diferir del presente); el selector/refresco es manual — documentado.
- [Container name non-resolvable] → respuesta `unavailable`; no crashea.

## Migration Plan
- Sin migraciones (consulta). Rollout: nueva sección + API; rollback: borrar ambas.

## Open Questions
Ninguna diferible.

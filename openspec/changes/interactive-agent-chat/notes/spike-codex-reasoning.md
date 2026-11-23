# Spike: superficie de razonamiento de Codex app-server 0.171

Fecha: 2026-11-23. Binary: `codex-app-server` **rust-v0.171.0** (el mismo de
`taap/agent-codex:local`). Modelo: `orcarouter/Qwen3.8-27B-Uncensored-MLX`
(MLX local, emite razonamiento en el formato estándar de la API
`responses` de OpenAI: `response.reasoning_summary_text.delta`, verificado
con `curl` directo antes del spike).

**Pregunta del cambio (2.1):** ¿el app-server 0.171 expone el razonamiento del
modelo, y en qué shape? **Respuesta: SÍ** — como items `type: "reasoning"`
con notificaciones de delta de resumen (`item/reasoning/summaryTextDelta`).

## Cómo se corrió

- `docker run` con la imagen `taap/agent-codex:local` real (mismo
  entrypoint/FIFO que producción), volúmenes `config`/`data`/`workspace`
  efímeros, conectado a `taap-internal` para alcanzar el Model Proxy.
- Config del agente spike (id 245, modelo 3): el `codex-args.env`
  equivalente al de `renderCodex`, más `-c web_search=disabled`
  (ver «Trampas»).
- Secuencia JSON-RPC: `initialize` → `initialized` → `thread/resume` →
  `turn/start` con un prompt que fuerza razonamiento + comandos shell.
- Transcript completo capturado de `docker logs` (el stdout del app-server);
  fixture recortado (deltas de razonamiento muestreados):
  `../fixtures/spike-codex-reasoning.jsonl`.

## Shape real observado

### Items de razonamiento (`item/*`)

Cada «paso» del modelo genera un item `reasoning` **nuevo** (5 en el turno:
uno antes de cada acción/mensaje). Ids del tipo `rs_<hash>_0`.

| Notificación | Params |
|---|---|
| `item/started` | `item: {type: "reasoning", id, summary: [], content: [], startedAtMs, completedAtMs: null}` |
| `item/reasoning/summaryPartAdded` | `{threadId, turnId, itemId, summaryIndex}` (1 part en el modelo probado; `summaryIndex` 0..n) |
| `item/reasoning/summaryTextDelta` | `{threadId, turnId, itemId, delta, summaryIndex}` — **streaming del razonamiento** |
| `item/completed` | `item: {type: "reasoning", id, summary: ["<texto completo>"], content: ["<texto completo>"], startedAtMs, completedAtMs}` |

Detalle del `item/completed` (real, truncado):

```json
{"type":"reasoning","id":"rs_972fa47cdf626cb19210481b5c1342bd_0",
 "summary":["The user's requests are:\n1. List the files ... First, let's run `ls -la` in /workspace.\n"],
 "content":["The user's requests are:\n1. List the files ... First, let's run `ls -la` in /workspace.\n"],
 "startedAtMs":1795471972485,"completedAtMs":1795471972497}
```

- `summary` y `content` son **arrays de strings** (uno por parte); el app-server
  aplana los `{type:"summary_text", text}` de la API `responses` a string plano.
- En el modelo probado `summary` == `content` (Qwen streamea el razonamiento
  como resumen). En modelos tipo o-series, `content` contendría
  `ReasoningText`/`EncryptedContent` (el request de codex pide
  `include: ["reasoning.encrypted_content"]`): `encrypted_content` no es
  legible → para el final, usar `summary` si hay texto, si no `content`.

### El resto del turno (ya mapeado en el adapter)

`turn/started` → (`item/started` reasoning + deltas + `item/completed`)* →
`item/started`/`item/agentMessage/delta`/`item/completed` (texto) →
`item/started`/`item/completed` `commandExecution` (tools) → `turn/completed`.
El rollout persistido (CODEX_HOME) confirma: 11 items `reasoning`, 10
`function_call`/`function_call_output`, 9 `message` — el razonamiento es una
parte de primera clase del transcript, nunca fundido con el texto.

## Trampas encontradas (importan para `renderCodex`)

1. **`web_search` forzado**: codex 0.171 incluye SIEMPRE la tool
   `web_search` en el request `POST /v1/responses` (verificado con un
   dump del body real: `tools: [function, function, function, function,
   namespace, function, function, function, web_search]` +
   `reasoning: {"summary": "auto"}` + `include: ["reasoning.encrypted_content"]`).
   Upstreams sin soporte (orcarouter: 400 «only function and custom tools
   are supported, not 'web_search'») **caen todos los turnos**, no solo la
   búsqueda.
   - Fix: `-c web_search=disabled` (clave **top-level**; los valores
     admitidos por el binary: `live`, `indexed`, `cached`, `disabled`).
   - `model_providers.<n>.supports_web_search` **NO funciona** en 0.171
     («session-flags: ... is ignored»): es un flag de sesión, no de config.
   - **Acción derivada:** `renderCodex` debe añadir `web_search=disabled`
     por defecto (los agentes TAAP no dependen de web search; un admin
     podría reactivarla en `config['web_search']`).
2. **`Host` del upstream**: orcarouter valida el header `Host`; el Model
   Proxy ya lo resuelve (`UpstreamIdentity::hostFor`: `host.docker.internal`
   → `127.0.0.1`). Ir directo al upstream desde el contenedor (bypass del
   proxy) falla con 403 — el spike pasó por el proxy como en producción.
3. **bwrap en Docker Desktop**: el sandbox `workspace-write` de codex falla
   dentro del contenedor en la VM de Docker Desktop de este Mac («bwrap:
   No permissi...», sin unprivileged user namespaces): el modelo se queda
   reintentando comandos. Ambiente local, no un hallazgo del contrato;
   en producción (Docker nativo) los agentes con sandbox funcionan.

## Mapeo para `CodexAdapter` (tarea 2.4)

- `item/started` con `item.type === 'reasoning'` → `thinkingDelta($runId, '')`
  no aporta; el flujo llega por los deltas.
- `item/reasoning/summaryTextDelta` → `thinkingDelta($runId, params.delta)`
  (nuevo case).
- `item/completed` con `item.type === 'reasoning'` → **nada por sí mismo**:
  el `message.final` del turno debe llevar el razonamiento completo. El
  adapter acumula `summary` (y, vacía, `content`) de todos los items
  reasoning del turno en la sesión (`$session['thinking_parts']`, unido con
  `\n`) y lo emite en el `messageFinal` del `item/completed` de
  `agentMessage` (hoy el punto donde se construye el final) — con
  `thinking` = '' si el turno no tuvo items reasoning.
- El dedupe/`message.final` actual (por `item/completed` agentMessage) se
  mantiene; solo gana el campo `thinking`.

# Spike: Claude Agent SDK + Model Proxy (tareas 1.1 y 1.2)

Ejecutado contra el par fijado, con gateway local falso (Anthropic) y el
orquestador TAAP corriendo. Evidencia reproducible:

- Gateway local: `/tmp/spike-claude/gateway.mjs` (SSE Anthropic + `GET
  /v1/models` + `HEAD /api/hello`; registra headers/body en `requests.log`).
- SDK real contra gateway local: `/tmp/spike-claude/spike.mjs` (mensajes
  crudos en `spike-out.txt`, peticiones HTTP en `requests.log`).
- Proxy real desde red `taap-internal`: `/tmp/spike-claude/proxy-test.mjs`
  y `proxy-cli-test.mjs` (agent/`spike-claude` + provider `spike-anthropic`
  creados temporalmente en la BD; eliminados al terminar).

## 1.1 Versiones fijadas y mensajes crudos del SDK

**Versiones** (las que usa la imagen `agents/claude`):

- `@anthropic-ai/claude-agent-sdk@0.3.329` (SDK TS: `query()`).
- `@anthropic-ai/claude-code@2.1.329` (CLI que el SDK spawnea como hijo).
  En Docker el SDK resuelve el binario del paquete de plataforma
  (`@anthropic-ai/claude-code` trae el binario nativo por arquitectura;
  basta con `npm install` ambos y el SDK lo encuentra — verificado: el CLI
  spawneado es `2.1.329`, visible en `user-agent: claude-cli/2.1.329
  (external, sdk-ts, agent-sdk/0.3.329)`).

**Mensajes crudos del stream de `query()`** (captura `spike-out.txt`):

| `type`     | Shape relevante                                                                                       |
| ---------- | ----------------------------------------------------------------------------------------------------- |
| `system`   | `subtype: init` con `session_id`, `tools`, `model`, `permissionMode`; hay también `hook_started`, `hook_response`, `api_retry`, `commands_changed`, `session_state_changed` (nuevos en 0.3.x). |
| `assistant`| `message: { role, content: [ {type:'text',text} \| {type:'tool_use',id,name,input} \| {type:'thinking',...} ] }`, `session_id`. |
| `user`     | Devuelve los `tool_result`: `message.content[]` con `{ tool_use_id, type:'tool_result', content, is_error }`; el `tool_use_id` va **dentro del bloque** (no en el top-level del mensaje); top-level añade `tool_use_result` y `tool_result_meta[]`. |
| `result`   | `{ session_id, is_error, subtype, result, num_turns, duration_ms, total_cost_usd, usage, ... }` — el `session_id` del result es el que se pasa como `resume` en el siguiente query. |

**`resume`**: verificado con 4 queries encadenadas (spike escenario 2-4):
cada `query({ resume: <session_id anterior> })` arranca el CLI con la
conversación reanudada (el contexto llega en el `messages[]` de la petición
HTTP; la respuesta trae el mismo `session_id`). El SDK/CLI mantiene la
transcripción en disco: con `CLAUDE_CONFIG_DIR` (si no, `$HOME/.claude`) en
`projects/<cwd-escapado>/<session-id>.jsonl`. **En el contenedor se fija
`CLAUDE_CONFIG_DIR=/data/claude`** (volumen persistente) para que el
`session_id` sobreviviera a un reinicio aunque el sidecar no lo guardara —
y el sidecar además lo persiste en su propio estado (`session-state.json`).

**`canUseTool`** (firma y retorno, verificado):

```ts
canUseTool(toolName: string, input: object,
  { signal: AbortSignal, suggestions?: PermissionUpdate[] }): Promise<
  { behavior:'allow', updatedInput?: object, decisionClassification? }
| { behavior:'deny', message: string, decisionClassification? }>
```

- Se invoca para cada tool cuya política exija confirmación. Con settings
  gestionadas (`options.settings.permissions.ask = [...]`) el CLI **fuerza**
  el prompt para las tools listadas, incluso las que su heurística
  consideraría seguras (verificado: `Bash` con `ask` → prompt; `Read` con
  `allow` → sin prompt).
- La Promise pendiente se resuelve con el retorno: `allow` (el CLI ejecuta y
  el stream sigue) o `deny` + `message` (el modelo recibe un `tool_result`
  con `is_error=true` y continúa — el agente no muere).
- **Limitación clave**: las `updatedPermissions`/decisiones de la sesión NO
  desbancan una regla `ask` gestionada dentro del mismo proceso (el CLI
  vuelve a preguntar para la misma tool en el siguiente turno del mismo
  query). Por eso el sidecar mantiene su set `always_allowed` persistente y
  recalcula `ask`/`allow` por turno (`effectivePermissions` en
  `src/session.mjs`) y auto-aprobá en el turno en curso lo ya permitido
  siempre (spike escenario 4: tras «always», la 2ª invocación no llama a
  `canUseTool`).

**`env` de sesión**: `CLAUDE_CONFIG_DIR` es la variable exacta (confirmada
en el código del SDK y probada: escenario 5 del spike — con un directorio
nuevo no hay sesiones; con el anterior, `resume` funciona). El sidecar la
fija a `/data/claude`.

## 1.2 Model Proxy visto desde `taap-internal` (y desde el CLI real)

Setup: gateway falso en el host (`:8787`), provider temporal
`spike-anthropic` (type `anthropic`, base_url `http://host.docker.internal:8787/v1`),
agente temporal `spike-claude` (id 3, con token). Llamadas reales desde un
contenedor en `taap-internal` (`backend-node`, `proxy-test.mjs`) y con el
SDK/CLI real fijado (`proxy-cli-test.mjs`).

**Comportamiento actual de la ruta `/v1/messages`** (lo que el gateway
recibió de verdad, `proxy-reqs.log`):

1. Autenticación: el proxy solo lee `Authorization: Bearer <token>`; el CLI
   envía `x-api-key` → **401**. El CLI real lo confirmó: `system/init` OK y
   luego `api_retry` 1..10 con `authentication_failed` (backoff exponencial;
   la query termina en error tras ~2 min).
2. El proxy reescribe **`stream` a `false`** siempre (la opción `stream:true`
   del cliente no llega al upstream) y, si el cliente pidió stream, sintetiza
   SSE **en formato OpenAI** (`chat.completion.chunk`) leyendo `choices[0]`
   de la respuesta JSON: con un upstream Anthropic ese JSON no tiene
   `choices` → **delta vacía, `model:"unknown"`**. El CLI espera SSE
   Anthropic (`message_start`, `content_block_*`, `message_delta`,
   `message_stop`) → no consumible.
3. Inyección de campos: `reasoning_effort:"high"` (campo OpenAI, ajeno a la
   API Anthropic) y `temperature:0` (válido en Anthropic, pero el CLI ya
   manda la suya; inyectar solo si ausente). Además `$request->all()` mete la
   query string en el body: aparece `beta:true` como campo top-level
   (inofensivo para OpenRouter, pero la API real de Anthropic es estricta).
4. Headers: el proxy envía `x-api-key` (clave del provider) +
   `anthropic-version: 2023-06-01` para type `anthropic` ✓; **NO reenvía el
   `anthropic-beta` del cliente** → con la API real, campos betas del body
   (p. ej. `thinking`) darían 400 sin el header beta.
5. El CLI hace también `GET /v1/models` (validación de modelo al arrancar) y
   `HEAD /api/hello` (health): el proxy los contesta **404** (solo existe
   `POST v1/messages`). El CLI **sobrevive** a ambos 404 (arranca y va a
   `/v1/messages`), pero conviene servirlos (task 3).

## Lista de gaps (alimenta el grupo 3 del cambio)

| # | Gap | Impacto | Fix (task) |
| - | --- | ------- | ---------- |
| 1 | El proxy autentica solo con `Authorization: Bearer`; el CLI usa `x-api-key` | 401 → la query muere tras 10 retries | `bearer()` acepta también `x-api-key` (3.2) |
| 2 | `stream:true` + upstream Anthropic → SSE sintético en formato OpenAI, delta vacía | el CLI no consume el stream | `asAnthropicSse()`: sintetizar `message_start`/`content_block_*`/`message_delta`/`message_stop` a partir del JSON no-stream (3.1) |
| 3 | Inyección de `reasoning_effort` (y la query string `beta`) en todo body | campos ajenos a la API Anthropic | inyección condicional por type de provider + body desde JSON crudo, sin query (3.2) |
| 4 | `anthropic-beta` del cliente no se reenvía | campos betas (thinking) → 400 en la API real | reenviar `anthropic-beta` para type `anthropic` (3.2) |
| 5 | No existen `GET /v1/models` ni `/api/hello` en la puerta del proxy | 404 (no fatales para el CLI, pero el CLI los consulta) | nuevas rutas: `/v1/models` (sintetiza desde el modelo del agente o passthrough) y `HEAD/GET /api/hello` → 200 (3.1/3.2) |

**Re-verificación tras el grupo 3**: re-ejecutar `proxy-test.mjs` y
`proxy-cli-test.mjs` (mismo agente/provider temporales): la llamada con
`stream:true` debe devolver un stream Anthropic válido y el CLI real debe
completar el turno contra el proxy sin `api_retry`.

## Cierre de los Open Questions del design

1. **SDK/CLI + env de sesión + retorno de `canUseTool`** → resuelto arriba
   (0.3.329 / 2.1.329, `CLAUDE_CONFIG_DIR`, `behavior: allow|deny` +
   `updatedPermissions` insuficiente → set `always_allowed` del sidecar).
2. **Semántica del provider `other` en el filtro** → se valida en la task 4
   contra el registro: `other` se trata como OpenAI (el filtro del spec solo
   deja pasar `anthropic` a los runtimes que consumen solo Anthropic; un
   `other` con endpoint Anthropic-compat se declara `anthropic` en el
   proveedor — no se adivina por URL).
3. **Listas por defecto de permisos** → `ask: Bash, Edit, Write, WebFetch,
   mcp__*`; `allow: Read, Grep, Glob, Skill, TodoWrite, Task`
   (implementado en `DEFAULTS` de `src/config.mjs`; ajustable vía la config
   renderizada — UX post-v1.1).

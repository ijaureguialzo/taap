# Spike 2.5 — Hermes: razonamiento vía Model Proxy (interactive-agent-chat)

**Fecha**: 2026-07-24 (hora local del host) · **Agente**: `taap-agent-4-hermes`
(`taap/agent-hermes:local` = `nousresearch/hermes-agent:stable` + API_SERVER_HOST) ·
**Modelo**: `orcarouter/Qwen3.8-27B-Uncensored-MLX` (Qwen3: razona en modo thinking).

## Pregunta

¿El gateway Hermes expone el razonamiento del modelo en su SSE
(`/v1/chat/completions`, :8642) para poder mapearlo a `thinking.delta` y al
`thinking` del `message.final`?

## Cadena verificada

```
HermesAdapter (orquestador)
  -> POST http://taap-agent-4-hermes:8642/v1/chat/completions (stream:true,
     Bearer API_SERVER_KEY, X-Hermes-Session-Key)
  -> gateway hermes (proxy «credential-attaching forwarder»: el cuerpo del
     request/response NO se media ni reescribe; solo añade [DONE] si el
     upstream no lo envió — hermes_cli/proxy/server.py)
  -> agente interno de hermes (config.yaml:
     model.base_url = http://taap-orchestrator:8000/api/proxy/agents/4/v1,
     model.default = orcarouter/Qwen3.8-27B-Uncensored-MLX)
  -> Model Proxy (ModelProxyController: upstream en NO-stream; si el cliente
     pidió stream, SINTETIZA el SSE: delta {reasoning_content} + delta
     {content} + delta {} con finish)
  -> orcarouter :8000 -> Qwen3.8 (respuesta JSON con content +
     reasoning_content)
```

## Hallazgos (SSE real capturado)

1. **SÍ hay razonamiento**: el gateway emite `data:` frames con
   `choices[0].delta.reasoning_content` (varios por turno, ANTES del content)
   — la convención DeepSeek/Qwen, relayada tal cual por la cadena.
2. **Hay además eventos propios del gateway**: frames con
   `event: hermes.tool.progress` + `data: {"tool", "label", "toolCallId",
   "status": running|completed}` — el loop de tools del agente interno, en
   vivo.
3. **Comportamiento del Model Proxy** (ModelProxyController::mergeReasoning):
   si el modelo SOLO pensó (content vacía, sin tool_calls), el proxy FUSIONA
   `reasoning_content` en `content` → el SSE lleva el MISMO texto por ambas
   claves (delta reasoning_content + delta content).
4. El prompt `What is 17 times 23? Think step by step` produjo: 3 chunks de
   `reasoning_content` (el modelo verificó con execute_code y luego terminal),
   1 chunk de `content` con la respuesta final, `finish_reason: stop` +
   `usage` (34742 tokens: el system prompt de hermes pesa 34k).

## Decisión implementada (HermesAdapter)

- `delta.reasoning_content` → `thinking.delta` (flujo separado; antes solo se
  acumulaba como última línea de defensa cuando content estaba vacío).
- `delta.content` → `message.delta` (inalterado).
- `hermes.tool.progress` → `tool.start` (running) / `tool.result`
  (completed/otros) — el loop de tools ahora se ve en el chat.
- Final: `messageFinal(runId, trim(content), trim(thinking))`. Dado el punto 3
  (el proxy funde reasoning→content cuando el modelo solo pensó), si
  `content === thinking` el final deja `thinking = ''` (el texto ya lo muestra;
  no se duplica en la sección Reasoning).
- Limitación registrada (spec 2.5): si el MODELO detrás no emite
  `reasoning_content` (no es un modelo de razonamiento, o el router no lo
  expone), el final lleva `thinking = ''` — el runtime no puede inventarlo.

## Tests

- `tests/Unit/HermesAdapterTest.php`: el body SSE es el shape real del spike
  (incl. keepalive, tool.progress, reasoning_content); verifica los 3 shapes
  + la deduplicación merge + el caso sin razonamiento.

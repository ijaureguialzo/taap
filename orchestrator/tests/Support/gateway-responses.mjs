// Gateway sintético del wire OpenAI "Responses" para la integración del
// runtime Codex (tests/Feature/Agents/CodexRuntimeIntegrationTest).
//
// El proxy del orquestador reenvía aquí los /v1/responses de codex (passthrough
// transparente: este gateway ES el upstream del provider). Determinista:
//   - user con "ping"        -> texto + exec_command "echo ping" (require_escalated)
//   - user con "denegado"    -> texto + exec_command "echo denegado" (require_escalated)
//   - user con "recordatorio"-> solo texto (turno sin tools)
//   - input con function_call_output -> confirmacion según el cmd ejecutado
// Registra cada request en JSONL (nItems/auth/model) para las aserciones de
// resume (el request post-reinicio debe llevar el historial completo).
import http from 'node:http';
import fs from 'node:fs';

const port = Number(process.argv[2] || 0);
const logFile = process.argv[3] || null;
let n = 0;

function sse(res, event, data) {
  res.write(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`);
}

function messageBlock(id, text) {
  return {
    id,
    type: 'message',
    role: 'assistant',
    content: [{ type: 'output_text', text, status: 'completed' }],
    status: 'completed',
  };
}

function functionCallBlock(id, callId, name, args) {
  return {
    id,
    type: 'function_call',
    name,
    arguments: args,
    call_id: callId,
    status: 'completed',
  };
}

/** Texto de mensaje para un user text (determinista por contenido). */
function textFor(userText) {
  if (userText.includes('tardio')) return 'Voy a ejecutar el tardio.';
  if (userText.includes('ping')) return 'Voy a ejecutar el ping.';
  if (userText.includes('denegado')) return 'Voy a ejecutar el denegado.';
  if (userText.includes('recordatorio')) return 'Aquí tienes el recordatorio: la integracion Codex funciona.';
  return 'ok';
}

/** cmd del exec_command para un user text (null = sin tool). */
function cmdFor(userText) {
  if (userText.includes('tardio')) return 'echo tardio';
  if (userText.includes('ping')) return 'echo ping';
  if (userText.includes('denegado')) return 'echo denegado';
  return null;
}

/** Confirmacion tras un tool result (según el cmd que pidio el gateway). */
function confirmFor(toolResult) {
  const out = JSON.stringify(toolResult.output ?? '');
  if (out.includes('tardio')) return 'Tardio recibido.';
  if (out.includes('ping')) return 'Ping recibido.';
  if (out.includes('denegado')) return 'Denegado, entendido.';
  if (out.toLowerCase().includes('cancel') || out.toLowerCase().includes('reject') || out.includes('approval')) {
    return 'La ejecucion no se aprobo; sigo.';
  }
  return 'Hecho.';
}

// Log en memoria servido por GET /__requests: el JSONL va por virtiofs
// (contenedor QEMU) y las lecturas desde fuera pueden ver pagina cache
// inconsistente. La memoria es la fuente de verdad para los tests.
const reqs = [];
const logEntry = (o) => {
  reqs.push(o);
  if (logFile) {
    try { fs.appendFileSync(logFile, JSON.stringify(o) + '\n'); } catch {}
  }
};

const server = http.createServer((req, res) => {
  const pathname = new URL(req.url, 'http://x').pathname;

  if (req.method === 'GET' && pathname === '/__requests') {
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end(JSON.stringify(reqs));
    return;
  }
  // health (el test espera aqui)
  if (req.url === '/api/hello') {
    res.writeHead(200, { 'content-type': 'application/json' });
    return res.end(JSON.stringify({ status: 'ok' }));
  }

  let body = '';
  req.on('data', (c) => (body += c));
  req.on('end', () => {
    let parsed = {};
    try {
      parsed = JSON.parse(body || '{}');
    } catch {
      parsed = {};
    }

    const input = Array.isArray(parsed.input) ? parsed.input : [];
    const userText = (input.filter((i) => i?.type === 'message' && i?.role === 'user')
      .flatMap((i) => (Array.isArray(i.content) ? i.content : []))
      .map((c) => c?.text ?? '')
      .filter(Boolean).pop()) ?? '';
    const toolResult = input.filter((i) => i?.type === 'function_call_output').pop() ?? null;
    const lastFc = input.filter((i) => i?.type === 'function_call').pop() ?? null;

    logEntry({
      ts: new Date().toISOString(),
      url: req.url,
      model: parsed.model ?? null,
      auth: req.headers.authorization ?? null,
      stream: !!parsed.stream,
      nItems: input.length,
      userText: userText.slice(0, 80),
      toolResult: !!toolResult,
      bodyLen: body.length,
    });

    n += 1;
    const id = 'resp_' + n;
    const base = { id, object: 'response', model: parsed.model ?? 'gpt-codex', status: 'in_progress' };
    const usage = { input_tokens: 100 + input.length, output_tokens: 12, total_tokens: 112 + input.length };

    // Decidir el output de este request
    let output;
    if (toolResult) {
      output = [messageBlock('msg_' + n, confirmFor(toolResult))];
    } else {
      const cmd = cmdFor(userText);
      const msg = messageBlock('msg_' + n, textFor(userText));
      output = [msg];
      if (cmd) {
        output.push(functionCallBlock('fc_' + n, 'call_' + n, 'exec_command',
          JSON.stringify({ cmd, sandbox_permissions: 'require_escalated' })));
      }
    }

    // Rama no-stream (codex pide stream; esta rama es por robustez)
    if (!parsed.stream) {
      res.writeHead(200, { 'content-type': 'application/json' });
      return res.end(JSON.stringify({ ...base, status: 'completed', output, usage }));
    }

    res.writeHead(200, {
      'content-type': 'text/event-stream',
      'cache-control': 'no-cache',
      connection: 'keep-alive',
    });
    sse(res, 'response.created', { type: 'response.created', response: { ...base, output: [] } });
    sse(res, 'response.in_progress', { type: 'response.in_progress', response: { ...base, output: [] } });

    output.forEach((item, idx) => {
      if (item.type === 'message') {
        sse(res, 'response.output_item.added', { type: 'response.output_item.added', output_index: idx, item: { ...item, status: 'in_progress', content: [] } });
        const text = item.content[0]?.text ?? '';
        sse(res, 'response.output_text.delta', { type: 'response.output_text.delta', item_id: item.id, output_index: idx, content_index: 0, delta: text });
        sse(res, 'response.output_text.done', { type: 'response.output_text.done', item_id: item.id, output_index: idx, content_index: 0, text });
        sse(res, 'response.output_item.done', { type: 'response.output_item.done', output_index: idx, item });
      } else if (item.type === 'function_call') {
        sse(res, 'response.output_item.added', { type: 'response.output_item.added', output_index: idx, item: { ...item, arguments: '', status: 'in_progress' } });
        sse(res, 'response.function_call_arguments.delta', { type: 'response.function_call_arguments.delta', item_id: item.id, output_index: idx, delta: item.arguments });
        sse(res, 'response.function_call_arguments.done', { type: 'response.function_call_arguments.done', item_id: item.id, output_index: idx, arguments: item.arguments });
        sse(res, 'response.output_item.done', { type: 'response.output_item.done', output_index: idx, item });
      }
    });

    sse(res, 'response.completed', { type: 'response.completed', response: { ...base, status: 'completed', output, usage } });
    return res.end();
  });
});

if (port > 0) {
  server.listen(port, '0.0.0.0', () => console.log(`gateway responses (codex) en ${port}`));
}

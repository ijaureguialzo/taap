// Gateway sintético del wire OpenAI "chat completions" para la integración
// del runtime OpenCode (tests/Feature/Agents/OpenCodeRuntimeIntegrationTest).
// El proxy del orquestador reenvía aquí los /v1/chat/completions del provider
// (opencode habla OpenAI-compatible contra el proxy; el proxy es passthrough
// transparente para OpenAI). Determinista:
//  - user con "ping"       -> texto + tool bash "echo ping" (requiere permiso)
//  - user con "denegado"   -> tool bash "echo denegado"
//  - user con "tardio"     -> tool bash "echo tardio"
//  - user con "recordatorio"-> solo texto
//  - tool result presente   -> confirmacion
import http from 'node:http';
import fs from 'node:fs';

const port = Number(process.argv[2] || 8901);
const reqs = [];
const logFile = process.argv[3] || null;

function sse(res, event, data) {
  res.write(`data: ${JSON.stringify(data)}\n\n`);
  if (event === 'done') res.write('data: [DONE]\n\n'), res.end();
}

const userText = (b) => (b.messages || [])
  .filter((m) => m.role === 'user')
  .flatMap((m) => (typeof m.content === 'string' ? [m.content]
    : (m.content || []).map((c) => c.text ?? '')))
  .filter(Boolean).pop() ?? '';
const toolMsg = (b) => (b.messages || []).filter((m) => m.role === 'tool').pop() ?? null;

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  if (req.method === 'GET' && url.pathname === '/__requests') {
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end(JSON.stringify(reqs));
    return;
  }
  if (req.method === 'GET' && url.pathname === '/api/hello') {
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ ok: true }));
    return;
  }
  if (url.pathname === '/v1/messages') {
    // Anthropic: el runtime OpenCode con provider anthropic lo usa. Misma
    // semantica determinista que el chat (texto + tool_use si procede).
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      let parsed = {};
      try { parsed = JSON.parse(body); } catch {}
      const ut = (parsed.messages || []).filter((m) => m.role === 'user')
        .flatMap((m) => (typeof m.content === 'string' ? [m.content] : (m.content || []).map((c) => c.text ?? '')))
        .filter(Boolean).pop() ?? '';
      const entry = { n: reqs.length, auth: req.headers['x-api-key'] ?? req.headers.authorization ?? null, ut: ut.slice(0, 60), nMessages: (parsed.messages || []).length, stream: !!parsed.stream, model: parsed.model ?? null, wire: 'anthropic' };
      reqs.push(entry);
      try { if (logFile) fs.appendFileSync(logFile, JSON.stringify(entry) + '\n'); } catch {}
      const id = 'msg_' + entry.n;
      const text = ut.includes('ping') ? 'Voy a ejecutar el ping.' : ut.includes('tardio') ? 'Voy a ejecutar el tardio.' : 'Recordatorio anthropic ok.';
      if (!parsed.stream) {
        res.writeHead(200, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ id, type: 'message', role: 'assistant', model: parsed.model, content: [{ type: 'text', text }], stop_reason: 'end_turn' }));
        return;
      }
      res.writeHead(200, { 'content-type': 'text/event-stream', 'cache-control': 'no-cache' });
      // Forma EXACTA de los eventos Anthropic (el SDK la valida con Zod:
      // message_start exige message.usage completo, message_delta exige
      // delta.stop_reason + usage).
      const sse = (t, body) => res.write('event: ' + t + '\ndata: ' + JSON.stringify({ ...body, type: t }) + '\n\n');
      sse('message_start', { message: { id, type: 'message', role: 'assistant', content: [], model: parsed.model, stop_reason: null, stop_sequence: null, usage: { input_tokens: 10, output_tokens: 1 } } });
      sse('content_block_start', { index: 0, content_block: { type: 'text', text: '' } });
      for (const ch of text.split('')) {
        sse('content_block_delta', { index: 0, delta: { type: 'text_delta', text: ch } });
      }
      if (ut.includes('ping')) {
        sse('content_block_start', { index: 1, content_block: { type: 'tool_use', id: 'toolu_' + entry.n, name: 'Bash', input: {} } });
        sse('content_block_delta', { index: 1, delta: { type: 'input_json_delta', partial_json: '{\"command\":\"echo ping\"}' } });
        sse('content_block_stop', { index: 1 });
      }
      sse('content_block_stop', { index: 0 });
      sse('message_delta', { delta: { stop_reason: 'end_turn', type: 'stop_reason' }, usage: { output_tokens: text.length } });
      sse('message_stop', {});
      res.end();
    });
    return;
  }
  // El Model Proxy quita el /v1 al reenviar a upstream OpenAI (el base_url
  // real de OpenAI ya lo lleva); se acepta con y sin el prefijo.
  const chat = url.pathname === '/v1/chat/completions' || url.pathname === '/chat/completions';
  if (!chat) {
    res.writeHead(404, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ error: { message: 'ruta desconocida: ' + url.pathname } }));
    return;
  }
  let body = '';
  req.on('data', (c) => (body += c));
  req.on('end', () => {
    let parsed = {};
    try { parsed = JSON.parse(body); } catch {}
    const ut = userText(parsed);
    const tool = toolMsg(parsed);
    const entry = { n: reqs.length, auth: req.headers.authorization ?? null, ut: ut.slice(0, 60), nMessages: (parsed.messages || []).length, tool: !!tool, toolOut: tool ? String(tool.content).slice(0, 40) : null, stream: !!parsed.stream, model: parsed.model ?? null };
    reqs.push(entry);
    try { if (logFile) fs.appendFileSync(logFile, JSON.stringify(entry) + '\n'); } catch {}

    res.writeHead(200, {
      'content-type': parsed.stream ? 'text/event-stream' : 'application/json',
      'cache-control': 'no-cache',
    });
    if (!parsed.stream) {
      // El Model Proxy llama en modo no-stream y sintetiza el SSE: la
      // llamada a herramienta debe ir en message.tool_calls (el proxy la
      // copia al primer delta).
      const toolCall = { index: 0, id: 'call_' + entry.n, type: 'function', function: { name: 'bash', arguments: ut.includes('ping') ? '{"command":"echo ping"}' : ut.includes('denegado') ? '{"command":"echo denegado"}' : '{"command":"echo tardio"}' } };
      const out = tool ? String(tool.content).slice(0, 40) : null;
      const message = { role: 'assistant', content: tool ? (out.includes('ping') ? 'Ping confirmado.' : out.includes('denegado') ? 'Denegado, sigo.' : 'Hecho.') : (ut.includes('ping') ? 'Voy a ejecutar el ping.' : ut.includes('denegado') ? 'Voy a ejecutar el denegado.' : 'Voy a ejecutar el tardio.') };
      if (!tool && (ut.includes('ping') || ut.includes('denegado') || ut.includes('tardio'))) message.tool_calls = [toolCall];
      res.end(JSON.stringify({ id: 'c_' + entry.n, object: 'chat.completion', model: parsed.model, choices: [{ index: 0, message, finish_reason: message.tool_calls ? 'tool_calls' : 'stop' }], usage: { prompt_tokens: 10, completion_tokens: 8, total_tokens: 18 } }));
      return;
    }
    // SSE: texto + (tool call si procede)
    const chunks = [];
    const id = 'c_' + entry.n;
    const push = (o, usage = null) => sse(res, 'x', { id, object: 'chat.completion.chunk', choices: [o], ...(usage ? { usage } : {}) });
    let delta = { role: 'assistant', content: '' };
    if (tool) {
      const out = JSON.stringify(tool).slice(0, 120);
      if (out.includes('ping')) delta.content = 'Ping confirmado.';
      else if (out.includes('denegado')) delta.content = 'Denegado, sigo.';
      else delta.content = 'Hecho.';
    } else {
      delta.content = ut.includes('tardio') ? 'Voy a ejecutar el tardio.'
        : ut.includes('ping') ? 'Voy a ejecutar el ping.'
        : ut.includes('denegado') ? 'Voy a ejecutar el denegado.'
        : 'Aquí tienes el recordatorio: la integracion OpenCode funciona.';
    }
    push({ index: 0, delta, finish_reason: null });
    if (ut.includes('ping')) {
      push({ index: 0, delta: { tool_calls: [{ index: 0, id: 'call_' + entry.n, type: 'function', function: { name: 'bash', arguments: '{"command":"echo ping"}' } }] }, finish_reason: null });
    } else if (ut.includes('denegado')) {
      push({ index: 0, delta: { tool_calls: [{ index: 0, id: 'call_' + entry.n, type: 'function', function: { name: 'bash', arguments: '{"command":"echo denegado"}' } }] }, finish_reason: null });
    } else if (ut.includes('tardio')) {
      push({ index: 0, delta: { tool_calls: [{ index: 0, id: 'call_' + entry.n, type: 'function', function: { name: 'bash', arguments: '{"command":"echo tardio"}' } }] }, finish_reason: null });
    } else {
      // chunk final: delta vacio (objeto) + usage (el SDK lo valida)
    push({ index: 0, delta: {}, finish_reason: 'stop' }, { prompt_tokens: 10, completion_tokens: delta.content.length, total_tokens: 10 + delta.content.length });
    }
    res.write('data: [DONE]\n\n');
    res.end();
  });
});

// el ultimo sse 'done' anterior es invalido (array): reescribir helper
server.on('listening', () => console.log(`gw opencode en ${port}`));
server.listen(port, '0.0.0.0', () => console.log(`gateway chat (opencode) en ${port}`));

// Gateway OpenAI (chat completions) para el spike OpenCode. Determinista:
//  - user con "ping"       -> texto + tool bash "echo ping" (requiere permiso)
//  - user con "denegado"   -> tool bash "echo denegado"
//  - user con "tardio"     -> tool bash "echo tardio"
//  - user con "recordatorio"-> solo texto
//  - tool result presente   -> confirmacion
import http from 'node:http';
import fs from 'node:fs';

const port = Number(process.argv[2] || 8901);
const reqs = [];

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
  if (url.pathname !== '/v1/chat/completions') {
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
    const entry = { n: reqs.length, auth: req.headers.authorization ?? null, ut: ut.slice(0, 60), tool: !!tool, toolOut: tool ? String(tool.content).slice(0, 40) : null, stream: !!parsed.stream, model: parsed.model ?? null };
    reqs.push(entry);
    try { fs.appendFileSync('/tmp/spike-opencode/gw-reqs.jsonl', JSON.stringify(entry) + '\n'); } catch {}

    res.writeHead(200, {
      'content-type': parsed.stream ? 'text/event-stream' : 'application/json',
      'cache-control': 'no-cache',
    });
    if (!parsed.stream) {
      res.end(JSON.stringify({ id: 'c_' + entry.n, object: 'chat.completion', model: parsed.model, choices: [{ index: 0, message: { role: 'assistant', content: 'ok' }, finish_reason: 'stop' }], usage: { prompt_tokens: 10, completion_tokens: 2, total_tokens: 12 } }));
      return;
    }
    // SSE: texto + (tool call si procede)
    const chunks = [];
    const id = 'c_' + entry.n;
    const push = (o) => sse(res, 'x', { id, object: 'chat.completion.chunk', choices: [o] });
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
      push({ index: 0, delta: {}, finish_reason: 'stop' });
    }
    res.write('data: [DONE]\n\n');
    res.end();
  });
});

// el ultimo sse 'done' anterior es invalido (array): reescribir helper
server.on('listening', () => console.log(`gw opencode en ${port}`));
server.listen(port, '127.0.0.1');

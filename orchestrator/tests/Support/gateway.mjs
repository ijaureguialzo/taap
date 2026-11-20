// Gateway local Anthropic-compatible para la suite de integracion
// (add-claude-code-runtime 7.x). Responde /v1/messages con un mensaje
// "canned" (SSE si stream: true) y registra cada request en un JSONL para
// poder asertar lo que el SDK envia (historial de sesion, headers, etc.).
//
// Uso: node gateway.mjs <puerto> <fichero-de-log>
//
// Comportamiento:
//  - El texto responde con "respuesta <N>" donde N = num. de messages en el
//    request: si la sesion se reanuda, N crece entre turnos (evidencia de
//    `resume`).
//  - Si el ultimo message de usuario contiene "bash", el asistente incluye
//    ademas un `tool_use` Bash (el SDK dispara `canUseTool` -> flujo de
//    aprobacion TAAP).
//  - El request con header `x-spike-error: 1` responde 500 (fallos de
//    proveedor para el test 7.2).

import http from 'node:http';
import fs from 'node:fs';

const port = Number(process.argv[2] || 0);
const logFile = process.argv[3] || null;

function sse(res, event, data) {
  res.write(`event: ${event}\n`);
  res.write(`data: ${JSON.stringify(data)}\n\n`);
}

const server = http.createServer((req, res) => {
  // Sin query string: el CLI llama a /v1/messages?beta=true (y similares);
  // el match debe hacerse sobre el pathname.
  const pathname = new URL(req.url, 'http://gateway').pathname;
  if (req.method === 'GET' && (pathname === '/api/hello' || pathname === '/up')) {
    res.writeHead(200, { 'content-type': 'application/json' });
    return res.end(JSON.stringify({ ok: true }));
  }

  if (req.method === 'GET' && pathname === '/v1/models') {
    res.writeHead(200, { 'content-type': 'application/json' });
    return res.end(JSON.stringify({
      data: [{ id: 'claude-sonnet-4-5', type: 'model', display_name: 'Claude Sonnet 4.5' }],
    }));
  }

  if (req.method === 'POST' && pathname === '/v1/messages') {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      let parsed = {};
      try {
        parsed = JSON.parse(body);
      } catch {
        parsed = { stream: false };
      }

      const messages = parsed.messages ?? [];
      const last = messages[messages.length - 1] ?? {};
      const lastText = typeof last.content === 'string'
        ? last.content
        : ((last.content ?? []).map((b) => b.text ?? '').join(''));

      if (logFile) {
        fs.appendFileSync(logFile, JSON.stringify({
          ts: new Date().toISOString(),
          model: parsed.model ?? null,
          stream: !!parsed.stream,
          nMessages: messages.length,
          lastUser: lastText,
          beta: req.headers['anthropic-beta'] ?? null,
          auth: req.headers['authorization'] ? 'bearer' : (req.headers['x-api-key'] ? 'x-api-key' : 'ninguna'),
          nMsgs: messages.length,
          roles: messages.map((m) => m.role),
          lastTail: lastText.slice(-80),
        }) + '\n');
      }

      if (req.headers['x-spike-error'] === '1') {
        res.writeHead(500, { 'content-type': 'application/json' });
        return res.end(JSON.stringify({ type: 'error', error: { type: 'api_error', message: 'fallo inducido' } }));
      }

      const text = `respuesta ${messages.length}`;
      const wantTool = /bash/i.test(lastText);
      const id = 'msg_' + Date.now().toString(36);

      if (parsed.stream) {
        res.writeHead(200, { 'content-type': 'text/event-stream', 'cache-control': 'no-cache', connection: 'keep-alive' });
        sse(res, 'message_start', {
          type: 'message_start',
          message: { id, type: 'message', role: 'assistant', model: parsed.model ?? 'claude-sonnet-4-5', content: [], stop_reason: null, stop_sequence: null, usage: { input_tokens: 25, output_tokens: 5 } },
        });
        sse(res, 'content_block_start', { type: 'content_block_start', index: 0, content_block: { type: 'text', text: '' } });
        sse(res, 'content_block_delta', { type: 'content_block_delta', index: 0, delta: { type: 'text_delta', text } });
        sse(res, 'content_block_stop', { type: 'content_block_stop', index: 0 });
        if (wantTool) {
          sse(res, 'content_block_start', { type: 'content_block_start', index: 1, content_block: { type: 'tool_use', id: 'toolu_' + Date.now().toString(36), name: 'Bash', input: {} } });
          sse(res, 'content_block_delta', { type: 'content_block_delta', index: 1, delta: { type: 'input_json_delta', partial_json: JSON.stringify({ command: 'echo ping', description: 'ping de prueba' }) } });
          sse(res, 'content_block_stop', { type: 'content_block_stop', index: 1 });
        }
        sse(res, 'message_delta', { type: 'message_delta', delta: { stop_reason: wantTool ? 'tool_use' : 'end_turn', stop_sequence: null }, usage: { output_tokens: 12 } });
        sse(res, 'message_stop', { type: 'message_stop' });
        return res.end();
      }

      const content = [{ type: 'text', text }];
      if (wantTool) {
        content.push({ type: 'tool_use', id: 'toolu_' + Date.now().toString(36), name: 'Bash', input: { command: 'echo ping', description: 'ping de prueba' } });
      }
      res.writeHead(200, { 'content-type': 'application/json' });
      return res.end(JSON.stringify({
        id, type: 'message', role: 'assistant', model: parsed.model ?? 'claude-sonnet-4-5',
        content,
        stop_reason: wantTool ? 'tool_use' : 'end_turn', stop_sequence: null,
        usage: { input_tokens: 25, output_tokens: 8 },
      }));
    });

    return;
  }

  res.writeHead(404, { 'content-type': 'application/json' });
  res.end(JSON.stringify({ type: 'error', error: { type: 'not_found', message: req.url } }));
});

if (port > 0) {
  // 0.0.0.0: el gateway corre en su propio contenedor de la red interna;
  // el loopback solo lo veria el propio contenedor.
  server.listen(port, '0.0.0.0', () => console.log(`gateway escuchando en 0.0.0.0:${port}`));
}
process.on('SIGTERM', () => process.exit(0));

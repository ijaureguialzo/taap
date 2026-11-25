// Gateway sintético del wire OpenAI "chat completions" para la integracion
// del runtime Pi con FS compartido (tests/Feature/Agents/PiRuntimeFsIntegrationTest).
// Misma semantica que gateway-chat.mjs pero con los escenarios de la tarea
// 6.4: el gateway es el "modelo" determinista que ordena comandos bash.
//
//   user con "fsrw"    -> tool bash `echo hola > /fs/ana/proyectos/hola.txt` (rw: auto)
//   user con "fsro"    -> tool bash `echo hola > /fs/ana/informes/hola.txt` (ro: aprobacion)
//   user con "fsfuera" -> tool bash `echo hola > /tmp/fuera.txt`      (fuera: aprobacion)
//   user con "fsread"  -> tool bash `cat /fs/ana/informes/seed.txt`    (ro: auto)
//   tool result        -> texto de confirmacion (detecta el fallo EROFS)
import http from 'node:http';
import fs from 'node:fs';

const port = Number(process.argv[2] || 8903);
const reqs = [];
const logFile = process.argv[3] || null;

const userText = (b) => (b.messages || [])
  .filter((m) => m.role === 'user')
  .flatMap((m) => (typeof m.content === 'string' ? [m.content] : (m.content || []).map((c) => c.text ?? '')))
  .filter(Boolean).pop() ?? '';
const toolMsg = (b) => (b.messages || []).filter((m) => m.role === 'tool').pop() ?? null;

const CMD = {
  fsrw: 'echo hola > /fs/ana/proyectos/hola.txt',
  fsro: 'echo hola > /fs/ana/informes/hola.txt',
  fsfuera: 'echo hola > /tmp/fuera.txt',
  fsread: 'cat /fs/ana/informes/seed.txt',
};
const scenarioOf = (t) => (['fsrw', 'fsro', 'fsfuera', 'fsread'].find((k) => t.includes(k)) ?? null);

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
    // La rama de confirmacion solo cuando el ULTIMO mensaje es el tool
    // result (llamada de cierre del propio turno); los tool results de
    // turnos anteriores viven en el historial y no disparan esta rama.
    const lastMsg = (parsed.messages || []).slice(-1)[0] || null;
    const tool = lastMsg && lastMsg.role === 'tool' ? lastMsg : null;
    const entry = { n: reqs.length, auth: req.headers.authorization ?? null, ut: ut.slice(0, 80), nMessages: (parsed.messages || []).length, tool: !!tool, toolOut: tool ? String(tool.content).slice(0, 120) : null, stream: !!parsed.stream, model: parsed.model ?? null };
    reqs.push(entry);
    try { if (logFile) fs.appendFileSync(logFile, JSON.stringify(entry) + '\n'); } catch {}

    const id = 'c_' + entry.n;
    let message;
    let toolCalls;
    if (tool) {
      const out = String(tool.content ?? '');
      message = { role: 'assistant', content: out.includes('read-only') ? 'El montaje es de solo lectura: no pude escribir.' : out.trim() ? 'Hecho: ' + out.slice(0, 60) : 'Hecho.' };
    } else {
      const sc = scenarioOf(ut);
      if (sc) {
        // El Model Proxy llama en modo no-stream y sintetiza el SSE: la
        // llamada a herramienta debe ir en message.tool_calls.
        toolCalls = [{ index: 0, id: 'call_' + entry.n, type: 'function', function: { name: 'bash', arguments: JSON.stringify({ command: CMD[sc] }) } }];
        message = { role: 'assistant', content: 'Voy a ejecutar: ' + CMD[sc], tool_calls: toolCalls };
      } else {
        message = { role: 'assistant', content: 'Listo para el siguiente turno.' };
      }
    }
    if (!parsed.stream) {
      res.writeHead(200, { 'content-type': 'application/json', 'cache-control': 'no-cache' });
      res.end(JSON.stringify({ id, object: 'chat.completion', model: parsed.model, choices: [{ index: 0, message, finish_reason: toolCalls ? 'tool_calls' : 'stop' }], usage: { prompt_tokens: 10, completion_tokens: 8, total_tokens: 18 } }));
      return;
    }
    res.writeHead(200, { 'content-type': 'text/event-stream', 'cache-control': 'no-cache' });
    const sse = (o, usage = null) => { res.write('data: ' + JSON.stringify({ id, object: 'chat.completion.chunk', choices: [o], ...(usage ? { usage } : {}) }) + '\n\n'); };
    sse({ index: 0, delta: { role: 'assistant', content: message.content }, finish_reason: null });
    if (toolCalls) sse({ index: 0, delta: { tool_calls: toolCalls }, finish_reason: null });
    sse({ index: 0, delta: {}, finish_reason: 'stop' }, { prompt_tokens: 10, completion_tokens: message.content.length, total_tokens: 10 + message.content.length });
    res.write('data: [DONE]\n\n');
    res.end();
  });
});

server.listen(port, '0.0.0.0', () => console.log(`gateway chat (pi fs) en ${port}`));

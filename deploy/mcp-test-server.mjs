#!/usr/bin/env node
/**
 * Servidor MCP mínimo (streamable HTTP) para el E2E de la puerta MCP de TAAP.
 * Expone una sola tool: get_time. Sin dependencias.
 */
import http from 'node:http';

const PORT = Number(process.env.MCP_PORT || 9911);

const serverInfo = { name: 'taap-test-time', version: '1.0.0' };

const tools = [
  {
    name: 'get_time',
    description: 'Devuelve la hora actual del sistema (UTC) en formato ISO 8601.',
    inputSchema: { type: 'object', properties: {}, additionalProperties: false },
  },
];

function handle(body) {
  const { id, method, params } = body;

  const reply = (result, isError = false) => ({
    jsonrpc: '2.0',
    id,
    result: { content: [{ type: 'text', text: result }], isError },
  });

  switch (method) {
    case 'initialize':
      return {
        jsonrpc: '2.0',
        id,
        result: {
          protocolVersion: params?.protocolVersion || '2024-11-05',
          capabilities: { tools: {} },
          serverInfo,
        },
      };
    case 'notifications/initialized':
      return null; // notificacion: sin respuesta
    case 'ping':
      return { jsonrpc: '2.0', id, result: {} };
    case 'tools/list':
      return { jsonrpc: '2.0', id, result: { tools } };
    case 'tools/call': {
      if (params?.name === 'get_time') {
        return reply(new Date().toISOString());
      }
      return {
        jsonrpc: '2.0',
        id,
        error: { code: -32601, message: `tool desconocida: ${params?.name}` },
      };
    }
    default:
      return { jsonrpc: '2.0', id, error: { code: -32601, message: `metodo desconocido: ${method}` } };
  }
}

http.createServer((req, res) => {
  if (req.method !== 'POST') {
    res.writeHead(405, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'use POST' }));
    return;
  }

  let raw = '';
  req.on('data', (c) => (raw += c));
  req.on('end', () => {
    try {
      const body = JSON.parse(raw || '{}');
      const out = handle(body);
      if (out === null) {
        res.writeHead(202);
        res.end();
        return;
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(out));
    } catch (e) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ jsonrpc: '2.0', id: null, error: { code: -32700, message: String(e) } }));
    }
  });
}).listen(PORT, '0.0.0.0', () => console.log(`mcp test server on :${PORT}`));

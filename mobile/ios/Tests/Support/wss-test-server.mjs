#!/usr/bin/env node
/**
 * Servidor de pruebas del protocolo Pusher/Reverb para los XCTests de iOS.
 *
 * Sin dependencias (el paquete `ws` no es garantizable en el host):
 * implementa el minimo del handshake WebSocket (RFC 6455) y el protocolo
 * Pusher v7 que habla ReverbSocket.swift:
 *   - connection_established (socket_id)
 *   - pusher:ping -> pusher:pong
 *   - pusher:subscribe (auth) -> subscription_succeeded
 *   - agent-event (con el payload que pida el test)
 *
 * API de control (HTTP JSON):
 *   POST /broadcast            {channel, event, data}  -> emite el frame
 *   POST /close-clients                            -> cierra todas las WS
 *   POST /shutdown                               -> termina el proceso
 */
import http from 'node:http';
import crypto from 'node:crypto';

const PORT = Number(process.env.TAAP_WSS_TEST_PORT || 9950);
const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

const sockets = new Set();

// ---- WebSocket minimo (frames de texto, un frame por mensaje) ----

function wsAccept(key) {
  return crypto.createHash('sha1').update(key + GUID).digest('base64');
}

function encodeFrame(text) {
  const payload = Buffer.from(text, 'utf8');
  const len = payload.length;
  let header;
  if (len < 126) {
    header = Buffer.alloc(2);
    header[1] = len;
  } else if (len < 65536) {
    header = Buffer.alloc(4);
    header[1] = 126;
    header.writeUInt16BE(len, 2);
  } else {
    header = Buffer.alloc(10);
    header[1] = 127;
    header.writeBigUInt64BE(BigInt(len), 2);
  }
  header[0] = 0x81; // FIN + texto
  return Buffer.concat([header, payload]);
}

// ---- Servidor ----

const server = http.createServer((req, res) => {
  const send = (code, obj) => {
    const body = JSON.stringify(obj);
    res.writeHead(code, {
      'Content-Type': 'application/json',
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Headers': '*',
    });
    res.end(body);
  };

  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
      'Access-Control-Allow-Headers': '*',
    });
    return res.end();
  }

  if (req.method === 'POST' && req.url === '/broadcasting/auth') {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      // Firma de juguete: el cliente solo necesita un string "clave:huella".
      send(200, { auth: 'test:' + crypto.createHash('sha256').update(body).digest('hex').slice(0, 16) });
    });
    return;
  }

  if (req.method === 'POST' && req.url === '/broadcast') {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      try {
        const { channel, event, data } = JSON.parse(body);
        const frame = JSON.stringify({
          event,
          channel,
          data: JSON.stringify(data ?? {}),
        });
        for (const c of sockets) {
          if (c.socket.writable && !c.socket.destroyed) c.socket.write(encodeFrame(frame));
        }
        send(200, { ok: true, sent: sockets.size });
      } catch (e) {
        send(400, { error: String(e) });
      }
    });
    return;
  }

  if (req.method === 'POST' && req.url === '/close-clients') {
    // Frame de close (0x88) para que el cliente vea una caida limpia.
    const close = Buffer.from([0x88, 0x00]);
    for (const c of sockets) {
      if (c.socket.writable && !c.socket.destroyed) c.socket.write(close, () => c.socket.end());
    }
    return send(200, { ok: true });
  }

  if (req.method === 'POST' && req.url === '/shutdown') {
    send(200, { ok: true });
    return setTimeout(() => process.exit(0), 50);
  }

  send(404, { error: 'nope' });
});

server.on('upgrade', (req, socket) => {
  const key = req.headers['sec-websocket-key'];
  if (!key) return socket.end();
  socket.write(
    'HTTP/1.1 101 Switching Protocols\r\n' +
    'Upgrade: websocket\r\n' +
    'Connection: Upgrade\r\n' +
    `Sec-WebSocket-Accept: ${wsAccept(key)}\r\n\r\n`
  );

  const client = { socket, buffer: Buffer.alloc(0) };
  sockets.add(client);

  // Handshake Pusher: established con socket_id.
  socket.write(
    encodeFrame(
      JSON.stringify({
        event: 'pusher:connection_established',
        channel: 'pusher',
        data: JSON.stringify({
          socket_id: '1234.5678',
          activity_timeout: 240,
        }),
      })
    )
  );

  socket.on('data', (chunk) => {
    client.buffer = Buffer.concat([client.buffer, chunk]);
    let off = 0;
    while (true) {
      const buf = client.buffer;
      if (buf.length - off < 2) break;
      const lenByte = buf[off + 1] & 0x7f;
      let payloadLen;
      let bodyOff;
      if (lenByte < 126) {
        payloadLen = lenByte;
        bodyOff = off + 2;
      } else if (lenByte === 126) {
        if (buf.length - off < 4) break;
        payloadLen = buf.readUInt16BE(off + 2);
        bodyOff = off + 4;
      } else if (lenByte === 127) {
        if (buf.length - off < 10) break;
        payloadLen = Number(buf.readBigUInt64BE(off + 2));
        bodyOff = off + 10;
      } else {
        break;
      }
      const masked = (buf[off + 1] & 0x80) !== 0;
      const maskOff = bodyOff + (masked ? 4 : 0);
      if (buf.length < maskOff + payloadLen) break; // frame incompleto
      let payload = buf.subarray(maskOff, maskOff + payloadLen);
      if (masked) {
        const mask = buf.subarray(bodyOff, bodyOff + 4);
        payload = Buffer.from(payload);
        for (let i = 0; i < payload.length; i++) payload[i] ^= mask[i % 4];
      }
      const opcode = buf[off] & 0x0f;
      off = maskOff + payloadLen;
      if (opcode !== 0x1) continue; // no texto
      let msg;
      try {
        msg = JSON.parse(payload.toString('utf8'));
      } catch {
        continue;
      }
      if (msg.event === 'pusher:ping') {
        socket.write(encodeFrame(JSON.stringify({ event: 'pusher:pong', data: '{}' })));
      } else if (msg.event === 'pusher:subscribe') {
        socket.write(
          encodeFrame(
            JSON.stringify({
              event: 'pusher:subscription_succeeded',
              channel: msg.channel,
              data: '{}',
            })
          )
        );
      }
    }
    // Consume lo procesado; deja colgado el frame incompleto.
    client.buffer = off < client.buffer.length ? Buffer.from(client.buffer.subarray(off)) : Buffer.alloc(0);
  });

  const cleanup = () => {
    sockets.delete(client);
    socket.destroy();
  };
  socket.on('close', cleanup);
  socket.on('error', cleanup);
});

server.listen(PORT, '127.0.0.1', () => {
  console.log(`TAAP WSS test server on http://127.0.0.1:${PORT}`);
});

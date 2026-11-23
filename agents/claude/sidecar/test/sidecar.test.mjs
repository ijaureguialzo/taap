// Suite del sidecar Claude (tarea 5.1): SDK falsificado, sin red ni Docker.
//
//   node --test test/
//
// Los tests esperan por EVENTOS (waitFor), no por conteo de lineas:
// `PassThrough.write()` emite `data` de forma sincrona, de modo que parte
// del resultado de un comando puede llegar dentro del propio `sendLine`.

import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import { assistantMsg, bootSidecar, cmd, resultMsg, userMsg, waitFor } from './helpers.mjs';

function parsed(side) {
  return side.lines.map((l) => {
    const o = JSON.parse(l);
    assert.equal(o.protocol, 1, 'cada linea lleva protocol: 1');
    return o;
  });
}

function ofType(side, type) {
  return parsed(side).filter((e) => e.type === type);
}

function scratch(prefix) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), prefix));
  return { dir, cfg: path.join(dir, 'claude.json'), state: path.join(dir, 'session-state.json') };
}

test('prompt: emite la secuencia exacta de AgentEvent (run->delta->tool->final->end)', async (t) => {
  const s = scratch('taap-sidecar-cfg-');
  const query = function ({ options }) {
    return {
      async *[Symbol.asyncIterator]() {
        yield { type: 'system', subtype: 'init', session_id: 'S-1', model: options.model, tools: ['Bash'] };
        yield assistantMsg([{ type: 'text', text: 'Voy a mirar. ' }]);
        yield assistantMsg([{ type: 'tool_use', id: 't1', name: 'Bash', input: { command: 'ls' } }]);
        yield userMsg([{ tool_use_id: 't1', type: 'tool_result', content: 'ficheros', is_error: false }]);
        yield assistantMsg([{ type: 'text', text: 'Hay varios ficheros.' }]);
        yield resultMsg('Hay varios ficheros.', { sessionId: 'S-1' });
      },
    };
  };

  const side = bootSidecar({ query, configPath: s.cfg, stateFile: s.state });
  side.sendLine(cmd({ id: 'c1', type: 'prompt', message: 'hola' }));
  await waitFor(side, (e) => e.type === 'run.end');

  const events = parsed(side).slice(1); // excluye la `starting` del arranque
  assert.deepEqual(
    events.map((e) => e.type),
    ['run.start', 'status.change', 'message.delta', 'tool.start', 'tool.result', 'message.delta', 'message.final', 'run.end', 'status.change'],
  );

  assert.equal(events.find((e) => e.type === 'message.delta').payload.content, 'Voy a mirar. ');
  const fin = events.find((e) => e.type === 'message.final');
  assert.equal(fin.payload.content, 'Hay varios ficheros.');
  assert.equal(fin.payload.run_id, events[0].payload.run_id);
  assert.equal(events.at(-1).payload.status, 'idle');

  const ts = events.find((e) => e.type === 'tool.start');
  assert.equal(ts.payload.tool, 'Bash');
  assert.deepEqual(ts.payload.args, { command: 'ls' });

  const tr = events.find((e) => e.type === 'tool.result');
  assert.equal(tr.payload.tool, 'Bash');
  assert.equal(tr.payload.ok, true);
  assert.equal(tr.payload.summary, 'ficheros');

  assert.equal(ofType(side, 'run.end')[0].payload.ok, true);

  // la sesion se persistio en el volumen
  assert.equal(JSON.parse(fs.readFileSync(s.state, 'utf8')).session_id, 'S-1');
});

test('razonamiento: bloques thinking -> thinking.delta y thinking en el final', async (t) => {
  const s = scratch('taap-sidecar-cfg-');
  const query = function ({ options }) {
    return {
      async *[Symbol.asyncIterator]() {
        yield { type: 'system', subtype: 'init', session_id: 'S-T', model: options.model, tools: [] };
        yield assistantMsg([{ type: 'thinking', thinking: 'Tengo que analizar la peticion. ' }]);
        yield assistantMsg([
          { type: 'thinking', thinking: 'Parece una suma simple.' },
          { type: 'text', text: '2+2=4.' },
        ]);
        yield resultMsg('2+2=4.', { sessionId: 'S-T' });
      },
    };
  };

  const side = bootSidecar({ query, configPath: s.cfg, stateFile: s.state });
  side.sendLine(cmd({ id: 'c1', type: 'prompt', message: 'suma' }));
  await waitFor(side, (e) => e.type === 'run.end');

  // El razonamiento viaja como flujo SEPARADO (dos bloques, en orden)
  const td = ofType(side, 'thinking.delta');
  assert.equal(td.length, 2);
  assert.equal(td[0].payload.content, 'Tengo que analizar la peticion. ');
  assert.equal(td[1].payload.content, 'Parece una suma simple.');
  assert.equal(td[0].payload.run_id, ofType(side, 'run.start')[0].payload.run_id);

  // El final lleva el razonamiento acumulado, separado del contenido
  const fin = ofType(side, 'message.final')[0];
  assert.equal(fin.payload.content, '2+2=4.');
  assert.equal(fin.payload.thinking, 'Tengo que analizar la peticion. Parece una suma simple.');
});

test('sin razonamiento: el final lleva thinking vacio', async (t) => {
  const s = scratch('taap-sidecar-cfg-');
  const query = function () {
    return {
      async *[Symbol.asyncIterator]() {
        yield assistantMsg([{ type: 'text', text: 'Ok.' }]);
        yield resultMsg('Ok.', { sessionId: 'S-N' });
      },
    };
  };

  const side = bootSidecar({ query, configPath: s.cfg, stateFile: s.state });
  side.sendLine(cmd({ id: 'c1', type: 'prompt', message: 'ok' }));
  await waitFor(side, (e) => e.type === 'run.end');

  assert.equal(ofType(side, 'thinking.delta').length, 0);
  const fin = ofType(side, 'message.final')[0];
  assert.equal(fin.payload.content, 'Ok.');
  assert.equal(fin.payload.thinking, '');
});

test('get_state: responde ready', async (t) => {
  const s = scratch('taap-sidecar-cfg-');
  const query = function () {
    return { async *[Symbol.asyncIterator]() {} };
  };
  const side = bootSidecar({ query, configPath: s.cfg, stateFile: s.state });
  side.sendLine(cmd({ id: 'g1', type: 'get_state' }));
  await waitFor(side, (e) => e.type === 'status.change' && e.payload.status === 'ready');
});

test('aprobacion: allow -> el tool se ejecuta y el turno termina', async (t) => {
  const s = scratch('taap-sidecar-cfg-');
  const query = function (q) {
    return {
      async *[Symbol.asyncIterator]() {
        yield assistantMsg([{ type: 'tool_use', id: 't9', name: 'Bash', input: { command: 'rm -rf /x' } }]);
        const decision = await q.options.canUseTool('Bash', { command: 'rm -rf /x' }, { signal: new AbortController().signal });
        assert.equal(decision.behavior, 'allow');
        yield userMsg([{ tool_use_id: 't9', type: 'tool_result', content: 'borrado', is_error: false }]);
        yield resultMsg('Listo.', { sessionId: 'S-A' });
      },
    };
  };

  const side = bootSidecar({ query, configPath: s.cfg, stateFile: s.state });
  side.sendLine(cmd({ id: 'p1', type: 'prompt', message: 'borra' }));

  const req = await waitFor(side, (e) => e.type === 'approval.request');
  assert.match(req.payload.prompt, /rm -rf \/x/);
  assert.ok(req.payload.options.some((o) => o.value === 'always'), 'ofrece «permitir siempre»');
  assert.equal(req.payload.resolved, false);

  side.sendLine(cmd({ id: 'r1', type: 'approval_response', approval_id: req.payload.id, approved: true, option: 'once' }));
  await waitFor(side, (e) => e.type === 'run.end');

  const res = ofType(side, 'approval.resolved')[0];
  assert.equal(res.payload.approved, true);
  assert.ok(ofType(side, 'tool.result').length > 0, 'el tool se ejecuto tras el allow');
  assert.equal(ofType(side, 'run.end')[0].payload.ok, true, 'el turno termino');
});

test('aprobacion: deny -> tool_result de error y el agente continua', async (t) => {
  const s = scratch('taap-sidecar-cfg-');
  const query = function (q) {
    return {
      async *[Symbol.asyncIterator]() {
        yield assistantMsg([{ type: 'tool_use', id: 't2', name: 'Bash', input: { command: 'rm -rf /y' } }]);
        const decision = await q.options.canUseTool('Bash', { command: 'rm -rf /y' }, { signal: new AbortController().signal });
        assert.equal(decision.behavior, 'deny');
        yield userMsg([{ tool_use_id: 't2', type: 'tool_result', content: decision.message, is_error: true }]);
        yield resultMsg('Procedo sin borrar.', { sessionId: 'S-B' });
      },
    };
  };

  const side = bootSidecar({ query, configPath: s.cfg, stateFile: s.state });
  side.sendLine(cmd({ id: 'p2', type: 'prompt', message: 'borra' }));
  const req = await waitFor(side, (e) => e.type === 'approval.request');

  side.sendLine(cmd({ id: 'r2', type: 'approval_response', approval_id: req.payload.id, approved: false, option: 'deny', note: 'no, gracias' }));
  await waitFor(side, (e) => e.type === 'run.end');

  const res = ofType(side, 'approval.resolved')[0];
  assert.equal(res.payload.approved, false);
  assert.equal(res.payload.note, 'no, gracias');
  const tr = ofType(side, 'tool.result')[0];
  assert.equal(tr.payload.ok, false);
  assert.match(tr.payload.summary, /no, gracias/);
  assert.equal(ofType(side, 'run.end')[0].payload.ok, true, 'el agente continuo y termino la corrida');
});

test('aprobacion: timeout -> denegado con nota y el agente continua', async (t) => {
  const s = scratch('taap-sidecar-cfg-');
  const query = function (q) {
    return {
      async *[Symbol.asyncIterator]() {
        yield assistantMsg([{ type: 'tool_use', id: 't3', name: 'Bash', input: { command: 'ping' } }]);
        const decision = await q.options.canUseTool('Bash', { command: 'ping' }, { signal: new AbortController().signal });
        assert.equal(decision.behavior, 'deny');
        yield userMsg([{ tool_use_id: 't3', type: 'tool_result', content: decision.message, is_error: true }]);
        yield resultMsg('Sin respuesta, sigo.', { sessionId: 'S-C' });
      },
    };
  };

  const side = bootSidecar({ query, timeoutMs: 30, configPath: s.cfg, stateFile: s.state });
  side.sendLine(cmd({ id: 'p3', type: 'prompt', message: 'pinta' }));
  const req = await waitFor(side, (e) => e.type === 'approval.request');
  assert.ok(req);

  // no responder: el timeout (30ms) la deniega y la corrida prosigue y termina
  await waitFor(side, (e) => e.type === 'run.end');
  const res = ofType(side, 'approval.resolved')[0];
  assert.equal(res.payload.approved, false, 'el timeout deniega');
  assert.equal(ofType(side, 'run.end')[0].payload.ok, true, 'el agente no queda zombie');
});

test('«permitir siempre»: persiste el tool y no vuelve a preguntar', async (t) => {
  const s = scratch('taap-sidecar-cfg-');
  let turn = 0;
  const query = function (q) {
    turn += 1;
    return {
      async *[Symbol.asyncIterator]() {
        yield assistantMsg([{ type: 'tool_use', id: `t${turn}`, name: 'Bash', input: { command: `c${turn}` } }]);
        const decision = await q.options.canUseTool('Bash', { command: `c${turn}` }, { signal: new AbortController().signal });
        yield userMsg([{ tool_use_id: `t${turn}`, type: 'tool_result', content: 'ok', is_error: decision.behavior === 'deny' }]);
        yield resultMsg(`fin ${turn}`, { sessionId: `S-${turn}` });
      },
    };
  };

  const side = bootSidecar({ query, configPath: s.cfg, stateFile: s.state });

  // Turno 1: «permitir siempre» Bash
  side.sendLine(cmd({ id: 'a1', type: 'prompt', message: 'una' }));
  const req1 = await waitFor(side, (e) => e.type === 'approval.request');
  side.sendLine(cmd({ id: 'a2', type: 'approval_response', approval_id: req1.payload.id, approved: true, option: 'always' }));
  await waitFor(side, (e) => e.type === 'run.end');

  // persistido en el volumen (sobrevive a reinicios)
  assert.deepEqual(JSON.parse(fs.readFileSync(s.state, 'utf8')).always_allowed, ['Bash']);

  // Turno 2: no debe pedir aprobacion de nuevo
  side.sendLine(cmd({ id: 'a3', type: 'prompt', message: 'dos' }));
  await waitFor(side, () => ofType(side, 'run.end').length >= 2);
  const asked2 = ofType(side, 'approval.request').filter((e) => e.payload.prompt.includes('c2'));
  assert.equal(asked2.length, 0, 'el tool «permitir siempre» no vuelve a preguntar');
  assert.equal(ofType(side, 'run.end').length, 2);
});

test('reanudacion: un sidecar nuevo reanuda la sesion persistida (kill+restart)', async (t) => {
  const s = scratch('taap-sidecar-cfg-');
  const seenResume = [];
  const query = function (q) {
    seenResume.push(q.options.resume ?? null);
    return {
      async *[Symbol.asyncIterator]() {
        yield resultMsg('ok', { sessionId: 'S-RESUME' });
      },
    };
  };

  // 1er sidecar: sin sesion previa (resume=null)
  const first = bootSidecar({ query, configPath: s.cfg, stateFile: s.state });
  first.sendLine(cmd({ id: 'n1', type: 'prompt', message: 'hola' }));
  await waitFor(first, (e) => e.type === 'run.end');
  first.sidecar.stop();

  // «reinicio del contenedor»: nuevo sidecar, mismo volumen
  const second = bootSidecar({ query, configPath: s.cfg, stateFile: s.state });
  second.sendLine(cmd({ id: 'n2', type: 'prompt', message: 'sigues ahi?' }));
  await waitFor(second, (e) => e.type === 'run.end');

  assert.deepEqual(seenResume, [null, 'S-RESUME'], 'el 2o query pasa resume con el id persistido');
});

test('linea malformada: evento error, el sidecar no crashea', async (t) => {
  const s = scratch('taap-sidecar-cfg-');
  const query = function () {
    return { async *[Symbol.asyncIterator]() {} };
  };
  const side = bootSidecar({ query, configPath: s.cfg, stateFile: s.state });

  side.sendLine('esto no es JSON');
  side.sendLine(JSON.stringify({ protocol: 99, id: 'x', type: 'prompt', message: 'm' }));
  await waitFor(side, () => ofType(side, 'error').length >= 2);

  assert.match(ofType(side, 'error')[0].payload.message, /no válido/);

  // y sigue vivo: un get_state responde
  side.sendLine(cmd({ id: 'g2', type: 'get_state' }));
  await waitFor(side, (e) => e.type === 'status.change' && e.payload.status === 'ready');
});

test('prompt en cola: un prompt durante un turno en vuelo se procesa despues (FIFO)', async (t) => {
  const s = scratch('taap-sidecar-cfg-');
  let release;
  const releasePromise = new Promise((r) => (release = r));
  const prompts = [];
  const query = function (q) {
    return {
      async *[Symbol.asyncIterator]() {
        prompts.push(q.prompt);
        if (prompts.length === 1) {
          await releasePromise; // el 1o turno se queda en vuelo
        }
        yield resultMsg(q.prompt, { sessionId: 'S-Q' });
      },
    };
  };

  const side = bootSidecar({ query, configPath: s.cfg, stateFile: s.state });
  side.sendLine(cmd({ id: 'q1', type: 'prompt', message: 'primero' }));
  await new Promise((r) => setTimeout(r, 30));
  side.sendLine(cmd({ id: 'q2', type: 'prompt', message: 'segundo' }));
  await new Promise((r) => setTimeout(r, 30));
  assert.equal(prompts.length, 1, 'el 2o prompt espera en cola (FIFO)');

  release();
  await waitFor(side, () => ofType(side, 'run.end').length >= 2, { timeoutMs: 5000 });
  assert.deepEqual(prompts, ['primero', 'segundo']);
});

test('max_retries: se traduce a CLAUDE_CODE_MAX_RETRIES del env del CLI', async (t) => {
  const { loadConfig } = await import('../src/config.mjs');
  const s = scratch('taap-sidecar-retries-');
  fs.writeFileSync(s.cfg, JSON.stringify({ model: 'claude-sonnet-4-5', max_retries: 2 }));
  const cfg = loadConfig(s.cfg);
  assert.equal(cfg.max_retries, 2);

  // sin campo: null (el CLI usa su default; no se fuerza nada)
  fs.writeFileSync(s.cfg, JSON.stringify({ model: 'claude-sonnet-4-5' }));
  assert.equal(loadConfig(s.cfg).max_retries, null);

  // string: no es un entero valido -> null
  fs.writeFileSync(s.cfg, JSON.stringify({ model: 'claude-sonnet-4-5', max_retries: '3' }));
  assert.equal(loadConfig(s.cfg).max_retries, null);

  const saved = process.env.CLAUDE_CODE_MAX_RETRIES;
  delete process.env.CLAUDE_CODE_MAX_RETRIES;
  try {
    let seen = null;
    const query = function ({ options }) {
      seen = options.env;
      return {
        async *[Symbol.asyncIterator]() {
          yield resultMsg('ok', { sessionId: 'S-R' });
        },
      };
    };
    fs.writeFileSync(s.cfg, JSON.stringify({ model: 'claude-sonnet-4-5', max_retries: 2 }));
    const side = bootSidecar({ query, configPath: s.cfg, stateFile: s.state });
    side.sendLine(cmd({ id: 'r1', type: 'prompt', message: 'hola' }));
    await waitFor(side, (e) => e.type === 'run.end');
    assert.equal(seen.CLAUDE_CODE_MAX_RETRIES, '2');

    fs.writeFileSync(s.cfg, JSON.stringify({ model: 'claude-sonnet-4-5' }));
    seen = null;
    const side2 = bootSidecar({ query, configPath: s.cfg, stateFile: s.state + '-2' });
    side2.sendLine(cmd({ id: 'r2', type: 'prompt', message: 'hola' }));
    await waitFor(side2, (e) => e.type === 'run.end');
    assert.equal(seen.CLAUDE_CODE_MAX_RETRIES, undefined);
  } finally {
    if (saved !== undefined) {
      process.env.CLAUDE_CODE_MAX_RETRIES = saved;
    }
  }
});

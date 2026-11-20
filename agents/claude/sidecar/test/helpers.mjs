// Helpers de test: SDK falsificado + sidecar en memoria.
//
// La version fijada del SDK se spawnea un CLI por `query()` y emite messages
// crudos (system/assistant/user/result). Los tests NO instalan el SDK real
// (no hay en node_modules de dev): inyectan un `query` falso con el mismo
// contrato de stream, de modo que el sidecar (y su mapeo) se pruebe de
// forma determinista y sin red.

import { PassThrough } from 'node:stream';
import { createSidecar } from '../src/index.mjs';

/**
 * Crea un stream asyncIterable de messages a partir de un array, con
 * pausas programables entre ellos.
 *
 * @param {object[]} messages
 * @param {number} [delayMs]
 */
export function fakeQuery(messages, { delayMs = 0, onOptions = null } = {}) {
  return function query({ options }) {
    onOptions?.(options);
    return {
      async *[Symbol.asyncIterator]() {
        for (const m of messages) {
          if (delayMs > 0) {
            await new Promise((r) => setTimeout(r, delayMs));
          }
          yield m;
        }
      },
    };
  };
}

/**
 * Empaqueta un message `assistant` con bloques.
 * @param {object[]} blocks
 */
export const assistantMsg = (blocks) => ({ type: 'assistant', message: { role: 'assistant', content: blocks }, session_id: null });
export const userMsg = (blocks) => ({ type: 'user', message: { role: 'user', content: blocks }, session_id: null });
export const resultMsg = (result, { isError = false, sessionId = null, subtype = 'success' } = {}) => ({
  type: 'result',
  subtype,
  is_error: isError,
  result,
  session_id: sessionId,
  num_turns: 1,
});

/**
 * Arranca un sidecar en memoria con un stdin virtual y captura sus lineas.
 *
 * @param {object} [deps]
 * @returns {{sidecar: object, sendLine: (s: string) => void, lines: string[],
 *            written: string[]}}
 */
export function bootSidecar(deps = {}) {
  const lines = [];
  const stdin = new PassThrough();
  const sidecar = createSidecar({
    stdin,
    query: deps.query,
    write: (line) => lines.push(line),
    configPath: deps.configPath,
    stateFile: deps.stateFile,
    timeoutMs: deps.timeoutMs,
    exit: () => {},
  });
  return {
    sidecar,
    lines,
    sendLine: (line) => stdin.write(line + '\n'),
    // Espera a que salgan N lineas nuevas (para tests que dependen del
    // orden: el bucle del sidecar es async pero secuencial por linea).
    async waitLines(n, { timeoutMs = 2000 } = {}) {
      const start = lines.length;
      const t0 = Date.now();
      while (lines.length < start + n) {
        if (Date.now() - t0 > timeoutMs) {
          throw new Error(`timeout esperando ${n} lineas (tenia ${lines.length - start}): ${lines.slice(start).join(' | ')}`);
        }
        await new Promise((r) => setTimeout(r, 5));
      }
      return lines.slice(start, start + n);
    },
  };
}

/**
 * Codifica un comando TAAP v1.
 */
export function cmd(obj) {
  return JSON.stringify({ protocol: 1, ...obj });
}

/**
 * Espera a que aparezca un evento que cumpla `predicate` en la captura.
 *
 * Se prefiere a contar lineas: `PassThrough.write()` emite `data` de forma
 * sincrona, asi que parte del resultado de un comando ya puede haber
 * llegado en el instante mismo del `sendLine` (nada que esperar).
 *
 * @param {(e: {type: string, payload: Record<string, unknown>}) => boolean} predicate
 * @param {object} [opts]
 * @returns {Promise<{type: string, payload: Record<string, unknown>}>}
 */
export async function waitFor(side, predicate, { timeoutMs = 5000 } = {}) {
  const t0 = Date.now();
  for (;;) {
    for (const line of side.lines) {
      const e = JSON.parse(line);
      if (predicate(e)) return e;
    }
    if (Date.now() - t0 > timeoutMs) {
      throw new Error(`timeout esperando evento; lineas capturadas: ${side.lines.length}`);
    }
    await new Promise((r) => setTimeout(r, 5));
  }
}

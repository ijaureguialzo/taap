// Mapeo SDK Claude Agent -> eventos normalizados TAAP (design D1).
//
// El sidecar emite el shape EXACTO de `AgentEvent::toArray()` (mas
// `protocol: 1`): el adapter PHP es un pase-through que solo valida. El
// mapeo vive junto al SDK porque este evoluciona rapido; un bump del SDK es
// un rebuild de la imagen taap/agent-claude, nunca del orquestador.
//
// Messages crudos del SDK fijado (spike 1.1):
//   system/init    {session_id, tools, model, permissionMode, ...}
//   assistant      {message: {content: [text|tool_use|thinking ...]}, ...}
//   user           {message: {content: [tool_result ...]}, tool_result_meta}
//   result         {session_id, is_error, subtype, result, num_turns, ...}

import { PROTOCOL_VERSION, encodeEvent } from './protocol.mjs';

/**
 * @typedef {{runId: string, sessionId: string|null, emit: (line: string) => void,
 *            now: () => Date}} Ctx
 */

/**
 * Traduce un mensaje crudo del SDK a lineas TAAP v1.
 *
 * @param {object} msg mensaje SDK (system | assistant | user | result)
 * @param {Ctx} ctx
 * @returns {string[]} lineas JSONL (puede ser 0..n)
 */
export function mapSdkMessage(msg, ctx) {
  const { runId, emit, now } = ctx;
  const lines = [];
  const push = (type, payload) => lines.push(encodeEvent(type, payload, now()));

  if (!msg || typeof msg !== 'object') {
    return lines;
  }

  switch (msg.type) {
    // ---- asistente -------------------------------------------------
    case 'assistant': {
      const content = msg.message?.content;
      if (!Array.isArray(content)) {
        return lines;
      }
      for (const block of content) {
        if (!block || typeof block !== 'object') {
          continue;
        }
        if (block.type === 'text' && typeof block.text === 'string' && block.text !== '') {
          push('message.delta', { run_id: runId, content: block.text });
        } else if (block.type === 'tool_use') {
          push('tool.start', {
            run_id: runId,
            tool: String(block.name ?? 'tool'),
            args: isObject(block.input) ? block.input : {},
          });
        }
        // thinking: no va a la UI (la respuesta final cubre el contenido);
        // se descarta deliberadamente para no doblar el texto visible.
      }
      return lines;
    }

    // ---- resultados de herramientas (el CLI los devuelve como user) --
    case 'user': {
      const content = msg.message?.content;
      if (!Array.isArray(content)) {
        return lines;
      }
      for (const block of content) {
        if (!block || block.type !== 'tool_result') {
          continue;
        }
        const text = resultText(block.content);
        // El SDK 0.3.x devuelve el `tool_use_id` DENTRO del bloque (no en el
        // top-level del mensaje): correlacionamos contra el tool.start previo.
        const toolId = block.tool_use_id ?? msg.tool_use_id;
        const tool = (toolId && ctx.toolNames.get(toolId)) ?? 'tool';
        push('tool.result', {
          run_id: runId,
          tool,
          ok: !block.is_error,
          summary: truncate(text, 4000),
        });
      }
      return lines;
    }

    // ---- fin del turno ---------------------------------------------
    case 'result': {
      const text = typeof msg.result === 'string' ? msg.result : '';
      if (text !== '') {
        push('message.final', { run_id: runId, content: text });
      }
      if (msg.is_error) {
        push('error', {
          message: resultErrorMessage(msg),
        });
        push('run.end', { run_id: runId, ok: false });
        push('status.change', { status: 'error' });
      } else {
        push('run.end', { run_id: runId, ok: true });
        push('status.change', { status: 'idle' });
      }
      return lines;
    }

    // ---- resto de system: no van a la pipeline ----------------------
    // (init, commands_changed, session_state_changed, ...): el sondeo y el
    // estado los cubre la ruta get_state/status del orquestador.
    default:
      return lines;
  }
}

/**
 * Contenido de un tool_result a texto plano (string | bloques).
 * @param {unknown} content
 */
function resultText(content) {
  if (typeof content === 'string') {
    return content;
  }
  if (Array.isArray(content)) {
    return content
      .map((b) => (b && typeof b === 'object' && typeof b.text === 'string' ? b.text : ''))
      .join('');
  }
  return '';
}

/**
 * Mensaje de error legible para el evento `error` de un result fallido.
 * @param {object} msg
 */
function resultErrorMessage(msg) {
  const base = typeof msg.result === 'string' && msg.result !== '' ? msg.result : null;
  if (base) {
    return truncate(base, 1000);
  }
  if (Array.isArray(msg.errors) && msg.errors.length > 0) {
    return truncate(msg.errors.filter(Boolean).join('; '), 1000);
  }
  return `Claude: ${msg.subtype ?? 'error'}`;
}

/**
 * @param {unknown} v
 */
function isObject(v) {
  return v && typeof v === 'object' && !Array.isArray(v);
}

/**
 * @param {string} s
 * @param {number} max
 */
function truncate(s, max) {
  return s.length > max ? s.slice(0, max - 1) + '…' : s;
}

export { PROTOCOL_VERSION };

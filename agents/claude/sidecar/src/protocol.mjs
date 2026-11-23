// Protocolo TAAP v1 (design add-claude-code-runtime D1): JSONL versionado.
// Cada linea lleva `protocol: 1`; el adapter PHP valida esa version y el
// `type` contra los diez tipos de AgentEvent antes de construirla.

export const PROTOCOL_VERSION = 1;

const COMMAND_TYPES = Object.freeze(['prompt', 'approval_response', 'get_state']);
const EVENT_TYPES = Object.freeze([
  'message.delta',
  'thinking.delta',
  'message.final',
  'tool.start',
  'tool.result',
  'approval.request',
  'approval.resolved',
  'status.change',
  'run.start',
  'run.end',
  'error',
]);

/**
 * Decodifica una linea de comando (orquestador -> sidecar).
 *
 * @param {string} line
 * @returns {{protocol: number, id: string, type: string, message?: string,
 *           approval_id?: string, approved?: boolean, option?: string, note?: string}|null}
 *   null si la linea no es un comando v1 valido (el sidecar lo convierte en
 *   un evento `error`, nunca en un crash).
 */
export function decodeCommand(line) {
  let obj;
  try {
    obj = JSON.parse(line);
  } catch {
    return null;
  }
  if (!obj || typeof obj !== 'object' || Array.isArray(obj)) {
    return null;
  }
  if (obj.protocol !== PROTOCOL_VERSION) {
    return null;
  }
  if (typeof obj.type !== 'string' || !COMMAND_TYPES.includes(obj.type)) {
    return null;
  }
  if (typeof obj.id !== 'string' || obj.id === '') {
    return null;
  }
  const cmd = { protocol: obj.protocol, id: obj.id, type: obj.type };
  if (cmd.type === 'prompt') {
    if (typeof obj.message !== 'string') {
      return null;
    }
    cmd.message = obj.message;
  }
  if (cmd.type === 'approval_response') {
    if (typeof obj.approval_id !== 'string' || typeof obj.approved !== 'boolean') {
      return null;
    }
    cmd.approval_id = obj.approval_id;
    cmd.approved = obj.approved;
    // `option`: `once` (defecto), `always` («permitir siempre») o `deny`.
    // `deny` se deriva de `approved:false` si el campo falta.
    cmd.option = typeof obj.option === 'string' && obj.option !== '' ? obj.option : (obj.approved ? 'once' : 'deny');
    cmd.note = typeof obj.note === 'string' ? obj.note : null;
  }
  return cmd;
}

/**
 * Codifica un evento TAAP v1 (sidecar -> orquestador): el shape EXACTO de
 * `AgentEvent::toArray()` mas el sello de version.
 *
 * @param {string} type uno de EVENT_TYPES
 * @param {Record<string, unknown>} payload
 * @param {Date} [at]
 * @returns {string} linea JSONL
 */
export function encodeEvent(type, payload = {}, at = new Date()) {
  if (!EVENT_TYPES.includes(type)) {
    throw new Error(`tipo de evento desconocido: ${type}`);
  }
  return JSON.stringify({
    protocol: PROTOCOL_VERSION,
    type,
    payload,
    at: at.toISOString(),
  });
}

/**
 * Valida una linea de entrada de eventos (se usa para exponer las lineas a
 * los tests y para el pase-through del adapter): shape AgentEvent + protocol.
 *
 * @param {string} line
 * @returns {{protocol: number, type: string, payload: Record<string, unknown>}}|null
 */
export function decodeEventLine(line) {
  let obj;
  try {
    obj = JSON.parse(line);
  } catch {
    return null;
  }
  if (!obj || typeof obj !== 'object') {
    return null;
  }
  if (obj.protocol !== PROTOCOL_VERSION) {
    return null;
  }
  if (typeof obj.type !== 'string' || !EVENT_TYPES.includes(obj.type)) {
    return null;
  }
  if (!obj.payload || typeof obj.payload !== 'object') {
    return null;
  }
  return { protocol: obj.protocol, type: obj.type, payload: obj.payload };
}

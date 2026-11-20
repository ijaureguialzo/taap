// Aprobaciones nativas (design D3): el SDK pausa el agente en `canUseTool`
// hasta que el usuario decide. El sidecar:
//   1. emite `approval.request` (prompt = herramienta + resumen del input;
//      options = aprobar / «permitir siempre» / denegar)
//   2. deja una Promise pendiente por `approval_id`
//   3. al recibir `approval_response` resuelve con el PermissionResult
//      correspondiente (allow / deny con nota)
//
// Sin respuesta dentro del timeout (config, por defecto 30 min) se resuelve
// como denegado con nota: evita un agente zombie con Promise eterna y el
// agente continúa (el modelo ve el `tool_result` de error).
//
// «Permitir siempre»: el sidecar acumula la herramienta en su estado de
// sesion (session.mjs) y la aplica a los permisos del SIGUIENTE query
// (settings gestionadas: una regla `ask` no se deja desbancar por updates
// de sesion dentro del propio proceso). En el turno en curso, `canUseTool`
// auto-aprobá lo que ya está en el set.

import { randomUUID } from 'node:crypto';

export class ApprovalGate {
  /**
   * @param {{emit: (type: string, payload: Record<string, unknown>) => void,
   *          timeoutMs: number, now?: () => Date}} deps
   *   `emit` publica el evento (el sidecar lo volca a stdout).
   */
  constructor({ emit, timeoutMs, now = () => new Date() }) {
    this.emit = emit;
    this.timeoutMs = timeoutMs;
    this.now = now;
    /** @type {Map<string, {resolve: (r: object) => void, timer: NodeJS.Timeout, tool: string, runId: string|null, at: Date}>} */
    this.pending = new Map();
  }

  /**
   * Callback `canUseTool` del SDK.
   *
   * @param {string} toolName
   * @param {Record<string, unknown>} input
   * @param {{signal: AbortSignal, suggestions?: unknown[]}} options
   * @param {{runId: string|null}} ctx
   * @returns {Promise<object>} PermissionResult del SDK
   */
  async canUseTool(toolName, input, options, ctx) {
    const approvalId = randomUUID();
    const at = this.now();

    this.emit('approval.request', {
      run_id: ctx.runId,
      id: approvalId,
      prompt: describeToolUse(toolName, input),
      options: [
        { value: 'once', label: 'Approve' },
        { value: 'always', label: 'Allow always' },
        { value: 'deny', label: 'Deny' },
      ],
      resolved: false,
      at: at.toISOString(),
    });

    const entry = {
      resolve: null, // se rellena al crear la Promise
      timer: null,
      tool: toolName,
      runId: ctx.runId,
      at,
    };
    return new Promise((resolve) => {
      entry.resolve = (r) => resolve(r);
      entry.timer = setTimeout(() => this._settle(approvalId, null, null, true), this.timeoutMs);
      // El timer no debe mantener vivo el proceso si es lo unico pendiente.
      entry.timer.unref?.();
      this.pending.set(approvalId, entry);
      // Abort del SDK (cancelacion del query): denegar y no colgar.
      options.signal?.addEventListener('abort', () => this._settle(approvalId, false, 'cancelled', false), { once: true });
    });
  }

  /**
   * Resuelve una aprobacion pendiente desde un comando `approval_response`.
   *
   * @param {string} approvalId
   * @param {boolean} approved
   * @param {string} option `once` | `always` | `deny`
   * @param {string|null} note
   * @returns {boolean} false si el id no estaba pendiente (ya resuelta o
   *   desconocida: el emisor sigue recibiendo `approval.resolved`).
   */
  respond(approvalId, approved, option, note) {
    const entry = this.pending.get(approvalId);
    const effApproved = option === 'deny' ? false : approved;
    const effOption = option === 'deny' || !approved ? 'deny' : option;

    if (!entry) {
      return false;
    }

    // `approval.resolved` lo emite `_settle` (un unico sitio: respuesta,
    // timeout y cancelacion).
    this._settle(approvalId, effApproved, note, false, effOption);

    // `always`: el caller actualiza el set persistente (session.mjs) y lo
    // aplica a los permisos del siguiente turno.
    return { tool: entry.tool, option: effOption };
  }

  /**
   * @param {string} approvalId
   * @param {boolean|null} approved null = timeout
   * @param {string|null} note
   * @param {boolean} isTimeout
   * @param {string} [option]
   */
  _settle(approvalId, approved, note, isTimeout, option = 'once') {
    const entry = this.pending.get(approvalId);
    if (!entry) {
      return;
    }
    clearTimeout(entry.timer);
    this.pending.delete(approvalId);

    const effNote =
      note ??
      (isTimeout
        ? 'approval timed out'
        : option === 'always'
          ? 'allowed-always'
          : null);

    // Publicamos la resolucion en todos los casos (respuesta, timeout,
    // cancelacion): la tarjeta de aprobacion del cliente no queda colgada.
    this.emit('approval.resolved', {
      run_id: entry.runId,
      id: approvalId,
      approved: approved === true,
      note: effNote,
    });

    entry.resolve(
      approved
        ? {
            behavior: 'allow',
            decisionClassification: option === 'always' ? 'user_permanent' : 'user_temporary',
          }
        : {
            behavior: 'deny',
            message: isTimeout
              ? (note ?? 'approval timed out: the agent proceeds without the tool')
              : (note ?? 'denied by the user'),
            decisionClassification: 'user_reject',
          },
    );
  }

  /** Hay aprobaciones pendientes? (para el sondeo get_state). */
  get hasPending() {
    return this.pending.size > 0;
  }

  /**
   * Resuelve COMO TIMEOUT todo lo pendiente (shut down ordenado).
   * @param {string} note
   */
  resolveAll(note) {
    for (const id of [...this.pending.keys()]) {
      this._settle(id, false, note, true);
    }
  }
}

/**
 * Texto legible para el prompt de la aprobacion: herramienta + resumen
 * corto del input (el cliente lo muestra en la tarjeta de aprobacion).
 *
 * @param {string} toolName
 * @param {Record<string, unknown>} input
 */
function describeToolUse(toolName, input) {
  const parts = [`Tool ${toolName} wants to:`];
  if (input && typeof input === 'object') {
    for (const [k, v] of Object.entries(input)) {
      let s = typeof v === 'string' ? v : JSON.stringify(v);
      if (s.length > 160) {
        s = s.slice(0, 157) + '…';
      }
      parts.push(`${k}: ${s}`);
    }
  }
  return parts.join('\n');
}

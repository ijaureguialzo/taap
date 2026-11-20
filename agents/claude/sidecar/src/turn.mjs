// Ejecucion de un turno (diseño D1/D2/D3).
//
// Un `prompt` = un `query()` del Claude Agent SDK. La version fijada del SDK
// spawnea el CLI por query: cada query es un proceso nuevo que se reanuda
// con `resume` (spike 1.1) — por eso el cambio de config (re-leido de
// /config/claude.json en cada turno) se aplica al siguiente turno SIN
// reiniciar el contenedor y sin perder la sesion.

import { randomUUID } from 'node:crypto';
import { encodeEvent } from './protocol.mjs';
import { mapSdkMessage } from './mapping.mjs';
import { effectivePermissions } from './session.mjs';

/**
 * @typedef {object} TurnDeps
 * @property {(opts: object) => AsyncIterable<object>} query la función query
 *   del SDK (inyectable para tests: los tests la falsifican).
 * @property {(line: string) => void} emit volcar una linea JSONL a stdout
 * @property {import('./approvals.mjs').ApprovalGate} approvals
 * @property {Record<string, unknown>} cfg config /config/claude.json
 * @property {{session_id: string|null, always_allowed: string[]}} session
 * @property {(session: object) => void} saveSession persistir el estado
 * @property {() => Date} [now]
 */

/**
 * Corre un turno completo y emite todos sus eventos.
 *
 * @param {TurnDeps} deps
 * @param {string} message mensaje del usuario
 * @returns {Promise<{runId: string, ok: boolean, sessionId: string|null, error?: string}>}
 */
export async function runTurn(deps, message) {
  const { query, emit, approvals, cfg, session, saveSession, now = () => new Date() } = deps;
  const runId = randomUUID();
  const push = (type, payload) => emit(encodeEvent(type, payload, now()));

  const ctx = {
    runId,
    sessionId: null,
    emit: push,
    now,
    toolNames: new Map(),
  };

  push('run.start', { run_id: runId });
  push('status.change', { status: 'working' });

  // `canUseTool` con el set «permitir siempre»: lo que ya se permitió
  // siempre se auto-aproba en el turno en curso (la regla ask gestionada
  // de lo contrario forzaría el prompt de nuevo dentro del mismo proceso).
  const always = new Set(session.always_allowed);
  const canUseTool = async (toolName, input, options) => {
    if (always.has(toolName) || (toolName.startsWith('mcp__') && always.has('mcp__*'))) {
      return { behavior: 'allow', updatedInput: input, decisionClassification: 'user_permanent' };
    }
    return approvals.canUseTool(toolName, input, options, { runId });
  };

  const perms = effectivePermissions(cfg.permissions, session.always_allowed);
  const options = {
    model: cfg.model,
    cwd: cfg.workdir,
    // El env es el del child del CLI (el SDK lo usa tal cual; ANTHROPIC_*
    // es como el CLI alcanza al Model Proxy).
    env: {
      ...process.env,
      ...(cfg.anthropic_base_url ? { ANTHROPIC_BASE_URL: cfg.anthropic_base_url } : {}),
      ...(cfg.anthropic_api_key ? { ANTHROPIC_API_KEY: cfg.anthropic_api_key } : {}),
      // Aciota los reintentos de peticiones fallidas (p. ej. proveedor caido):
      // sin ella el CLI reintenta varios minutos y el error TAAP tardaria en
      // llegar. null = comportamiento por defecto del CLI.
      ...(cfg.max_retries !== null ? { CLAUDE_CODE_MAX_RETRIES: String(cfg.max_retries) } : {}),
    },
    // Preset claude-code + append del prompt de sistema del agente (se
    // conserva el comportamiento nativo del runtime; design D6).
    ...(cfg.system_prompt
      ? { systemPrompt: { type: 'preset', preset: 'claude_code', append: cfg.system_prompt } }
      : {}),
    // MCP por la puerta HTTP del orquestador (como Pi: los servidores los
    // alcanza vía el orquestador, con el token del agente).
    ...(Object.keys(cfg.mcp_servers).length > 0
      ? { mcpServers: cfg.mcp_servers, mcpServersDelivery: 'initialize' }
      : {}),
    // Política de permisos (design D3) como settings gestionadas: `ask`
    // fuerza el prompt (canUseTool) incluso para comandos que la heuristica
    // interna del CLI consideraría seguros.
    settings: { permissions: { ask: perms.ask, allow: perms.allow } },
    permissionPrompts: 'host',
    // Sesión (design D2): reanudar si existe.
    ...(session.session_id ? { resume: session.session_id } : {}),
    canUseTool,
  };

  /** @type {object|undefined} */
  let lastResult;
  let sdkError = null;

  try {
    const stream = query({ prompt: message, options });
    for await (const msg of stream) {
      // Registrar nombres de herramientas para correlacionar tool_result.
      if (msg?.type === 'assistant' && Array.isArray(msg.message?.content)) {
        for (const block of msg.message.content) {
          if (block?.type === 'tool_use' && block.id) {
            ctx.toolNames.set(block.id, String(block.name ?? 'tool'));
          }
        }
      }

      for (const line of mapSdkMessage(msg, ctx)) {
        emit(line);
      }

      if (msg?.type === 'result') {
        lastResult = msg;
      }
    }
  } catch (e) {
    sdkError = e;
  } finally {
    // El SDK deja la query cerrada al terminar el result; si el proceso del
    // CLI muere sin result (crash, OOM) el stream se agota igual.
    approvals.resolveAll('turn ended');
  }

  // ---- cierre de corrida --------------------------------------------
  const newSessionId = lastResult?.session_id ?? session.session_id;

  if (sdkError) {
    push('error', { message: `Claude: ${sdkError.message}` });
    push('run.end', { run_id: runId, ok: false });
    push('status.change', { status: 'error' });
  } else if (!lastResult) {
    // El stream termino sin result (no deberia pasar; se deja rastro).
    push('error', { message: 'Claude: el turno termino sin resultado' });
    push('run.end', { run_id: runId, ok: false });
    push('status.change', { status: 'error' });
  }

  // ---- persistir estado de sesion (design D2) ------------------------
  if (newSessionId && newSessionId !== session.session_id) {
    session.session_id = newSessionId;
    saveSession(session);
  }

  return {
    runId,
    ok: Boolean(lastResult) && !lastResult.is_error && !sdkError,
    sessionId: session.session_id,
    error: sdkError?.message ?? (lastResult?.is_error ? lastResult.result ?? null : null),
  };
}


// TAAP sidecar Claude Code — proceso principal del contenedor (design D1).
//
// Lee comandos JSONL (protocolo TAAP v1) del FIFO /rpc/in (IPC de archivos
// de v1.0: el bridge escribe en $dir/in y un coproceso lo entrega al FIFO)
// y emite eventos JSONL por stdout (el coproceso `docker logs -f` del
// bridge los volca a $dir/out).
//
// Comandos:
//   prompt             -> nuevo turno (query del SDK con resume)
//   approval_response  -> resuelve una aprobacion pendiente (canUseTool)
//   get_state          -> sondeo: emite status ready (o waiting si hay
//                         aprobaciones pendientes)
//
// La configuracion del agente (/config/claude.json, renderizada por el
// orquestador) se RE-LEE en cada turno: el cambio de modelo/MCP/prompt/
// permisos se aplica al siguiente turno sin reinicio (config_apply:
// next-turn) y la sesion sobrevive (design D2/D6).

import fs from 'node:fs';
import process from 'node:process';
import { query as realQuery } from '@anthropic-ai/claude-agent-sdk';

import { PROTOCOL_VERSION, decodeCommand, encodeEvent } from './protocol.mjs';
import { DEFAULTS, loadConfig } from './config.mjs';
import { loadSession, saveSession } from './session.mjs';
import { ApprovalGate } from './approvals.mjs';
import { runTurn } from './turn.mjs';

/**
 * @param {{stdin?: NodeJS.ReadableStream, write?: (line: string) => void,
 *          query?: (opts: object) => AsyncIterable<object>,
 *          configPath?: string, stateFile?: string,
 *          timeoutMs?: number, exit?: (code: number) => never}} [deps]
 *   inyectables para tests (por defecto: FIFO real, SDK real, rutas del
 *   contenedor)
 */
export function createSidecar(deps = {}) {
  const stdin = deps.stdin ?? process.stdin;
  const write = deps.write ?? ((line) => process.stdout.write(line + '\n'));
  const query = deps.query ?? realQuery;
  const configPath = deps.configPath ?? DEFAULTS.config_file;
  let stateFile = deps.stateFile ?? DEFAULTS.state_file;
  const timeoutMs = deps.timeoutMs ?? null;
  const exit = deps.exit ?? ((code) => process.exit(code));

  const emit = (line) => write(line);
  const emitEvent = (type, payload, at = new Date()) => emit(encodeEvent(type, payload, at));

  let approvals = null;
  let cfg = null;
  let session = null;
  /** cola FIFO de prompts (mientras hay un turno en vuelo) */
  const promptQueue = [];
  let turnInFlight = null;

  function reloadConfig() {
    cfg = loadConfig(configPath);
    stateFile = deps.stateFile ?? cfg.state_file;
    if (!approvals) {
      approvals = new ApprovalGate({
        emit: emitEvent,
        timeoutMs: timeoutMs ?? cfg.approval_timeout_seconds * 1000,
      });
    } else if (timeoutMs === null) {
      // El timeout se relee por si cambio en la config (siguiente request
      // lo usara; los ya pendientes conservan el de su creacion).
      approvals.timeoutMs = cfg.approval_timeout_seconds * 1000;
    }
    session = loadSession(stateFile);
  }

  async function handlePrompt(cmd) {
    // Siempre por la cola: si no hay turno en vuelo, `nextPrompt` lo procesa
    // de inmediato; si lo hay, espera su sitio (FIFO, design D1).
    promptQueue.push(cmd);
    if (!turnInFlight) {
      await nextPrompt();
    }
  }

  async function nextPrompt() {
    const cmd = promptQueue.shift();
    if (!cmd) {
      turnInFlight = null;
      return;
    }
    turnInFlight = true;
    try {
      reloadConfig();
      const res = await runTurn(
        { query, emit, approvals, cfg, session, saveSession: (s) => saveSession(stateFile, s) },
        cmd.message,
      );
      // «Permitir siempre» acumulado en el turno: persistir para que
      // sobreviva a reinicios y se aplique al siguiente query.
      if (session.session_id !== res.sessionId) {
        session.session_id = res.sessionId;
      }
      saveSession(stateFile, session);
    } catch (e) {
      emitEvent('error', { message: `sidecar: ${e.message}` });
      emitEvent('status.change', { status: 'error' });
    } finally {
      turnInFlight = false;
    }
    // siguiente en cola (follow-up enviado mientras el agente trabajaba)
    if (promptQueue.length > 0) {
      await nextPrompt();
    }
  }

  function handleApprovalResponse(cmd) {
    if (!approvals) {
      reloadConfig();
    }
    const r = approvals?.respond(cmd.approval_id, cmd.approved, cmd.option, cmd.note);
    if (r && r.option === 'always' && !session.always_allowed.includes(r.tool)) {
      session.always_allowed.push(r.tool);
      try {
        saveSession(stateFile, session);
      } catch (e) {
        emitEvent('error', { message: `sidecar: no se pudo persistir «permitir siempre»: ${e.message}` });
      }
    }
    // `approval.resolved` lo emite la gate (el pipeline lo consume).
  }

  function handleGetState(cmd) {
    if (!approvals) {
      reloadConfig();
    }
    const status = approvals?.hasPending ? 'waiting-approval' : 'ready';
    emitEvent('status.change', { status });
  }

  function dispatch(line) {
    const cmd = decodeCommand(line);
    if (!cmd) {
      // Linea malformada: no crashear; el orquestador ve el error en el chat.
      emitEvent('error', { message: `comando no válido en el protocolo v${PROTOCOL_VERSION}` });
      return;
    }
    switch (cmd.type) {
      case 'prompt':
        void handlePrompt(cmd);
        break;
      case 'approval_response':
        handleApprovalResponse(cmd);
        break;
      case 'get_state':
        handleGetState(cmd);
        break;
      default:
        emitEvent('error', { message: `comando desconocido: ${cmd.type}` });
    }
  }

  /**
   * Lee el stream de entrada line a lineas (JSONL).
   * @param {NodeJS.ReadableStream} stream
   */
  function startReader(stream) {
    let buffer = '';
    stream.setEncoding('utf8');
    stream.on('data', (chunk) => {
      buffer += chunk;
      let pos;
      while ((pos = buffer.indexOf('\n')) !== -1) {
        const line = buffer.slice(0, pos).trim();
        buffer = buffer.slice(pos + 1);
        if (line !== '') {
          dispatch(line);
        }
      }
    });
    stream.on('close', () => {
      // El holder del FIFO mantiene el lado de escritura abierto; un close
      // aqui significa que el bridge solto el IPC: salir limpio (el
      // entrypoint/contenedor re-arranca).
      process.exitCode = 0;
    });
  }

  // ---- arranque ------------------------------------------------------
  try {
    reloadConfig();
  } catch (e) {
    emitEvent('error', { message: `config: ${e.message}` });
  }

  // Anuncio de arranque (el bridge lo correlaciona con el sondeo).
  emitEvent('status.change', { status: 'starting' });

  if (stdin && typeof stdin.on === 'function') {
    startReader(stdin);
  }

  return {
    /** @internal para tests */
    dispatch,
    queue: promptQueue,
    state: () => ({ cfg, session, approvals }),
    stop() {
      approvals?.resolveAll('sidecar shutting down');
      stdin?.removeAllListeners?.();
    },
  };
}

/**
 * Arranque real: solo si se ejecuta directamente (no al ser importado por
 * los tests).
 */
const isMain = process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href;
if (isMain) {
  createSidecar();
}

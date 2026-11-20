// Plano de configuración del agente Claude (design D6).
//
// El orquestador renderiza /config/claude.json; el sidecar lo RE-LEE en cada
// turno (`config_apply: next-turn`): el cambio de modelo/MCP/prompt/permisos
// se aplica en el siguiente `query()` sin reiniciar el contenedor y sin
// perder la sesion (a diferencia de Pi, donde el modelo arrastra la sesion).

import fs from 'node:fs';

/**
 * Valores por defecto (design D3): preguntar para las herramientas con
 * efectos y las MCP; auto-permitir lectura para no spammear aprobaciones.
 */
export const DEFAULTS = Object.freeze({
  model: 'claude-sonnet-4-5',
  anthropic_base_url: null,
  anthropic_api_key: null,
  system_prompt: null,
  mcp_servers: {},
  permissions: {
    ask: ['Bash', 'Edit', 'Write', 'WebFetch', 'mcp__*'],
    allow: ['Read', 'Grep', 'Glob', 'Skill', 'TodoWrite', 'Task'],
  },
  approval_timeout_seconds: 1800, // 30 min: sin respuesta -> denegado (D3)
  max_retries: null, // null = default del CLI; el orquestador puede acotarla
  workdir: '/workspace',
  state_file: '/data/session-state.json',
  config_file: '/config/claude.json',
});

/**
 * @param {string} [path] por defecto /config/claude.json
 * @param {typeof DEFAULTS} [defaults]
 * @returns {Record<string, unknown>} config normalizada
 * @throws {Error} si el fichero existe pero no es JSON valido
 */
export function loadConfig(path = DEFAULTS.config_file, defaults = DEFAULTS) {
  let raw = {};
  if (fs.existsSync(path)) {
    raw = JSON.parse(fs.readFileSync(path, 'utf8'));
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
      throw new Error(`config inválida (no es un objeto): ${path}`);
    }
  }

  const permissions = { ...defaults.permissions, ...asObject(raw.permissions) };
  return {
    model: stringOr(raw.model, defaults.model),
    anthropic_base_url: nullableString(raw.anthropic_base_url, defaults.anthropic_base_url),
    anthropic_api_key: nullableString(raw.anthropic_api_key, defaults.anthropic_api_key),
    system_prompt: nullableString(raw.system_prompt, defaults.system_prompt),
    mcp_servers: asObject(raw.mcp_servers, defaults.mcp_servers),
    permissions: {
      ask: stringArray(permissions.ask),
      allow: stringArray(permissions.allow),
    },
    approval_timeout_seconds: positiveNumber(raw.approval_timeout_seconds, defaults.approval_timeout_seconds),
    max_retries: nonNegativeInt(raw.max_retries, defaults.max_retries),
    workdir: stringOr(raw.workdir, defaults.workdir),
    state_file: stringOr(raw.state_file, defaults.state_file),
  };
}

function asObject(v, fallback) {
  return v && typeof v === 'object' && !Array.isArray(v) ? v : fallback;
}

function stringOr(v, fallback) {
  return typeof v === 'string' && v !== '' ? v : fallback;
}

function nullableString(v, fallback) {
  return typeof v === 'string' ? v : fallback;
}

function stringArray(v) {
  return Array.isArray(v) ? v.filter((x) => typeof x === 'string') : [];
}

function positiveNumber(v, fallback) {
  return typeof v === 'number' && Number.isFinite(v) && v > 0 ? v : fallback;
}

function nonNegativeInt(v, fallback) {
  if (v === null || v === undefined) {
    return fallback;
  }
  return typeof v === 'number' && Number.isInteger(v) && v >= 0 ? v : fallback;
}

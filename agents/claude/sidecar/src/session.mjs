// Estado de sesion del sidecar (design D2).
//
// El SDK devuelve el `session_id` vigente en el mensaje `result` de cada
// turno. Lo persistimos en el volumen de datos (fichero PROPIO del sidecar:
// el ConfigRenderer nunca lo toca) y lo pasamos como `resume` en cada
// `query()`. Tras un reinicio del contenedor, el nuevo sidecar lo lee y
// reanuda con el contexto intacto.
//
// Tambien vive aqui el set de herramientas «permitir siempre» (D3): cuando
// el usuario elige permitir siempre, el sidecar lo acumula y lo aplica a las
// listas de permisos del siguiente turno (la regla `ask` de settings
// gestionadas no se deja desbancar por updates de reglas de sesion).

import fs from 'node:fs';
import path from 'node:path';

const EMPTY = Object.freeze({ session_id: null, always_allowed: [] });

/** @returns {{session_id: string|null, always_allowed: string[]}} */
export function loadSession(stateFile) {
  try {
    const raw = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
    if (!raw || typeof raw !== 'object') {
      return { ...EMPTY };
    }
    return {
      session_id: typeof raw.session_id === 'string' && raw.session_id !== '' ? raw.session_id : null,
      always_allowed: Array.isArray(raw.always_allowed)
        ? raw.always_allowed.filter((x) => typeof x === 'string')
        : [],
    };
  } catch {
    return { ...EMPTY };
  }
}

/**
 * Guarda el estado (escritura atomica: tmp + rename, sobrevive a un crash a
 * medias de escritura).
 *
 * @param {{session_id: string|null, always_allowed: string[]}} state
 */
export function saveSession(stateFile, state) {
  fs.mkdirSync(path.dirname(stateFile), { recursive: true });
  const tmp = `${stateFile}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify(state, null, 2) + '\n');
  fs.renameSync(tmp, stateFile);
}

/**
 * Aplica las listas de permisos con el set «permitir siempre»: las
 * herramientas en `always` pasan de `ask` a `allow` (una regla `ask` en
 * settings gestionadas tiene precedencia sobre cualquier allow, asi que
 * hay que moverlas de lista).
 *
 * @param {{ask: string[], allow: string[]}} permissions
 * @param {string[]} alwaysAllowed
 * @returns {{ask: string[], allow: string[]}}
 */
export function effectivePermissions(permissions, alwaysAllowed) {
  const set = new Set(alwaysAllowed);
  const ask = permissions.ask.filter((t) => !set.has(t));
  const allow = [...new Set([...permissions.allow, ...alwaysAllowed])];
  return { ask, allow };
}

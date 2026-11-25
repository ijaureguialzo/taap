/**
 * Politica de auto-aprobacion de comandos bash sobre el FS compartido
 * (shared-filesystem 6.4).
 *
 * El orquestador exporta en agent.env:
 *   TAAP_FS_RW  paths montados read-write  (coma-separados: /fs/ana/proyectos)
 *   TAAP_FS_RO  paths montados read-only
 *
 * Decision (conservadora: ante la duda, el flujo de aprobacion de siempre):
 *   - sin paths absolutos en el comando        -> no auto (flow actual)
 *   - algun path fuera de lo montado            -> no auto
 *   - ops de escritura y algun path en un ro   -> no auto
 *   - resto (lectura en ro/rw, escritura en rw) -> auto (sin dialog)
 *
 * Es un heuristico sobre el texto del comando, no un parser completo:
 * un path relativo o una redireccion inusual caen al flow de aprobacion.
 *
 * Fichero puro (sin imports del SDK) para poder testearlo con node
 * directo (type stripping) sin depender de la instalacion del paquete.
 */

/**
 * Paths absolutos mencionados en el comando. Solo cuenta lo que esta en
 * frontera de token (inicio, espacio, ; | & ( = >, o comilla de apertura):
 * asi el '/' interior de expresiones tipo `sed 's/a/b/'` no es un path.
 */
export function extractPaths(command: string): string[] {
	const out = new Set<string>();
	// 1) path entre comillas (puede llevar espacios):  "/fs/ana/mis docs/x"
	// 2) path sin comillas (tokens normales):         /fs/ana/proyectos/x
	const re = /(^|[\s;|&(=])(?:(["'])(\/[A-Za-z0-9._\-\s]+(?:\/[A-Za-z0-9.*_\-\s]+)*)\2|(["']?)(\/[A-Za-z0-9._\-]+(?:\/[A-Za-z0-9.*_\-]+)*)\4)/g;
	let m: RegExpExecArray | null;
	while ((m = re.exec(command)) !== null) {
		const raw = m[3] ?? m[5];
		if (raw === undefined) {
			continue;
		}
		out.add(raw.replace(/\/+$/, "") || "/");
	}
	return [...out];
}

/** ¿El comando escribe en algun lado? (redirecciones + verbos tipicos) */
export function hasWriteOp(command: string): boolean {
	// ">" como redireccion (2>&1 no: la ">" va antes del "&")
	if (/(^|[\s;|&(])>/.test(command) || />>/.test(command)) {
		return true;
	}
	if (/\b(rm|mkdir|touch|mv|cp|ln|tee|dd|chmod|chown|install|truncate|shuf)\b/.test(command)) {
		return true;
	}
	// sed -i / sed --in-place
	if (/sed\b[^|;&]*(-[a-zA-Z]*i[a-zA-Z]*|--in-place)/.test(command)) {
		return true;
	}
	return false;
}

function underAny(p: string, roots: string[]): boolean {
	return roots.some((r) => r === p || p.startsWith(r + "/"));
}

/**
 * 'auto' = el comando toca SOLO paths montados y respeta los modos
 * (lecturas en ro y rw, escrituras solo en rw) -> sin approval.request.
 * null  = mantener el flow de aprobacion actual (dialog).
 */
export function fsPolicy(command: string, rw: string[], ro: string[]): "auto" | null {
	const paths = extractPaths(command);
	if (paths.length === 0) {
		return null;
	}
	const inFs = paths.every((p) => underAny(p, rw) || underAny(p, ro));
	if (!inFs) {
		return null;
	}
	if (hasWriteOp(command) && !paths.every((p) => underAny(p, rw))) {
		return null;
	}
	return "auto";
}

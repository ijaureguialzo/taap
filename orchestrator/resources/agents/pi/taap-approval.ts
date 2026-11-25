/**
 * Taap approval gate (pi extension).
 *
 * Pide confirmacion al usuario (via `ui.confirm` -> extension_ui_request
 * del modo RPC) antes de ejecutar cualquier comando bash. El orquestador
 * la mapea a un approval.request y la respuesta del usuario (aprobado /
 * denegado) vuelve como extension_ui_response.
 *
 * Gate:
 *   TAAP_APPROVE_PATTERNS  coma-separado de substrings (default: "*" = todo)
 *   TAAP_APPROVE_TIMEOUT   ms maximo esperando respuesta (default: 300000)
 *
 * Auto-aprobacion del FS compartido (6.4): el orquestador exporta los paths
 * montados (TAAP_FS_RW / TAAP_FS_RO); un comando que solo toca esos paths y
 * respeta los modos (lecturas en ro, escrituras solo en rw) se ejecuta sin
 * dialog (taap-fs-policy.ts). El resto mantiene el dialog de aprobacion.
 *
 * Si el usuario deniega, la tool queda bloqueada con una razon que el
 * modelo ve en la conversacion.
 */
import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";
import { isToolCallEventType } from "@earendil-works/pi-coding-agent";
import { fsPolicy } from "../taap-fs-policy.ts";

const patterns = (process.env.TAAP_APPROVE_PATTERNS ?? "*")
	.split(",")
	.map((s) => s.trim())
	.filter((s) => s.length > 0);

const timeout = Number(process.env.TAAP_APPROVE_TIMEOUT ?? 300_000);

// Paths del FS compartido montados (coma-separados, canonicos desde /fs).
const fsRw = (process.env.TAAP_FS_RW ?? "")
	.split(",")
	.map((s) => s.trim())
	.filter((s) => s.length > 0);
const fsRo = (process.env.TAAP_FS_RO ?? "")
	.split(",")
	.map((s) => s.trim())
	.filter((s) => s.length > 0);

function isGated(command: string): boolean {
	if (patterns.includes("*")) {
		return true;
	}
	return patterns.some((p) => command.includes(p));
}

export default function (pi: ExtensionAPI) {
	pi.on("tool_call", async (event, ctx) => {
		if (!isToolCallEventType("bash", event) || !ctx.hasUI) {
			return;
		}

		const command = String(event.input.command ?? "");
		if (!isGated(command)) {
			return;
		}

		// Auto-aprobacion: el comando queda dentro del FS montado y respeta
		// los modos (6.4). Sin montados, fsPolicy devuelve null y todo pasa
		// por el dialog, como antes.
		if (fsPolicy(command, fsRw, fsRo) === "auto") {
			return;
		}

		const confirmed = await ctx.ui.confirm(
			"Ejecutar comando",
			`${command}\n\n¿Permitir la ejecución?`,
			{ timeout },
		);

		if (!confirmed) {
			return { block: true, reason: "El usuario denegó la ejecución del comando." };
		}
	});
}

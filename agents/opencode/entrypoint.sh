#!/bin/sh
# Entrypoint del agente OpenCode (TAAP v1.1).
#
# `opencode serve` como PID 1. El orquestador:
#   - manda COMANDOS por HTTP (prompt_async, permisos, sesion) al puerto
#   - recibe EVENTOS por el stream /event (lo cola un coproceso `curl -N`
#     en la IPC de ficheros; el adapter lo drena)
#
# Preparacion del entorno (los dirs son volúmenes del orquestador):
#   - copia el pre-warm de node_modules a /config/opencode/ SI el dir no
#     existe todavia (no pisa la config renderizada ni un warm existente)
#   - crea los XDG dirs bajo los binds
#
# Sin FIFO: este modo (`api-events`) no usa el stdio del contenedor.

set -eu

# Pre-warm de la app dir (el primer arranque instala ~60 MB de deps de
# plugin; los agentes suelen no tener Internet -> se copia de la imagen).
WARM=/opt/warm/opencode
APPDIR="${XDG_CONFIG_HOME:-/config}/opencode"
if [ -d "$WARM" ] && [ ! -d "$APPDIR/node_modules" ]; then
    mkdir -p "$APPDIR"
    cp -rn "$WARM/." "$APPDIR/" 2>/dev/null || true
fi

# Dirs XDG en los volúmenes (opencode crea lo que necesita; se asegura la
# base para que un arranque temprano no falle por permisos).
mkdir -p "${XDG_DATA_HOME:-/data}" "${XDG_CONFIG_HOME:-/config}/opencode" "$HOME"

port="${OPENCODE_PORT:-4096}"

exec opencode serve --port "$port" --hostname 0.0.0.0 --pure

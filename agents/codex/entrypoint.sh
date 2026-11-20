#!/bin/bash
# PID 1 del contenedor del agente Codex: el binario `codex app-server`
# (Rust, JSON-RPC por stdio) de larga vida. La sesion (thread) persiste en
# /data = CODEX_HOME (rollouts, state_*.sqlite, rules, ...).
#
# La configuracion llega por dos planos en /config (el orquestador los
# escribe):
#   codex.env       variables shell (TAAP_API_KEY, CODEX_HOME)
#   codex-args.env  un ARGUMENTO por linea: los -c key=value del binary
#
# Entrada RPC por FIFO (identico al entrypoint de Pi): el app-server lee de
# /rpc/in en vez de del stdin del contenedor. Un proceso residente mantiene
# abierto el lado escritura para que NUNCA llegue un EOF (un EOF cerraria el
# app-server). El orquestador escribe con:
#   docker exec -i <contenedor> sh -c 'printf ... > /rpc/in'
set -e
# `set -a`: las variables del plano deben exportarse al binary (el app-server
# las lee del ENTORNO: CODEX_HOME, TAAP_API_KEY; un source sin export las
# dejaria solo como variables de shell).
[ -f /config/codex.env ] && { set -a; . /config/codex.env; set +a; }

# Los -c de la config (model, provider, approval, sandbox, MCP)
ARGS=()
if [ -f /config/codex-args.env ]; then
    while IFS= read -r arg; do
        [ -n "$arg" ] && ARGS+=("$arg")
    done < /config/codex-args.env
fi

mkdir -p /rpc
rm -f /rpc/in
mkfifo /rpc/in
( exec 3>/rpc/in; tail -f /dev/null >&3 ) &

# --listen stdio:// es el default; se pone explicito por claridad.
exec /usr/local/bin/codex-app-server --listen stdio:// "${ARGS[@]}" < /rpc/in

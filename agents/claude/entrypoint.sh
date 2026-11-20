#!/bin/sh
# PID 1 del contenedor del agente Claude (patrón agents/pi/entrypoint.sh):
# el sidecar Node (Claude Agent SDK) lee el FIFO /rpc/in y emite eventos
# JSONL por stdout.
#
# Un proceso residente mantiene abierto el lado de escritura del FIFO para
# que NUNCA llegue un EOF (un EOF cerraria el lector y mataria el sidecar).
# El orquestador escribe comandos con:
#   docker exec -i <contenedor> sh -c 'printf ... > /rpc/in'
#
# La configuracion llega en /config/claude.json (la RE-LEE el sidecar en
# cada turno; un cambio se aplica al siguiente turno sin reinicio).
set -e

mkdir -p /rpc
rm -f /rpc/in
mkfifo /rpc/in
( exec 3>/rpc/in; tail -f /dev/null >&3 ) &

exec node /opt/sidecar/src/index.mjs < /rpc/in

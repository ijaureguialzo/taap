#!/bin/sh
# PID 1 del contenedor del agente Pi: un bucle `pi --mode rpc` de larga vida
# (una sesion por agente, persistente en el volumen /home/agent/.pi/agent).
#
# La configuracion llega en /config/agent.env como variables shell (el
# orquestador lo escribe con valores ya exportables):
#   SESSION_ID      id de la sesion (reanudable; por defecto: marca de tiempo)
#   PROVIDER        provider registrado en models.json (por defecto: taap)
#   MODEL           id de modelo
#   API_KEY         token de agente del Model Proxy
#   SYSTEM_PROMPT   prompt de sistema opcional
set -e
[ -f /config/agent.env ] && . /config/agent.env

SESSION_ID="${SESSION_ID:-$(date +%s)}"
set -- --mode rpc \
       --session-dir /home/agent/.pi/agent/sessions \
       --session-id "$SESSION_ID"
[ -n "${MODEL:-}" ] && set -- "$@" --provider "${PROVIDER:-taap}" --model "$MODEL"
[ -n "${API_KEY:-}" ] && set -- "$@" --api-key "$API_KEY"
[ -n "${SYSTEM_PROMPT:-}" ] && set -- "$@" --system-prompt "$SYSTEM_PROMPT"

# Entrada RPC por FIFO: pi lee de /rpc/in en vez de del stdin del
# contenedor. Un proceso residente mantiene abierto el lado escritura para
# que NUNCA llegue un EOF (un EOF cerraria pi). El orquestador escribe
# con:  docker exec -i <contenedor> sh -c 'printf ... > /rpc/in'
mkdir -p /rpc
rm -f /rpc/in
mkfifo /rpc/in
( exec 3>/rpc/in; tail -f /dev/null >&3 ) &

exec pi "$@" < /rpc/in

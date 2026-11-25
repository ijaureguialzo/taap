#!/bin/sh
# PID 1 del contenedor del agente Pi: un bucle `pi --mode rpc` de larga vida
# (una sesion por agente, persistente en el volumen /home/agent/.pi/agent).
#
# La configuracion llega en /config/agent.env como variables shell (el
# orquestador lo escribe con valores ya exportables):
#   SESSION_ID      id de la sesion (reanudable; por defecto: marca de tiempo)
#   PROVIDER        provider registrado en models.json (por defecto: splash)
#   MODEL           id de modelo
#   API_KEY         token de agente del Model Proxy
#   SYSTEM_PROMPT   prompt de sistema opcional
#
# El provider se registra en ~/.pi/agent/models.json para que pi lo resuelva
# (no se pasa por --provider: pi solo conoce providers integrados como
# openai, anthropic, ollama; los custom viven en models.json).
#
# La config (models.json, mcp.json) se monta en ~/.pi/agent/ (bind mount
# writable desde el host). Pi lee de ~/.pi/agent/.
# La extension taap-approval se copia a ~/.pi/agent/ (tambien writable).
set -e
[ -f /config/agent.env ] && { set -a; . /config/agent.env; set +a; }

# Asegurar que los subdirectorios de ~/.pi/agent/ existen. El bind mount
# reemplaza el contenido de ~/.pi/agent/ pero no garantiza que todos los
# subdirectorios existan (sessions y extensions pueden no estar en el host).
mkdir -p "$HOME/.pi/agent/sessions"
mkdir -p "$HOME/.pi/agent/extensions"

# Copiar extension taap-approval si existe en el config mount
if [ -f /config/taap-approval.ts ]; then
    cp /config/taap-approval.ts "$HOME/.pi/agent/extensions/taap-approval.ts"
fi

SESSION_ID="${SESSION_ID:-$(date +%s)}"
set -- --mode rpc \
       --session-dir /home/agent/.pi/agent/sessions \
       --session-id "$SESSION_ID"
[ -n "${MODEL:-}" ] && set -- "$@" --model "taap/${MODEL}"
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

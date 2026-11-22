# Imagen del agente Pi.
#
# El orquestador monta:
#   - taap-agent-<id>-data      -> /home/agent/.pi/agent (config, sessions, mcp.json)
#   - taap-agent-<id>-workspace -> /workspace (ficheros que el agente crea/edita)
#   - taap-agent-<id>-config    -> /config (agent.env renderizado por el orquestador)
#
# El contenedor ARRANCA con `pi --mode rpc` como proceso principal (PID 1):
# una sesion de agente viva 24/7. El orquestador se comunica con ella via
# `docker attach` (JSONL por stdio). Al actualizar la configuracion
# (modelo, prompt, MCP) el orquestador re-esecribe /config/agent.env y hace
# `docker restart`: pi se re-inicia y reanuda la misma sesion (--session-id).
FROM node:24-bookworm

ARG PI_VERSION=1.6.0

# python3 para servers MCP stdio; curl para healthchecks
RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 curl ca-certificates \
 && rm -rf /var/lib/apt/lists/*

RUN npm install -g @earendil-works/pi-coding-agent@${PI_VERSION}

RUN useradd -m -u 1001 -d /home/agent -s /bin/sh agent \
 && mkdir -p /home/agent/.pi/agent/sessions /workspace /config \
 && chown -R agent:agent /home/agent/.pi /workspace /config

ENV HOME=/home/agent
WORKDIR /workspace

COPY entrypoint.sh /usr/local/bin/agent-entrypoint
RUN chmod +x /usr/local/bin/agent-entrypoint && chown agent:agent /usr/local/bin/agent-entrypoint

RUN mkdir -p /rpc && chown agent:agent /rpc
USER agent
ENTRYPOINT ["/usr/local/bin/agent-entrypoint"]

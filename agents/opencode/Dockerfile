# Imagen del agente OpenCode (TAAP v1.1, add-opencode-runtime).
#
# El orquestador monta:
#   - taap-agent-<id>-data      -> /data (XDG_DATA_HOME: opencode.db con las
#                                  sesiones; sobrevive reinicios)
#   - taap-agent-<id>-workspace -> /workspace (cwd estable -> el "proyecto"
#                                  de opencode es estable entre reinicios)
#   - taap-agent-<id>-config    -> /config (XDG_CONFIG_HOME: opencode.json
#                                  renderizado por el orquestador en
#                                  /config/opencode/opencode.json)
#
# El contenedor ARRANCA con `opencode serve` (1.19.6) como PID 1: un server
# HTTP+ SSE vivo 24/7. El orquestador manda COMANDOS por HTTP (prompt_async,
# permisos, sesion) y recibe EVENTOS por el stream `/event` (un coproceso
# `curl -N` lo cola en la IPC de ficheros; modo `api-events`).
#
# Base node:24-bookworm: el paquete `opencode-ai` empaqueta un binario
# BUN con el SDK de proveedores (@ai-sdk/*) dentro (verificado en el spike:
# funciona con glibc de bookworm). node queda para servers MCP stdio
# (paridad Pi/Codex).
FROM node:24-bookworm

# Version fijada del spike 1.1 (un bump = rebuild de ESTA imagen; la
# normalizacion del stream vive en el orquestador: OpenCodeAdapter).
ARG OPENCODE_VERSION=1.19.6

RUN npm install -g opencode-ai@${OPENCODE_VERSION} --no-audit --no-fund \
 && npm cache clean --force \
 && opencode --version

# python3 para servers MCP stdio; curl/ca-certificates para el warmup y HTTPS
RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 curl ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# PRE-WARM de la app dir: opencode instala sus dependencias de plugin
# (~60 MB: @opencode-ai/plugin + effect) en $XDG_CONFIG_HOME/opencode/ al
# primer arranque. Los agentes SUELEN no tener Internet: se hace en el build
# y el entrypoint lo copia a /config/opencode/ (si no existe).
COPY warmup.sh /opt/warmup.sh
RUN sh /opt/warmup.sh && rm -f /opt/warmup.sh

RUN useradd -m -u 1001 -d /home/agent -s /bin/sh agent \
 && mkdir -p /data /workspace /config /opt/warm/opencode \
 && chown -R agent:agent /data /workspace /config /opt/warm

# Todo lo escribible de opencode vive en los volúmenes: HOME en /data (auth,
# caches) y XDG apuntando a los binds. /config/opencode/ recibe la copia del
# pre-warm (el renderer escribe /config/opencode/opencode.json).
ENV HOME=/data/home
ENV XDG_DATA_HOME=/data
ENV XDG_CONFIG_HOME=/config
ENV OPENCODE_PORT=4096

WORKDIR /workspace

COPY entrypoint.sh /usr/local/bin/agent-entrypoint
RUN chmod +x /usr/local/bin/agent-entrypoint && chown agent:agent /usr/local/bin/agent-entrypoint

RUN mkdir -p /data/home /rpc && chown -R agent:agent /data /rpc

USER agent
ENTRYPOINT ["/usr/local/bin/agent-entrypoint"]

# Imagen del agente Claude Code (TAAP v1.1).
#
# El orquestador monta:
#   - taap-agent-<id>-data      -> /data (CLAUDE_CONFIG_DIR: sesiones
#                                  transcripts, estado del sidecar; es
#                                  donde el CLI escribe TODO)
#   - taap-agent-<id>-workspace -> /workspace (cwd estable -> hash de
#                                  proyecto estable en los transcripts)
#   - taap-agent-<id>-config    -> /config (claude.json renderizado por el
#                                  orquestador; re-leido por turno)
#
# El contenedor ARRANCA con el sidecar Node como proceso principal (PID 1):
# una sesion de agente viva 24/7. El orquestador se comunica con ella por
# la IPC de archivos de v1.0 (FIFO /rpc/in + `docker logs -f` para el
# stdout). Al cambiar la configuracion (modelo, MCP, prompt) el orquestador
# re-escribe /config/claude.json y el cambio se aplica al SIGUIENTE turno,
# sin reinicio (la sesion la reanuda el SDK con `resume`).
FROM node:24-bookworm

# Versiones fijadas del spike 1.1 (design: un bump = rebuild de ESTA
# imagen, nunca del orquestador: la normalizacion vive en el sidecar).
ARG CLAUDE_CODE_VERSION=2.1.329
ARG AGENT_SDK_VERSION=0.3.329

# python3 para servers MCP stdio; curl para healthchecks (patrón Pi)
RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 curl ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# El sidecar + el SDK/CLI fijados por ARG (una sola instalacion: el CLI se
# spawnea por el SDK desde su propio node_modules; no hace falta
# instalarlo global). El package.json lleva la misma version: el ARG gana
# si se sobreescribe en el build.
COPY sidecar/package.json /opt/sidecar/package.json
RUN cd /opt/sidecar \
 && npm install --no-audit --no-fund --omit=dev \
      @anthropic-ai/claude-code@${CLAUDE_CODE_VERSION} \
      @anthropic-ai/claude-agent-sdk@${AGENT_SDK_VERSION}

COPY sidecar/src /opt/sidecar/src

# Usuario no root; /data es el area escribible (rootfs readonly compatible:
# CLAUDE_CONFIG_DIR apunta ahi, y el resto de escrituras van a /tmp tmpfs).
RUN useradd -m -u 1001 -d /home/agent -s /bin/sh agent \
 && mkdir -p /data/claude /workspace /config /opt/sidecar \
 && chown -R agent:agent /data /workspace /config /opt/sidecar

ENV HOME=/home/agent
# El CLI escribe TODO (transcripts, telemetry, settings) bajo este dir;
# con CLAUDE_CONFIG_DIR=/data/claude todo queda en el volumen persistente.
ENV CLAUDE_CONFIG_DIR=/data/claude

WORKDIR /workspace

COPY entrypoint.sh /usr/local/bin/agent-entrypoint
RUN chmod +x /usr/local/bin/agent-entrypoint && chown agent:agent /usr/local/bin/agent-entrypoint

RUN mkdir -p /rpc && chown agent:agent /rpc
USER agent
ENTRYPOINT ["/usr/local/bin/agent-entrypoint"]

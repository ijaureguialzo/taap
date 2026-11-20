# Imagen del agente Codex.
#
# El orquestador monta:
#   - taap-agent-<id>-data      -> /data (CODEX_HOME: threads, rollouts, state)
#   - taap-agent-<id>-workspace -> /workspace (ficheros que el agente crea)
#   - taap-agent-<id>-config    -> /config (codex.env + codex-args.env)
#
# El contenedor ARRANCA con `codex app-server` (binario Rust de la release
# de codex-cli) como proceso principal (PID 1): un app server JSON-RPC vivo
# 24/7. El orquestador se comunica via `docker attach` (una linea JSON-RPC
# por mensaje). Al actualizar la configuracion (modelo, MCP, sandbox) el
# orquestador re-escibe /config/* y hace `docker restart`: el app-server se
# relanza con los nuevos -c y reanuda el thread (thread/resume).
#
# Base node:24-bookworm (paridad con Pi: node moderno para servers MCP stdio
# + apt para python3). El binary app-server es MUSL ESTATICO: no necesita
# ni node ni glibc para correr (verificado en el spike: corre en
# debian:bookworm-slim desnudo).
FROM node:24-bookworm

ARG CODEX_VERSION=0.171.0
ARG TARGETARCH=amd64

# El asset se llama codex-app-server-<plat>-unknown-linux-musl.tar.gz y
# contiene un unico binario. Todo en un RUN: `plat` es una variable de
# shell del propio command (un fichero temporal perderia el newline en la URL).
RUN set -eux; \
    case "${TARGETARCH}" in \
        amd64) plat=x86_64 ;; \
        arm64) plat=aarch64 ;; \
        *) echo "TARGETARCH no soportada: ${TARGETARCH}"; exit 1 ;; \
    esac; \
    curl -fsSL "https://github.com/openai/codex/releases/download/rust-v${CODEX_VERSION}/codex-app-server-${plat}-unknown-linux-musl.tar.gz" -o /tmp/codex-app-server.tar.gz; \
    tar -xzf /tmp/codex-app-server.tar.gz -C /tmp; \
    install -m 0755 "/tmp/codex-app-server-${plat}-unknown-linux-musl" /usr/local/bin/codex-app-server; \
    rm -f /tmp/codex-app-server.tar.gz "/tmp/codex-app-server-${plat}-unknown-linux-musl"; \
    /usr/local/bin/codex-app-server --version

# python3 para servers MCP stdio (paridad con Pi); bubblewrap para el
# sandbox de Codex (sin el, el app-server avisa y usa el embebido);
# ca-certificates para HTTPS
RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 bubblewrap ca-certificates \
 && rm -rf /var/lib/apt/lists/*

RUN useradd -m -u 1001 -d /home/agent -s /bin/sh agent \
 && mkdir -p /data /workspace /config \
 && chown -R agent:agent /data /workspace /config

ENV HOME=/home/agent
WORKDIR /workspace

COPY entrypoint.sh /usr/local/bin/agent-entrypoint
RUN chmod +x /usr/local/bin/agent-entrypoint && chown agent:agent /usr/local/bin/agent-entrypoint

RUN mkdir -p /rpc && chown agent:agent /rpc
USER agent
ENTRYPOINT ["/usr/local/bin/agent-entrypoint"]

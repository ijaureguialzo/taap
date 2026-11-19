# Imagen del agente Hermes.
#
# La imagen oficial de Hermes ya trae todo lo necesario: supervisor s6
# (si el gateway cae, s6 lo reinicia en segundos - semantica 24/7) y lectura
# de configuracion de /opt/data/.env (el volumen de datos) y /opt/data/config.yaml.
#
# Por tanto la imagen de TAAP es la base con solo los defaults del servidor
# API. El orquestador, por agente:
#   - monta el volumen taap-agent-<id>-data en /opt/data
#   - renderiza en el volumen: .env (API_SERVER_KEY=...) y config.yaml
#     (mcp_servers apuntando al bridge de TAAP, model apuntando al Model Proxy)
#   - arranca `gateway run` (CMD por defecto) y habla con :8642 por taap-internal
ARG HERMES_IMAGE=nousresearch/hermes-agent:stable
FROM ${HERMES_IMAGE}

ENV API_SERVER_ENABLED=true \
    API_SERVER_HOST=0.0.0.0

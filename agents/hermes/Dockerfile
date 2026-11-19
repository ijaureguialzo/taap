# Imagen del agente Hermes.
#
# La imagen oficial trae: s6-overlay (si el gateway cae, lo reinicia en
# segundos - semantica 24/7), lectura de /opt/data/.env (override=True) y
# /opt/data/config.yaml, y el API server OpenAI-compatible en :8642
# (aiohttp, loopback por defecto).
#
# Decisiones de TAAP:
#   - CMD `hermes gateway run` (sin args el wrapper lanzaria la TUI `hermes`).
#   - API_SERVER_HOST=0.0.0.0 para que el orquestador hable con :8642 por
#     taap-internal (el bind por defecto es 127.0.0.1).
#   - La clave fuerte (API_SERVER_KEY) y la config (config.yaml) las escribe
#     el orquestador en el volumen /opt/data por agente; el .env del
#     contenedor es sobreescrito por el .env del volumen (override=True).
ARG HERMES_IMAGE=nousresearch/hermes-agent:stable
FROM ${HERMES_IMAGE}

ENV API_SERVER_ENABLED=true \
    API_SERVER_HOST=0.0.0.0

CMD ["hermes", "gateway", "run"]

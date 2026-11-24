# Orquestador TAAP (Laravel 13 + Reverb + bridge).
#
# Fases:
#   vendor   - dependencias PHP (composer)
#   frontend - build de assets (Vite: CSS/JS de la UI)
#   runtime  - php-cli + docker CLI + extensiones
#
# El contenedor es dual-homed (taap-egress + taap-internal, ver compose):
# los agentes lo alcanzan como taap-orchestrator:8000 (Model Proxy/MCP/bridge).

# --- Dependencias PHP (sin el codigo, para cache de capas) ---
FROM composer:2 AS vendor
WORKDIR /app
COPY orchestrator/composer.json orchestrator/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --ignore-platform-reqs --prefer-dist --no-progress
COPY orchestrator ./
RUN composer dump-autoload --optimize

# --- Frontend (build de Vite: UI web) ---
# node:24 (glibc): las dependencias nativas (rollup/oxdide) son variantes -gnu.
FROM node:24 AS frontend
WORKDIR /app
COPY orchestrator/package.json orchestrator/package-lock.json* ./
RUN npm install --no-audit --no-fund
COPY orchestrator/vite.config.js ./
COPY orchestrator/artisan artisan
COPY orchestrator/bootstrap ./bootstrap
COPY orchestrator/public ./public
COPY orchestrator/resources ./resources
COPY orchestrator/routes ./routes
COPY orchestrator/config ./config
RUN npm run build

# --- Runtime ---
# php:8.4 (no -cli): incluye phpize para las extensiones desde fuente.
FROM php:8.4
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip zip libzip-dev libxml2-utils ca-certificates curl gnupg libpq-dev; \
    # CLI de Docker (el DockerClient habla por CLI: contenedores, redes, logs)
    curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /usr/share/keyrings/docker.gpg; \
    echo "deb [arch=amd64 signed-by=/usr/share/keyrings/docker.gpg] https://download.docker.com/linux/debian bookworm stable" > /etc/apt/sources.list.d/docker.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends docker-cli; \
    rm -rf /var/lib/apt/lists/*
# Solo las extensiones que no trae la imagen base (posix/openssl/curl/
# mbstring/fileinfo ya van compiladas; reinstalarlas rompe los sources).
# zip: empaquetado de carpetas en el explorador (shared-filesystem 3.3).
RUN docker-php-ext-install pdo_pgsql pcntl opcache zip

COPY --from=vendor /app /app
COPY --from=frontend /app/public/build /app/public/build
COPY deploy/entrypoint.sh /usr/local/bin/taap-entrypoint
RUN chmod +x /usr/local/bin/taap-entrypoint && \
    mkdir -p /app/storage/framework/{cache,sessions,views} /app/bootstrap/cache /data/agents /data/fs && \
    chown -R www-data:www-data /app/storage /app/bootstrap/cache /data/agents /data/fs && \
    cp /app/.env.example /app/.env && rm /app/.env.example

ENV PHP_CLI_SERVER_WORKERS=8 \
    TAAP_AGENT_DATA_BASE=/data/agents \
    TAAP_RPC_BASE=/tmp/taap-rpc

WORKDIR /app
EXPOSE 8000 8080
# Sin USER: el entrypoint baja a www-data con setpriv tras soltar el socket.
CMD ["/usr/local/bin/taap-entrypoint"]

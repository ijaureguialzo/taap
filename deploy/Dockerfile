# --- Dependencias PHP (sin el código, para cache de capas) ---
FROM composer:2 AS vendor
WORKDIR /app
COPY orchestrator/composer.json orchestrator/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --ignore-platform-reqs --prefer-dist --no-progress
COPY orchestrator ./
RUN composer dump-autoload --optimize

# --- Frontend (build de Vite: Bootstrap 5 + Alpine + Livewire) ---
FROM node:24-alpine AS frontend
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
FROM php:8.4-cli
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip zip libxml2-utils; \
    docker-php-ext-install pdo_pgsql pcntl opcache; \
    rm -rf /var/lib/apt/lists/*

COPY --from=vendor /app /app
COPY --from=frontend /app/public/build /app/public/build
COPY --from=vendor /app/.env.example /app/.env.production
COPY deploy/entrypoint.sh /usr/local/bin/taap-entrypoint
RUN chmod +x /usr/local/bin/taap-entrypoint && \
    mkdir -p /app/storage/framework/{cache,sessions,views} /app/bootstrap/cache && \
    chown -R www-data:www-data /app/storage /app/bootstrap/cache && \
    cp /app/.env.production /app/.env && rm /app/.env.production

ENV PHP_CLI_SERVER_WORKERS=4
WORKDIR /app
EXPOSE 8000 8080
USER www-data
CMD ["/usr/local/bin/taap-entrypoint"]

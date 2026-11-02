# Lumberjack: one image with the web server, Reverb, Horizon and the scheduler.
# Postgres and Redis run beside it (see compose.yaml).

FROM docker.io/dunglas/frankenphp:1-php8.5-alpine AS base

RUN install-php-extensions pdo_pgsql redis pcntl intl gd zip bcmath \
    && apk add --no-cache supervisor su-exec

WORKDIR /app

# Build: PHP and Node together, since Wayfinder runs artisan during the Vite build.
FROM base AS build

RUN apk add --no-cache nodejs npm
COPY --from=docker.io/library/composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && npm run build \
    && rm -rf node_modules

FROM base

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    DB_CONNECTION=pgsql \
    DB_HOST=postgres \
    DB_PORT=5432 \
    DB_DATABASE=lumberjack \
    DB_USERNAME=lumberjack \
    REDIS_CLIENT=phpredis \
    REDIS_HOST=redis \
    REDIS_PORT=6379 \
    CACHE_STORE=redis \
    QUEUE_CONNECTION=redis \
    SESSION_DRIVER=redis \
    BROADCAST_CONNECTION=reverb \
    REVERB_HOST=127.0.0.1 \
    REVERB_PORT=8081 \
    REVERB_SCHEME=http \
    REVERB_SERVER_HOST=127.0.0.1 \
    REVERB_SERVER_PORT=8081 \
    TRUSTED_PROXIES=127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7 \
    SERVER_NAME=:8080

COPY --from=build --chown=www-data:www-data /app /app
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-lumberjack.ini
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/lumberjack-entrypoint

RUN chown -R www-data:www-data /data /config

VOLUME /app/storage
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s \
    CMD wget -qO- http://127.0.0.1:8080/up >/dev/null || exit 1

ENTRYPOINT ["lumberjack-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]

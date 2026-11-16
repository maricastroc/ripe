FROM dunglas/frankenphp:1-php8.4-alpine

RUN install-php-extensions pdo_pgsql pcntl intl zip opcache bcmath \
    && apk add --no-cache supervisor postgresql17-client

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_STDERR_FORMATTER=Monolog\\Formatter\\JsonFormatter \
    LOG_LEVEL=info \
    REVERB_SERVER_HOST=127.0.0.1 \
    REVERB_SERVER_PORT=8081 \
    REVERB_HOST=127.0.0.1 \
    REVERB_PORT=8081 \
    REVERB_SCHEME=http

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R www-data:www-data storage bootstrap/cache /data /config

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/supervisord.conf /etc/supervisord.conf
COPY --chmod=755 docker/start.sh /usr/local/bin/ripe-start

USER www-data

CMD ["ripe-start"]

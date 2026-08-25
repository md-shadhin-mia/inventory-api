# syntax=docker/dockerfile:1.7

# This is a pure JSON REST API: there is no frontend and therefore no asset
# build stage. Health probe: GET /api/v1/health (checks PostgreSQL + cache).

# ---------- 1. Shared PHP runtime ----------
FROM php:8.4-fpm-alpine AS php-base
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
# pdo_pgsql/pgsql: DB_CONNECTION=pgsql everywhere.
# redis: REDIS_CLIENT=phpredis — predis is NOT installed, so this is mandatory.
# pcntl: lets queue:work handle SIGTERM gracefully.
RUN install-php-extensions pdo_pgsql pgsql redis pcntl bcmath opcache \
    && apk add --no-cache su-exec
WORKDIR /var/www/html

# ---------- 2. Composer vendor ----------
FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
# Manifests first so the vendor layer survives application code changes.
COPY composer.json composer.lock ./
# --no-scripts is required: post-autoload-dump boots Artisan, which needs app/.
RUN composer install --no-dev --no-scripts --no-autoloader \
    --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi

# ---------- 3. Production app image (nginx + php-fpm under supervisor) ----------
FROM php-base AS app
RUN apk add --no-cache nginx supervisor

COPY --chown=www-data:www-data . /var/www/html
COPY --from=vendor --chown=www-data:www-data /app/vendor          /var/www/html/vendor
# Carry the package:discover manifest forward rather than regenerating at runtime.
COPY --from=vendor --chown=www-data:www-data /app/bootstrap/cache /var/www/html/bootstrap/cache

COPY docker/php/app.ini                 /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf                /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/nginx/nginx.conf            /etc/nginx/nginx.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh               /usr/local/bin/entrypoint

# .dockerignore strips the contents of storage/framework/*, and git cannot carry
# empty directories, so recreate them. nginx runs as www-data (not the apk nginx
# user) so it can read the php-fpm unix socket.
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions \
                storage/framework/views storage/framework/testing storage/logs \
                bootstrap/cache /run/nginx /var/lib/nginx/tmp \
    && chown -R www-data:www-data storage bootstrap/cache /run/nginx /var/lib/nginx \
    && rm -f /etc/nginx/http.d/default.conf

ENV APP_ENV=production \
    APP_DEBUG=false \
    CONTAINER_ROLE=app
EXPOSE 8080
ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]

# ---------- 4. Dev/test target ----------
# Every database factory calls fake(), and fakerphp/faker + mockery + collision
# are require-dev. A --no-dev image physically cannot run the suite or db:seed,
# so tests and seeding use this target: BUILD_TARGET=dev docker compose up.
FROM app AS dev
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=local \
    APP_DEBUG=true
RUN composer install --prefer-dist --no-interaction --no-progress \
    && chown -R www-data:www-data vendor \
    # An EMPTY .env: configuration still comes entirely from real environment
    # variables, but its presence stops phpdotenv emitting a "failed to open
    # stream" warning on every single test.
    && touch .env \
    && chown www-data:www-data .env

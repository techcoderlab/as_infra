###############################################################################
# services/business-agency-saas-api/Dockerfile
# Laravel API, PHP-FPM only (no embedded webserver — the gateway container
# reverse-proxies to this via FastCGI).
#
# Shared by business-agency-saas-api / agency-saas-queue-worker-default /
# agency-saas-queue-worker-ai / scheduler in infra/docker-compose.yml — only
# the container command differs between them, so this is built exactly once.
#
# vendor/ is installed and baked in at build time (composer install runs
# HERE, not on the host) — this is the "immutable image" choice you
# confirmed, so there's no host-side composer step and no risk of a
# container starting with a stale or missing vendor/ directory.
###############################################################################

# ---- Stage 1: install PHP dependencies with Composer -----------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --prefer-dist \
      --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---- Stage 2: runtime --------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache \
      bash \
      curl \
      gettext \
      icu-libs \
      libzip \
      libpng \
      libjpeg-turbo \
      freetype \
      oniguruma \
      postgresql-libs \
    && apk add --no-cache --virtual .build-deps \
      icu-dev \
      libzip-dev \
      libpng-dev \
      libjpeg-turbo-dev \
      freetype-dev \
      oniguruma-dev \
      postgresql-dev \
      $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
      pdo_pgsql \
      pgsql \
      intl \
      zip \
      gd \
      bcmath \
      mbstring \
      opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html

# *.template files — entrypoint.sh renders these with envsubst at container
# start, so PHP_FPM_MAX_CHILDREN / OPCACHE_MEMORY_MB / etc. (set in
# infra/docker-compose.yml or infra/.env) take effect without a rebuild.
COPY docker/php.ini.template /usr/local/etc/php-templates/99-app.ini.template
COPY docker/opcache.ini.template /usr/local/etc/php-templates/98-opcache.ini.template
COPY docker/www.conf.template /usr/local/etc/php-templates/www.conf.template
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Stays root deliberately: php-fpm's master process needs to start as root
# so it can setuid/setgid to www-data for each worker (per `user = www-data`
# in www.conf.template) — this matches the upstream php:fpm image's own
# default, and lets entrypoint.sh write rendered configs into
# /usr/local/etc before handing off.
EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]

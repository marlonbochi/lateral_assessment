# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
# base: PHP 8.4 FPM with the extensions the application needs.
# The tag pins an exact patch release, at or above the minimum in
# App\Support\Security\PhpRuntimePolicy (8.4.27 fixes CVE-2026-109618 and
# CVE-2026-109619); Dependabot proposes newer patches.
# ---------------------------------------------------------------------------
FROM php:8.5.0-fpm-alpine AS base

RUN apk add --no-cache icu-libs libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql opcache pcntl intl \
    && apk del .build-deps

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# vendor: production dependencies, resolved with the same PHP as runtime.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-interaction
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts
# Refuse to build an image whose PHP runtime has a known critical vulnerability.
RUN php artisan security:php

# ---------------------------------------------------------------------------
# app: production image (no dev dependencies, no Composer, non-root).
# ---------------------------------------------------------------------------
FROM base AS app

ENV APP_ENV=production \
    APP_DEBUG=false

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html

USER www-data

EXPOSE 9000
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# dev: local development and tests (Composer + pcov for coverage).
# Source code is bind-mounted by docker-compose.yml.
# ---------------------------------------------------------------------------
FROM base AS dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .build-deps \
    && echo "pcov.enabled=1" > /usr/local/etc/php/conf.d/zz-pcov.ini \
    && echo "pcov.directory=/var/www/html/app" >> /usr/local/etc/php/conf.d/zz-pcov.ini \
    # Must sort after zz-opcache.ini so it overrides validate_timestamps=0 in dev.
    && echo "opcache.validate_timestamps=1" > /usr/local/etc/php/conf.d/zzz-opcache-dev.ini

EXPOSE 9000
CMD ["php-fpm"]

# syntax=docker/dockerfile:1

########################################
# PHP runtime for the Sales Document service
########################################
# composer.lock pins dev deps (phpunit 13, sebastian/*, doctrine/instantiator)
# that require PHP >= 8.4, even though composer.json only asks for >= 8.2.
FROM php:8.4-fpm-alpine

# Runtime libraries + build deps for the PHP extensions
RUN apk add --no-cache \
        bash git unzip icu-libs postgresql-libs \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev postgresql-dev linux-headers \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql opcache \
    # Xdebug - the task recommends a debugger for diagnosing problem 3
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# Composer 2 from the official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP / Xdebug configuration
COPY docker/php/conf.d/ /usr/local/etc/php/conf.d/

# Run as a user whose id matches the host so bind-mounted files stay writable.
# php-fpm master then runs unprivileged and workers inherit this uid.
ARG UID=1000
ARG GID=1000
RUN addgroup -g "${GID}" app && adduser -u "${UID}" -G app -s /bin/bash -D app

WORKDIR /app

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

USER app

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm", "-F"]

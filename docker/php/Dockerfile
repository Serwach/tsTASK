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

# Run php-fpm as a non-root user whose uid/gid match the host, so files created
# in the bind mount (vendor/, var/) stay writable from both sides.
# The gid or uid may already exist in the base image (e.g. on macOS the host gid
# is 20, which is "dialout" in Alpine) - in that case reuse it instead of failing.
ARG UID=1000
ARG GID=1000
RUN set -eux; \
    if awk -F: -v g="${GID}" '$3==g{f=1} END{exit !f}' /etc/group; then \
        appgroup="$(awk -F: -v g="${GID}" '$3==g{print $1; exit}' /etc/group)"; \
    else \
        addgroup -g "${GID}" app; appgroup=app; \
    fi; \
    if ! awk -F: -v u="${UID}" '$3==u{f=1} END{exit !f}' /etc/passwd; then \
        adduser -u "${UID}" -G "$appgroup" -s /bin/bash -D -h /home/app app; \
    fi; \
    mkdir -p /home/app; chown "${UID}:${GID}" /home/app

ENV HOME=/home/app \
    COMPOSER_HOME=/home/app/.composer

WORKDIR /app

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

USER ${UID}:${GID}

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm", "-F"]

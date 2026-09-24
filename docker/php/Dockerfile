# PHP 8.4-FPM with the extensions the app needs (imagick for TIFF-capable
# thumbnailing, exif for metadata, pdo_mysql, intl, zip, opcache).
FROM php:8.4-fpm

ARG APP_UID=1000
ARG APP_GID=1000

# git/unzip are needed by Composer for VCS installs and package downloads;
# netcat is used by the container healthcheck below; the PHP extensions
# themselves are installed via docker-php-extension-installer below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        netcat-openbsd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# mlocati/docker-php-extension-installer (MIT) resolves the correct system
# packages (e.g. libmagickwand-dev) for each extension on this PHP version/OS.
# Pinned to an exact release and checksum-verified for a reproducible,
# supply-chain-safe build (avoid a bare "latest" fetch).
ARG PHP_EXTENSION_INSTALLER_VERSION=2.11.33
ARG PHP_EXTENSION_INSTALLER_SHA256=5e6580fc1a16832ee88cd43a96ca5c8727e2e210bee433297f19e2f6018f1b7a
ADD https://github.com/mlocati/docker-php-extension-installer/releases/download/${PHP_EXTENSION_INSTALLER_VERSION}/install-php-extensions /usr/local/bin/install-php-extensions
RUN echo "${PHP_EXTENSION_INSTALLER_SHA256}  /usr/local/bin/install-php-extensions" | sha256sum -c - \
    && chmod +x /usr/local/bin/install-php-extensions
RUN install-php-extensions imagick exif pdo_mysql intl zip opcache pcntl

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/bootstrap-env.sh /usr/local/bin/bootstrap-env.sh
COPY docker/php/php-entrypoint.sh /usr/local/bin/php-entrypoint.sh
COPY docker/php/queue-entrypoint.sh /usr/local/bin/queue-entrypoint.sh
RUN chmod +x /usr/local/bin/bootstrap-env.sh /usr/local/bin/php-entrypoint.sh /usr/local/bin/queue-entrypoint.sh

# Align the container's www-data with the host user so bind-mounted files
# in backend/ stay owned by the developer, not root.
RUN groupmod -g "${APP_GID}" www-data \
    && usermod -u "${APP_UID}" -g "${APP_GID}" www-data \
    && mkdir -p /var/www/html \
    && chown -R www-data:www-data /var/www/html

USER www-data
WORKDIR /var/www/html

# Bootstraps backend/.env on a fresh clone before falling through to the
# base image's own entrypoint (php-fpm); `queue` overrides this in
# docker-compose.yml, so it only affects the `php` service.
ENTRYPOINT ["/usr/local/bin/php-entrypoint.sh"]
CMD ["php-fpm"]

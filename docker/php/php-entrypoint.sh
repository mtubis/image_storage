#!/usr/bin/env bash
# Entrypoint for the `php` (fpm) service: bootstrap .env, then hand off to
# the base image's own entrypoint so php-fpm starts normally.
set -euo pipefail

/usr/local/bin/bootstrap-env.sh

# Thumbnails live on the "thumbnails" disk under storage/app/public and are served by
# nginx through public/storage. Only here, not in the queue container, which shares the
# bind mount and would race on creating the link. Absolute (/var/www/html/...), which is
# where nginx mounts the backend too; a relative link would need symfony/filesystem.
if [ -f artisan ] && [ -d vendor ] && [ ! -L public/storage ]; then
    php artisan storage:link
fi

exec docker-php-entrypoint "$@"

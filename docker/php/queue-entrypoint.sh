#!/usr/bin/env bash
# Entrypoint for the `queue` service (same image as `php`).
# Laravel isn't scaffolded until step 0.2, so on a fresh checkout `artisan`
# doesn't exist yet; wait for it instead of crash-looping the container.
set -euo pipefail

until [ -f /var/www/html/artisan ]; do
    echo "[queue] waiting for backend/artisan (Laravel not installed yet)..."
    sleep 2
done

/usr/local/bin/bootstrap-env.sh

exec php artisan queue:work --tries=3 --backoff=5

#!/usr/bin/env bash
# Entrypoint for the `queue` service (same image as `php`).
# backend/ is a bind mount; wait for `artisan` to be visible there instead of
# crash-looping the container.
set -euo pipefail

until [ -f /var/www/html/artisan ]; do
    echo "[queue] waiting for backend/artisan (Laravel not installed yet)..."
    sleep 2
done

/usr/local/bin/bootstrap-env.sh

# Tries, backoff and timeout are declared on each job class; worker-level flags would only
# serve as misleading fallbacks. The job timeout needs ext-pcntl (installed in the image).
exec php artisan queue:work

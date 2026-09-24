#!/usr/bin/env bash
# Entrypoint for the `php` (fpm) service: bootstrap .env, then hand off to
# the base image's own entrypoint so php-fpm starts normally.
set -euo pipefail

/usr/local/bin/bootstrap-env.sh

exec docker-php-entrypoint "$@"

#!/usr/bin/env bash
# Bootstraps backend/.env on a fresh clone (it's gitignored, so it won't
# exist yet) so `make up` is self-sufficient and doesn't need a manual
# `cp .env.example .env && artisan key:generate` step. Idempotent: does
# nothing once .env already exists on the bind-mounted host directory.
set -euo pipefail

cd /var/www/html

if [ -f artisan ] && [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force --ansi
fi

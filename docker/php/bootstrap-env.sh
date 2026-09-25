#!/usr/bin/env bash
# Bootstraps backend/.env on a fresh clone (it's gitignored, so it won't
# exist yet), so `make setup` needs no manual
# `cp .env.example .env && artisan key:generate` step. Idempotent: does
# nothing once .env already exists on the bind-mounted host directory.
# Runs only once vendor/ exists: key:generate needs it, and a .env copied
# without a key would stay keyless, since every later run skips an existing .env.
set -euo pipefail

cd /var/www/html

if [ -f vendor/autoload.php ] && [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force --ansi
fi

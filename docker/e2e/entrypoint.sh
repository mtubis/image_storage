#!/usr/bin/env sh
# Entrypoint of the `playwright` runner (docker-compose.e2e.yml). Like the frontend's:
# node_modules lives in the bind mount, so reinstall only when the lockfile changed.
set -eu

if [ ! -f /e2e/node_modules/.package-lock.json ] \
    || [ /e2e/package-lock.json -nt /e2e/node_modules/.package-lock.json ]; then
    npm ci
fi

exec "$@"

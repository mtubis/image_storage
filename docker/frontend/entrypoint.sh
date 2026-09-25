#!/usr/bin/env sh
set -euo pipefail

# node_modules lives in the bind mount and survives container restarts, so
# reinstall whenever the lockfile is newer than npm's record of the last
# install (e.g. after a dependency bump in a pull).
if [ ! -f /app/node_modules/.package-lock.json ] \
    || [ /app/package-lock.json -nt /app/node_modules/.package-lock.json ]; then
    npm ci
fi

# The Vite dev server by default (CMD); the E2E stack serves a production build instead.
exec "$@"

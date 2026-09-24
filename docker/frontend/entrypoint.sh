#!/usr/bin/env sh
# The Vite app isn't scaffolded until step 0.4; wait for package.json
# instead of crash-looping the container on a fresh checkout.
set -euo pipefail

until [ -f /app/package.json ]; do
    echo "[frontend] waiting for frontend/package.json (Vite app not scaffolded yet)..."
    sleep 2
done

if [ ! -d /app/node_modules ]; then
    npm install
fi

exec npm run dev -- --host 0.0.0.0

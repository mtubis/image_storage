SHELL := /bin/bash
COMPOSE := docker compose
# Files created in the bind mounts (vendor, node_modules, storage) belong to the host user.
DEV_COMPOSE := DOCKER_UID=$$(id -u) DOCKER_GID=$$(id -g) $(COMPOSE)
# The E2E stack is its own Compose project (docker-compose.e2e.yml): other ports, its own
# database and storage, so it runs next to the development stack without touching it.
E2E_COMPOSE := DOCKER_UID=$$(id -u) DOCKER_GID=$$(id -g) $(COMPOSE) -p image_storage_e2e -f docker-compose.yml -f docker-compose.e2e.yml

.PHONY: setup up down ps logs sh-php artisan fixtures test-be test-be-mariadb test-fe lint-be lint-fe lint-e2e build-fe fix check e2e e2e-deps e2e-logs e2e-down

# First run on a fresh clone; safe to repeat. backend/vendor must exist before the php service
# starts (its entrypoint runs key:generate and storage:link), so composer runs first in a one-off
# container that bypasses that entrypoint. Migrations run in a second one-off container (which
# starts db and creates backend/.env once, before php and queue could race on it), so the queue
# worker never polls a missing jobs table. The frontend container installs node_modules itself.
setup:
	$(DEV_COMPOSE) run --rm --no-deps --build --entrypoint composer \
		php install --no-interaction --no-progress --prefer-dist
	$(DEV_COMPOSE) run --rm php php artisan migrate --force
	$(DEV_COMPOSE) up --detach --build --wait --wait-timeout 300

up:
	$(DEV_COMPOSE) up -d --build

down:
	$(COMPOSE) down

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f

sh-php:
	$(COMPOSE) exec php bash

artisan:
	$(COMPOSE) exec -T php php artisan $(cmd)

# Regenerates the committed image fixtures (backend/tests/Fixtures/README.md).
fixtures:
	$(COMPOSE) exec -T php php tests/Fixtures/generate.php

test-be:
	$(COMPOSE) exec -T php vendor/bin/pest

# The same suite on MariaDB, the production engine (phpunit.mariadb.xml). The test database is
# created on demand, so existing dev volumes (initialised before it existed) work as well.
test-be-mariadb:
	$(COMPOSE) exec -T db sh -c 'mariadb -uroot -p"$$MARIADB_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS image_storage_testing; GRANT ALL ON image_storage_testing.* TO \`$$MARIADB_USER\`@\`%\`;"'
	$(COMPOSE) exec -T php vendor/bin/pest -c phpunit.mariadb.xml

test-fe:
	$(COMPOSE) exec -T frontend npm run test

lint-be:
	$(COMPOSE) exec -T php composer validate --strict
	$(COMPOSE) exec -T php vendor/bin/pint --test
	$(COMPOSE) exec -T php vendor/bin/phpstan analyse
	$(COMPOSE) exec -T php vendor/bin/rector --dry-run
	$(COMPOSE) exec -T php php artisan scramble:analyze

lint-fe:
	$(COMPOSE) exec -T frontend npm run lint
	$(COMPOSE) exec -T frontend npm run typecheck
	$(COMPOSE) exec -T frontend npm run format:check

# The runner container only; no need for the E2E stack to be up.
lint-e2e:
	$(E2E_COMPOSE) run --rm --no-deps playwright npm run lint
	$(E2E_COMPOSE) run --rm --no-deps playwright npm run typecheck
	$(E2E_COMPOSE) run --rm --no-deps playwright npm run format:check

build-fe:
	$(COMPOSE) exec -T frontend npm run build

fix:
	$(COMPOSE) exec -T php vendor/bin/pint
	$(COMPOSE) exec -T php vendor/bin/rector
	$(COMPOSE) exec -T frontend npm run lint -- --fix
	$(COMPOSE) exec -T frontend npm run format
	$(E2E_COMPOSE) run --rm --no-deps playwright npm run lint -- --fix
	$(E2E_COMPOSE) run --rm --no-deps playwright npm run format

# Mirrors the CI workflow (.github/workflows/ci.yml): green here means green there.
# lint-e2e runs in CI's E2E job, together with `make e2e` (which is not part of `check`).
check: lint-be test-be test-be-mariadb lint-fe test-fe build-fe lint-e2e

# A fresh stack and database on every run. The data is reset here rather than in Playwright's
# globalSetup: the runner container has no access to Docker (and must not get the socket).
# The stack stays up afterwards for inspection; `make e2e-down` removes it.
# Options for Playwright go in `args`, e.g. make e2e args="--repeat-each=3".
e2e:
	$(E2E_COMPOSE) down --volumes --remove-orphans
	$(E2E_COMPOSE) up --detach --build --wait --wait-timeout 300
	# bootstrap/cache is shared with development; a cached config would ignore the E2E env.
	$(E2E_COMPOSE) exec -T php php artisan config:clear
	$(E2E_COMPOSE) exec -T php php artisan migrate:fresh --seed --seeder=E2eImageSeeder --force
	$(E2E_COMPOSE) run --rm playwright $(if $(args),npx playwright test $(args))

# A fresh clone has no backend/vendor, which the php service's entrypoint needs (key:generate,
# storage:link), so this runs composer directly in a one-off container, bypassing it.
# composer_cache=<host dir> mounts a download cache (used by CI).
e2e-deps:
	$(E2E_COMPOSE) run --rm --no-deps --build --entrypoint composer \
		$(if $(composer_cache),--volume $(composer_cache):/composer-cache --env COMPOSER_CACHE_DIR=/composer-cache) \
		php install --no-interaction --no-progress --prefer-dist

e2e-logs:
	$(E2E_COMPOSE) logs --no-color --timestamps

e2e-down:
	$(E2E_COMPOSE) down --volumes --remove-orphans

SHELL := /bin/bash
COMPOSE := docker compose

.PHONY: up down ps logs sh-php artisan fixtures test-be test-fe lint-be lint-fe build-fe fix check e2e

up:
	DOCKER_UID=$$(id -u) DOCKER_GID=$$(id -g) $(COMPOSE) up -d --build

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

build-fe:
	$(COMPOSE) exec -T frontend npm run build

fix:
	$(COMPOSE) exec -T php vendor/bin/pint
	$(COMPOSE) exec -T php vendor/bin/rector
	$(COMPOSE) exec -T frontend npm run lint -- --fix
	$(COMPOSE) exec -T frontend npm run format

# Mirrors the CI workflow (.github/workflows/ci.yml): green here means green there.
check: lint-be test-be lint-fe test-fe build-fe

e2e:
	$(COMPOSE) -f docker-compose.yml -f docker-compose.e2e.yml up -d --build
	cd e2e && npm test

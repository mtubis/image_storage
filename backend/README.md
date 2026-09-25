# Image Storage — Backend

The Laravel 13 JSON API behind Image Storage. See the [root README](../README.md) for setup,
architecture, the API and testing, and [docs/DECISIONS.md](../docs/DECISIONS.md) for the
reasoning behind the design.

Runs as part of the root `docker-compose.yml` stack; see the root `Makefile` for the commands
(`make setup`, `make artisan cmd="..."`, `make test-be`, `make lint-be`, ...). No standalone
setup is needed or supported outside that stack.

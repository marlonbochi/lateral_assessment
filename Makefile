# All targets run inside the containers defined in docker-compose.yml.
COMPOSE ?= docker compose
EXEC    ?= $(COMPOSE) exec -T app

.DEFAULT_GOAL := help
.PHONY: help setup up down build shell migrate fresh test coverage perf lint fix ci token

help: ## List the available targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  %-10s %s\n", $$1, $$2}'

setup: ## One-command bootstrap: env, build, start, install, migrate, demo seed
	@test -f .env || cp .env.example .env
	$(COMPOSE) up -d --build --wait
	$(EXEC) composer install --no-interaction
	@grep -q '^APP_KEY=base64' .env || $(EXEC) php artisan key:generate --force
	$(EXEC) php artisan migrate --force
	@if [ -f database/seeders/DemoSeeder.php ]; then $(EXEC) php artisan db:seed --class=DemoSeeder --force; fi
	$(COMPOSE) restart worker scheduler

up: ## Start the stack
	$(COMPOSE) up -d --wait

down: ## Stop the stack
	$(COMPOSE) down

build: ## Rebuild the images
	$(COMPOSE) build

shell: ## Open a shell in the app container
	$(COMPOSE) exec app sh

migrate: ## Run migrations on the development database
	$(EXEC) php artisan migrate

fresh: ## Recreate the development database and seed it
	$(EXEC) php artisan migrate:fresh --seed

test: ## Run the test suite (isolated cms_sync_test database)
	$(EXEC) php artisan test --parallel

coverage: ## Run the test suite with coverage
	$(EXEC) php artisan test --parallel --coverage --min=85

perf: ## Run the performance tests against a 100k-row seed
	$(EXEC) php artisan migrate:fresh --env=testing --force
	$(COMPOSE) exec -T -e PERF_SEED_ROWS=100000 app vendor/bin/pest tests/Performance

lint: ## Pint (check) + Larastan
	$(EXEC) vendor/bin/pint --test
	$(EXEC) vendor/bin/phpstan analyse --no-progress --memory-limit=1G

fix: ## Apply Pint fixes
	$(EXEC) vendor/bin/pint

ci: lint coverage ## Everything CI runs (lint, static analysis, tests with the 85% coverage gate)

token: ## Issue a consumer token: make token EMAIL=you@example.test
	$(EXEC) php artisan consumer:token $(EMAIL)

COMPOSE := docker compose
EXEC    := $(COMPOSE) exec -u www-data ledger-service
CONSOLE := $(EXEC) php bin/console

.DEFAULT_GOAL := help
.PHONY: help up down build logs sh install migrate seed test-db test check verify

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "} {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Build and start the stack
	$(COMPOSE) up -d --build

down: ## Stop the stack
	$(COMPOSE) down

build: ## Build images
	$(COMPOSE) build

logs: ## Follow logs
	$(COMPOSE) logs -f

sh: ## Shell into the ledger-service container
	$(EXEC) sh

install: ## Install PHP dependencies
	$(EXEC) composer install

migrate: ## Run database migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

seed: ## Load demo accounts and transfers
	$(CONSOLE) ledger:demo:seed

test-db: ## Create and migrate the test database
	$(CONSOLE) doctrine:database:create --if-not-exists --env=test
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

test: test-db ## Run the test suite
	$(EXEC) php bin/phpunit

check: test-db ## Lint, static analysis and tests
	$(EXEC) composer check

verify: ## Check cached balances against postings
	$(CONSOLE) ledger:verify-balances

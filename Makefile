COMPOSE := docker compose

# Infrastructure that every service depends on; it has no compose profile.
INFRA := postgres redis loki grafana

# An application service is runnable once services/<name>/Dockerfile exists.
# Its compose profile carries the same name as its directory.
SERVICES := $(sort $(patsubst services/%/Dockerfile,%,$(wildcard services/*/Dockerfile)))
PROFILES := $(foreach s,$(SERVICES),--profile $(s))

# webhooks-service helpers
WEBHOOKS      := $(COMPOSE) exec webhooks-service
WEBHOOKS_DBS  := webhooks webhooks_test
PG_URL        := postgresql://ledger:ledger@postgres:5432

# ledger-service helpers
EXEC    := $(COMPOSE) exec -u www-data ledger-service
CONSOLE := $(EXEC) php bin/console

.DEFAULT_GOAL := help
.PHONY: help services up up-infra down build ps logs sh install migrate seed test-db test check verify \
        webhook-db webhook-migrate webhook-test webhook-sh

help: ## List available targets
	@grep -E '^[a-zA-Z_%-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "} {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'
	@echo "  Runnable services: $(or $(SERVICES),none)"

services: ## List services that have a Dockerfile
	@printf '%s\n' $(SERVICES)

up: ## Start infrastructure and every service with a Dockerfile
	$(COMPOSE) $(PROFILES) up -d --build

up-infra: ## Start only postgres, redis, loki and grafana
	$(COMPOSE) up -d $(INFRA)

up-%: ## Start infrastructure plus one service, e.g. make up-ledger-service
	@test -f services/$*/Dockerfile || { echo "services/$*/Dockerfile does not exist yet"; exit 1; }
	$(COMPOSE) --profile $* up -d --build $(INFRA) $*

down: ## Stop everything, including all service profiles
	$(COMPOSE) --profile '*' down

down-%: ## Stop one service, e.g. make down-ledger-service
	$(COMPOSE) --profile $* stop $*

build: ## Build images of every service with a Dockerfile
	$(COMPOSE) $(PROFILES) build

ps: ## Show running containers
	$(COMPOSE) --profile '*' ps

logs: ## Follow logs of everything that is running
	$(COMPOSE) --profile '*' logs -f

logs-%: ## Follow logs of one container, e.g. make logs-ledger-service
	$(COMPOSE) --profile '*' logs -f $*

sh: ## Shell into the ledger-service container
	$(EXEC) sh

install: ## Install ledger-service PHP dependencies
	$(EXEC) composer install

migrate: ## Run ledger-service database migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

seed: ## Load demo accounts and transfers
	$(CONSOLE) ledger:demo:seed

test-db: ## Create and migrate the ledger-service test database
	$(CONSOLE) doctrine:database:create --if-not-exists --env=test
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test

test: test-db ## Run the ledger-service test suite
	$(EXEC) php bin/phpunit

check: test-db ## Lint, static analysis and tests for ledger-service
	$(EXEC) composer check

verify: ## Check cached balances against postings
	$(CONSOLE) ledger:verify-balances

webhook-db: ## Create the webhooks and webhooks_test databases if missing
	@for db in $(WEBHOOKS_DBS); do \
	  $(COMPOSE) exec -T postgres psql -U ledger -d ledger -tAc "SELECT 1 FROM pg_database WHERE datname = '$$db'" | grep -q 1 \
	    || $(COMPOSE) exec -T postgres createdb -U ledger $$db; \
	done

webhook-migrate: webhook-db ## Apply webhooks-service migrations to both databases
	@for db in $(WEBHOOKS_DBS); do \
	  $(WEBHOOKS) sh -c "DATABASE_URL=$(PG_URL)/$$db npx prisma migrate deploy"; \
	done

webhook-test: webhook-migrate ## Lint, typecheck, unit, integration and e2e tests for webhooks-service
	$(WEBHOOKS) npm run check

webhook-sh: ## Shell into the webhooks-service container
	$(WEBHOOKS) sh

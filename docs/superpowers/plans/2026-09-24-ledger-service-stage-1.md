# Ledger Service Stage 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver `services/ledger-service` as a runnable, tested double-entry ledger API (accounts, deposits, withdrawals, transfers, statements) with a transactional outbox relayed to Redis, packaged in one php-fpm + nginx container, documented for a portfolio audience.

**Architecture:** A Symfony 8.1 app with three layers.
- `Domain` holds the entities (with Doctrine attributes), the rules and the repository ports.
- `Application` holds the use-case handlers and the ports: transactions, events and the statement reader.
- `Infrastructure` holds the HTTP controllers, the Doctrine adapters, the outbox relay and the console commands.

Postings are the append-only source of truth. `accounts.balance_minor` is a cache, updated under `SELECT … FOR UPDATE`. Events are written to `outbox_messages` in the same DB transaction, and `ledger:outbox:relay` publishes them via Messenger to a Redis stream.

**Tech Stack:**
- PHP 8.4 and Symfony 8.1
- Doctrine ORM 3 / DBAL 4 on PostgreSQL 16
- moneyphp/money 4
- Symfony Messenger with the Redis transport (phpredis), Symfony Lock and Symfony Clock
- PHPUnit 13 with dama/doctrine-test-bundle
- PHPStan level 9 and php-cs-fixer
- Docker (php:8.4-fpm-alpine plus nginx and supervisord)
- GitHub Actions

**Spec:** `docs/superpowers/specs/2026-09-23-ledger-service-stage-1-design.md`

## Global Constraints

- PHP `>=8.4`, Symfony `8.1.*`. Every PHP file starts with `declare(strict_types=1);`.
- Money is never a float. Amounts travel as decimal strings in JSON, and are stored as bigint minor units plus a `char(3)` currency.
- Supported currencies are exactly USD 2, EUR 2, GBP 2, TZS 2 and JPY 0.
- IDs are `Symfony\Component\Uid\Uuid` v7, generated in PHP, stored in Doctrine `uuid` columns.
- Doctrine mapping uses PHP attributes in the entity classes. Mapping dirs are `src/Domain` (prefix `App\Domain`) and `src/Infrastructure/Persistence/Doctrine/Outbox`.
- Schema changes come only from `doctrine:migrations:diff` plus `doctrine:migrations:migrate`. Never use `doctrine:schema:update`.
- `Domain` imports nothing from `Application` or `Infrastructure`. `Application` imports nothing from `Infrastructure`.
- Services are `final class` with `private readonly` promoted properties. They are never `readonly class`. DTOs, views, commands and events are `final readonly class`.
- Domain exceptions extend `App\Domain\Shared\LedgerException` and carry no HTTP status.
- Errors are `application/problem+json` with the `type` value `urn:ledger:problem:<kebab-name>`.
- Commit messages contain **no AI attribution**: no `Co-Authored-By` naming a model and no "Generated with" footer.
- A feature isn't done until a caller-level test exercises it: an HTTP request for a controller, a service call for a service.
- Each task's gates before committing, from `services/ledger-service`:
  - `vendor/bin/php-cs-fixer fix`
  - `vendor/bin/phpstan analyse --memory-limit=1G`
  - `php bin/phpunit`

## Review Focus

1. **Two concurrent first requests with the same `Idempotency-Key`.** Expect exactly one transfer and one balance change; both callers get the same transfer. The Task 13 test `testSameIdempotencyKeyFromManyWorkersCreatesOneTransfer` pins it.
2. **Non-canonical amounts** (a JSON number `10.5`, `"1e3"`, `"-5"`, `"10."`, `" 10"`, a 25-digit amount). Expect 422 problem+json, never 500. Task 2 unit tests and the Task 9 functional test `testRejectsMalformedAmounts` pin it.
3. **A supported currency with no settlement account** would make deposits 500. The Task 6 test `testEverySupportedCurrencyHasASettlementAccount` pins it.
4. **Postings to a frozen account.** Expect 422 `account-not-active`, with no postings, transfer or outbox row persisted. The Task 9 integration test `testFrozenAccountRejectsPostingAndPersistsNothing` pins it.
5. **Statement date boundaries.** A posting at `to`+1 day 00:00:00 is excluded, one at `from` 00:00:00 is included, `from > to` returns 422, and a range over 366 days returns 422. Task 10 tests pin it.

---

## File Structure

```
ledger-platform/
  .github/workflows/ledger-service.yml                 (Task 14)
  Makefile, docker-compose.yml, README.md              (Task 1, README in 14)
  contracts/events/*.schema.json                       (Task 11)
  contracts/openapi/ledger-service.yaml                (Task 14)
  docs/adr/000{1..7}-*.md                              (Task 14)
  services/ledger-service/
    Dockerfile, .dockerignore, docker/{nginx.conf,supervisord.conf,php.ini,php-fpm.conf}   (Task 1)
    config/packages/{doctrine,messenger,lock}.yaml                                          (Tasks 1, 11)
    migrations/Version*.php                                                                 (Tasks 6, 7)
    src/Domain/Shared/{SupportedCurrencies,MoneyParser,MoneyFormatter,DomainEvent,RecordsEvents,LedgerException}.php
    src/Domain/Shared/Exception/{UnsupportedCurrency,InvalidAmount,CurrencyMismatch}.php    (Task 2)
    src/Domain/Account/{Account,AccountType,AccountStatus,AccountRepository}.php
    src/Domain/Account/Event/AccountOpened.php
    src/Domain/Account/Exception/{AccountNotFound,AccountNotActive,InsufficientFunds,AccountHasBalance,SettlementAccountMissing}.php
    src/Domain/Ledger/Direction.php                                                         (Task 3)
    src/Domain/Ledger/{PostingLine,JournalEntry,Posting,JournalEntryRepository}.php
    src/Domain/Ledger/Exception/UnbalancedJournalEntry.php                                  (Task 4)
    src/Domain/Ledger/Exception/InvalidStatementPeriod.php                                  (Task 10)
    src/Domain/Transfer/{Transfer,TransferKind,TransferStatus,TransferRepository}.php
    src/Domain/Transfer/Event/{TransferEvent,TransferCompleted,DepositRecorded,WithdrawalRecorded}.php
    src/Domain/Transfer/Exception/{SameAccountTransfer,TransferNotFound,IdempotencyConflict}.php   (Task 5)
    src/Application/Port/{TransactionManager,DuplicateRecord}.php                           (Task 6)
    src/Application/Port/EventPublisher.php                                                 (Task 7)
    src/Application/Port/{StatementReader,StatementLine}.php                                (Task 10)
    src/Application/Account/{OpenAccountCommand,OpenAccountHandler,GetAccountHandler,AccountView}.php   (Task 8)
    src/Application/Transfer/{PostTransferCommand,PostTransferResult,PostTransferService,TransferView,
        TransferMoneyCommand,TransferMoneyHandler,RecordDepositCommand,RecordDepositHandler,
        RecordWithdrawalCommand,RecordWithdrawalHandler,GetTransferHandler}.php             (Task 9)
    src/Application/Statement/{StatementQuery,GetAccountStatementHandler,StatementView,StatementLineView}.php  (Task 10)
    src/Infrastructure/Http/Controller/{HealthController(1),AccountController(8),TransferController(9),StatementController(10)}.php
    src/Infrastructure/Http/Request/{OpenAccountRequest(8),TransferRequest,FundsRequest,IdempotencyKey,IdempotencyKeyValueResolver(9),StatementRequest(10)}.php
    src/Infrastructure/Http/ProblemDetails/ProblemDetailsListener.php                       (Task 8)
    src/Infrastructure/Persistence/Doctrine/{DoctrineTransactionManager,DoctrineAccountRepository,
        DoctrineJournalEntryRepository,DoctrineTransferRepository}.php                      (Task 6)
    src/Infrastructure/Persistence/Doctrine/Outbox/{OutboxMessage,OutboxEventPublisher}.php (Task 7)
    src/Infrastructure/Persistence/Doctrine/DbalStatementReader.php                         (Task 10)
    src/Infrastructure/Persistence/Doctrine/{BalanceVerifier,BalanceReport}.php             (Task 12)
    src/Infrastructure/Messaging/{IntegrationEvent,OutboxRelay,RelayResult}.php             (Task 11)
    src/Infrastructure/Console/{OutboxRelayCommand(11),VerifyBalancesCommand,DemoSeedCommand(12)}.php
    tests/Support/{IntegrationTestCase,ApiTestCase}.php                                     (Tasks 6, 8)
    tests/Unit/…, tests/Integration/…, tests/Functional/…, tests/Concurrency/…
```

These are deleted in Task 1:
- the whole current `src/Application`, `src/Domain` and `src/Infrastructure` draft, which doesn't compile and is rewritten
- `src/Entity`, `src/Repository` and `src/Controller`

---

### Task 1: Tooling, container runtime and health endpoint

**Files:**
- Modify: `services/ledger-service/composer.json` (through composer commands only)
- Modify: `services/ledger-service/config/packages/doctrine.yaml`, `.env`, `.env.test`, `phpunit.dist.xml`, `phpstan.dist.neon`
- Create: `services/ledger-service/Dockerfile`, `.dockerignore`, `docker/nginx.conf`, `docker/supervisord.conf`, `docker/php.ini`, `docker/php-fpm.conf`
- Create: `docker-compose.yml`, `Makefile` (repo root, replacing the stubs)
- Create: `services/ledger-service/src/Infrastructure/Http/Controller/HealthController.php`
- Delete: the existing draft `src/Application/**`, `src/Domain/**`, `src/Infrastructure/**`, `src/Entity`, `src/Repository`, `src/Controller`
- Test: `services/ledger-service/tests/Functional/HealthTest.php`

**Interfaces:**
- Produces:
  - `make up | install | migrate | test-db | test | check`
  - the `ledger-service` container, with source mounted at `/app` and contracts at `/contracts`
  - env vars `DATABASE_URL`, `LEDGER_EVENTS_TRANSPORT_DSN`, `LOCK_DSN` and `CONTRACTS_DIR`

- [ ] **Step 1: Remove the uncompilable draft and skeleton folders**

```bash
cd services/ledger-service
git rm -rq src/Application src/Domain src/Infrastructure src/Entity src/Repository src/Controller
```

- [ ] **Step 2: Install and remove packages through Composer (Flex recipes do the wiring)**

```bash
composer config extra.symfony.allow-contrib true
composer remove predis/predis
composer require symfony/messenger symfony/redis-messenger symfony/lock symfony/clock symfony/monolog-bundle moneyphp/money "ext-bcmath:*" "ext-pdo_pgsql:*" "ext-redis:*" "ext-intl:*"
composer require --dev dama/doctrine-test-bundle symfony/process
```

`symfony/browser-kit` and `symfony/css-selector` are already installed, so `symfony/test-pack` would only add `symfony/phpunit-bridge`, which PHPUnit 13 doesn't need. Skip it.

Expected: the recipes create `config/packages/{messenger,lock,monolog,dama_doctrine_test_bundle}.yaml` and register the bundles, and `.env` gains `MESSENGER_TRANSPORT_DSN` and `LOCK_DSN`.

- [ ] **Step 3: Point Doctrine at the domain and outbox folders**

Replace the `orm.mappings` block in `config/packages/doctrine.yaml` with:

```yaml
        mappings:
            Ledger:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Domain'
                prefix: 'App\Domain'
                alias: Ledger
            Outbox:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Infrastructure/Persistence/Doctrine/Outbox'
                prefix: 'App\Infrastructure\Persistence\Doctrine\Outbox'
                alias: Outbox
```

Create the empty folders `src/Domain` and `src/Infrastructure/Persistence/Doctrine/Outbox`, each with a `.gitkeep`. Doctrine fails to boot if a mapping dir is missing.

- [ ] **Step 4: Set env defaults**

In `.env`:
- set `DATABASE_URL="postgresql://ledger:ledger@postgres:5432/ledger?serverVersion=16&charset=utf8"`
- replace the recipe's `MESSENGER_TRANSPORT_DSN` block with:

```dotenv
###> ledger events ###
LEDGER_EVENTS_TRANSPORT_DSN=redis://redis:6379/ledger_events?serializer=0
###< ledger events ###
```

- keep `LOCK_DSN=flock`

Append to `.env.test`:

```dotenv
LEDGER_EVENTS_TRANSPORT_DSN=in-memory://
```

Replace the recipe `config/packages/messenger.yaml` with:

```yaml
framework:
    messenger:
        transports:
            ledger_events:
                dsn: '%env(LEDGER_EVENTS_TRANSPORT_DSN)%'
                serializer: messenger.transport.symfony_serializer
        routing: { }
```

- [ ] **Step 5: Register the DAMA extension in `phpunit.dist.xml`** (only if the recipe didn't)

```xml
    <extensions>
        <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
    </extensions>
```

- [ ] **Step 6: Point PHPStan at the Symfony container**

`phpstan.dist.neon`:

```neon
parameters:
    level: 9
    paths:
        - bin/
        - config/
        - public/
        - src/
        - tests/
    symfony:
        containerXmlPath: var/cache/dev/App_KernelDevDebugContainer.xml
```

- [ ] **Step 7: Write the container files**

`services/ledger-service/Dockerfile`:

```dockerfile
# syntax=docker/dockerfile:1
FROM php:8.4-fpm-alpine AS base
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql intl opcache bcmath redis pcntl \
    && apk add --no-cache nginx supervisor
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
EXPOSE 80
CMD ["supervisord", "-c", "/etc/supervisord.conf"]

FROM base AS dev
ENV APP_ENV=dev

FROM base AS prod
ENV APP_ENV=prod APP_DEBUG=0
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && php bin/console cache:warmup \
    && chown -R www-data:www-data var
```

`services/ledger-service/.dockerignore`:

```
var/
vendor/
.env.local
.env.*.local
.phpunit.cache
.php-cs-fixer.cache
```

`services/ledger-service/docker/nginx.conf`:

```nginx
server {
    listen 80;
    server_name _;
    root /app/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    access_log /dev/stdout;
    error_log /dev/stderr warn;
}
```

`services/ledger-service/docker/supervisord.conf`:

```ini
[supervisord]
nodaemon=true
user=root
logfile=/dev/null
logfile_maxbytes=0
pidfile=/run/supervisord.pid

[program:php-fpm]
command=php-fpm -F
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:nginx]
command=nginx -g 'daemon off;'
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
```

`services/ledger-service/docker/php.ini`:

```ini
memory_limit = 256M
expose_php = Off
date.timezone = UTC
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

`services/ledger-service/docker/php-fpm.conf`. Without `clear_env = no`, FPM drops the compose env vars:

```ini
[www]
clear_env = no
catch_workers_output = yes
decorate_workers_output = no
```

Repo root `docker-compose.yml`:

```yaml
name: ledger-platform

x-ledger-service: &ledger-service
  build:
    context: ./services/ledger-service
    target: dev
  volumes:
    - ./services/ledger-service:/app
    - ./contracts:/contracts:ro
  environment:
    DATABASE_URL: postgresql://ledger:ledger@postgres:5432/ledger?serverVersion=16&charset=utf8
    LEDGER_EVENTS_TRANSPORT_DSN: redis://redis:6379/ledger_events?serializer=0
    CONTRACTS_DIR: /contracts
  depends_on:
    postgres:
      condition: service_healthy
    redis:
      condition: service_healthy

services:
  ledger-service:
    <<: *ledger-service
    ports:
      - "8081:80"

  ledger-outbox-relay:
    <<: *ledger-service
    command: ["php", "bin/console", "ledger:outbox:relay", "--loop"]
    restart: unless-stopped

  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER: ledger
      POSTGRES_PASSWORD: ledger
      POSTGRES_DB: ledger
    ports:
      - "5433:5432"
    volumes:
      - pgdata:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ledger -d ledger"]
      interval: 5s
      retries: 10

  redis:
    image: redis:7-alpine
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      retries: 10

volumes:
  pgdata:
```

Repo root `Makefile`. Recipes are indented with a tab:

```make
COMPOSE := docker compose
EXEC    := $(COMPOSE) exec ledger-service
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
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

seed: ## Load demo accounts and transfers
	$(CONSOLE) ledger:demo:seed

test-db: ## Create and migrate the test database
	$(CONSOLE) doctrine:database:create --if-not-exists --env=test
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --env=test

test: test-db ## Run the test suite
	$(EXEC) php bin/phpunit

check: test-db ## Lint, static analysis and tests
	$(EXEC) composer check

verify: ## Check cached balances against postings
	$(CONSOLE) ledger:verify-balances
```

- [ ] **Step 8: Write the failing health test**

`tests/Functional/HealthTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testHealthReportsDatabaseStatus(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","checks":{"database":"ok"}}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
```

- [ ] **Step 9: Start the stack and run the test to watch it fail**

```bash
cd ../.. && make up && make test-db
docker compose exec ledger-service php bin/phpunit tests/Functional/HealthTest.php
```

Expected: FAIL with 404 (no route).

- [ ] **Step 10: Implement `HealthController`**

`src/Infrastructure/Http/Controller/HealthController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1');
            $database = 'ok';
        } catch (\Throwable) {
            $database = 'down';
        }

        $healthy = 'ok' === $database;

        return $this->json(
            ['status' => $healthy ? 'ok' : 'degraded', 'checks' => ['database' => $database]],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
```

- [ ] **Step 11: Run the tests and check the running app**

```bash
docker compose exec ledger-service php bin/phpunit
curl -s localhost:8081/health
docker compose exec ledger-service php bin/console lint:container
```

Expected: the tests PASS, curl prints `{"status":"ok","checks":{"database":"ok"}}`, and `lint:container` reports OK.

- [ ] **Step 12: Run the gates and commit**

```bash
docker compose exec ledger-service sh -c 'vendor/bin/php-cs-fixer fix && vendor/bin/phpstan analyse --memory-limit=1G'
git add -A && git commit -m "build(ledger-service): add php-fpm/nginx container, compose stack and health check

Removes the uncompilable draft ahead of the rewrite, installs Messenger,
Lock, Clock, MoneyPHP and the DAMA test bundle through Flex, and maps
Doctrine to the domain folders."
```

---

### Task 2: Shared kernel (currencies, money parsing and formatting, events, base exception)

**Files:**
- Create: `src/Domain/Shared/SupportedCurrencies.php`, `MoneyParser.php`, `MoneyFormatter.php`, `DomainEvent.php`, `RecordsEvents.php`, `LedgerException.php`
- Create: `src/Domain/Shared/Exception/UnsupportedCurrency.php`, `InvalidAmount.php`, `CurrencyMismatch.php`
- Test: `tests/Unit/Domain/Shared/SupportedCurrenciesTest.php`, `MoneyParserTest.php`, `MoneyFormatterTest.php`, `RecordsEventsTest.php`

**Interfaces:**
- Produces:
  - `SupportedCurrencies::get(string $code): Currency`, which throws `UnsupportedCurrency`
  - `SupportedCurrencies::minorUnits(Currency): int`
  - `SupportedCurrencies::list(): CurrencyList`
  - `SupportedCurrencies::codes(): list<string>`
  - `MoneyParser::parsePositive(string $decimal, Currency $currency): Money`, which throws `InvalidAmount`
  - `MoneyFormatter::format(Money): string`
  - the `DomainEvent` interface: `eventName(): string`, `eventVersion(): int`, `aggregateId(): Uuid`, `occurredAt(): \DateTimeImmutable`, `payload(): array<string, mixed>`
  - the `RecordsEvents` trait: `protected record(DomainEvent)` and `public pullEvents(): list<DomainEvent>`
  - `abstract class LedgerException extends \DomainException`
  - `CurrencyMismatch::between(Currency $expected, Currency $actual)`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Domain/Shared/SupportedCurrenciesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Shared;

use App\Domain\Shared\Exception\UnsupportedCurrency;
use App\Domain\Shared\SupportedCurrencies;
use PHPUnit\Framework\TestCase;

final class SupportedCurrenciesTest extends TestCase
{
    public function testReturnsSupportedCurrencyNormalisingCase(): void
    {
        self::assertSame('USD', SupportedCurrencies::get(' usd ')->getCode());
    }

    public function testExposesMinorUnits(): void
    {
        self::assertSame(2, SupportedCurrencies::minorUnits(SupportedCurrencies::get('TZS')));
        self::assertSame(0, SupportedCurrencies::minorUnits(SupportedCurrencies::get('JPY')));
    }

    public function testRejectsUnsupportedCurrency(): void
    {
        $this->expectException(UnsupportedCurrency::class);
        $this->expectExceptionMessage('Currency "CHF" is not supported. Supported: USD, EUR, GBP, TZS, JPY.');

        SupportedCurrencies::get('CHF');
    }
}
```

`tests/Unit/Domain/Shared/MoneyParserTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Shared;

use App\Domain\Shared\Exception\InvalidAmount;
use App\Domain\Shared\MoneyParser;
use App\Domain\Shared\SupportedCurrencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyParserTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function validAmounts(): iterable
    {
        yield 'whole dollars' => ['50', 'USD', '5000'];
        yield 'two decimals' => ['10.05', 'USD', '1005'];
        yield 'one decimal' => ['0.1', 'EUR', '10'];
        yield 'yen has no minor units' => ['1200', 'JPY', '1200'];
        yield 'surrounding whitespace is trimmed' => [' 7.50 ', 'GBP', '750'];
    }

    #[DataProvider('validAmounts')]
    public function testParsesExactMinorUnits(string $decimal, string $currency, string $expectedMinor): void
    {
        $money = MoneyParser::parsePositive($decimal, SupportedCurrencies::get($currency));

        self::assertSame($expectedMinor, $money->getAmount());
        self::assertSame($currency, $money->getCurrency()->getCode());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidAmounts(): iterable
    {
        yield 'too many decimals' => ['10.001', 'USD'];
        yield 'decimals on yen' => ['10.5', 'JPY'];
        yield 'zero' => ['0.00', 'USD'];
        yield 'negative' => ['-5', 'USD'];
        yield 'exponent' => ['1e3', 'USD'];
        yield 'trailing dot' => ['10.', 'USD'];
        yield 'empty' => ['', 'USD'];
        yield 'letters' => ['ten', 'USD'];
        yield 'exceeds 64-bit minor units' => ['99999999999999999999999', 'USD'];
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectsInvalidAmounts(string $decimal, string $currency): void
    {
        $this->expectException(InvalidAmount::class);

        MoneyParser::parsePositive($decimal, SupportedCurrencies::get($currency));
    }
}
```

`tests/Unit/Domain/Shared/MoneyFormatterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Shared;

use App\Domain\Shared\MoneyFormatter;
use App\Domain\Shared\SupportedCurrencies;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class MoneyFormatterTest extends TestCase
{
    public function testFormatsUsingCurrencyPrecision(): void
    {
        self::assertSame('50.00', MoneyFormatter::format(new Money(5000, SupportedCurrencies::get('USD'))));
        self::assertSame('0.05', MoneyFormatter::format(new Money(5, SupportedCurrencies::get('EUR'))));
        self::assertSame('1200', MoneyFormatter::format(new Money(1200, SupportedCurrencies::get('JPY'))));
        self::assertSame('-12.34', MoneyFormatter::format(new Money(-1234, SupportedCurrencies::get('TZS'))));
    }
}
```

`tests/Unit/Domain/Shared/RecordsEventsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Shared;

use App\Domain\Shared\DomainEvent;
use App\Domain\Shared\RecordsEvents;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RecordsEventsTest extends TestCase
{
    public function testPullReturnsRecordedEventsOnce(): void
    {
        $event = new class implements DomainEvent {
            public function eventName(): string { return 'test.happened'; }
            public function eventVersion(): int { return 1; }
            public function aggregateId(): Uuid { return Uuid::v7(); }
            public function occurredAt(): \DateTimeImmutable { return new \DateTimeImmutable(); }
            public function payload(): array { return []; }
        };
        $aggregate = new class {
            use RecordsEvents;

            public function happen(DomainEvent $event): void { $this->record($event); }
        };

        $aggregate->happen($event);

        self::assertSame([$event], $aggregate->pullEvents());
        self::assertSame([], $aggregate->pullEvents());
    }
}
```

- [ ] **Step 2: Run the tests to watch them fail**

Run: `docker compose exec ledger-service php bin/phpunit tests/Unit/Domain/Shared`
Expected: FAIL with "Class App\Domain\Shared\SupportedCurrencies not found".

- [ ] **Step 3: Implement the shared kernel**

`src/Domain/Shared/LedgerException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Base class for business-rule violations. Carries no transport concerns:
 * the HTTP layer decides how each subtype is presented.
 */
abstract class LedgerException extends \DomainException
{
}
```

`src/Domain/Shared/Exception/UnsupportedCurrency.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

use App\Domain\Shared\LedgerException;
use App\Domain\Shared\SupportedCurrencies;

final class UnsupportedCurrency extends LedgerException
{
    public static function code(string $code): self
    {
        return new self(\sprintf(
            'Currency "%s" is not supported. Supported: %s.',
            $code,
            implode(', ', SupportedCurrencies::codes()),
        ));
    }
}
```

`src/Domain/Shared/Exception/InvalidAmount.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

use App\Domain\Shared\LedgerException;

final class InvalidAmount extends LedgerException
{
    public static function notDecimal(string $amount): self
    {
        return new self(\sprintf('Amount "%s" must be a positive decimal string such as "10.50".', $amount));
    }

    public static function tooPrecise(string $amount, string $currency, int $minorUnits): self
    {
        return new self(\sprintf('Amount "%s" has more than %d decimal places allowed for %s.', $amount, $minorUnits, $currency));
    }

    public static function notPositive(string $amount): self
    {
        return new self(\sprintf('Amount "%s" must be greater than zero.', $amount));
    }

    public static function tooLarge(string $amount): self
    {
        return new self(\sprintf('Amount "%s" exceeds the maximum supported value.', $amount));
    }
}
```

`src/Domain/Shared/Exception/CurrencyMismatch.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

use App\Domain\Shared\LedgerException;
use Money\Currency;

final class CurrencyMismatch extends LedgerException
{
    public static function between(Currency $expected, Currency $actual): self
    {
        return new self(\sprintf('Currency mismatch: expected %s, got %s.', $expected->getCode(), $actual->getCode()));
    }
}
```

`src/Domain/Shared/SupportedCurrencies.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Shared\Exception\UnsupportedCurrency;
use Money\Currencies\CurrencyList;
use Money\Currency;

/**
 * The currencies this ledger accepts, with their ISO-4217 minor-unit exponent.
 * Every entry needs a settlement account (see the settlement seed migration).
 */
final class SupportedCurrencies
{
    private const array MINOR_UNITS = [
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'TZS' => 2,
        'JPY' => 0,
    ];

    public static function get(string $code): Currency
    {
        $code = strtoupper(trim($code));
        if ('' === $code || !\array_key_exists($code, self::MINOR_UNITS)) {
            throw UnsupportedCurrency::code($code);
        }

        return new Currency($code);
    }

    public static function minorUnits(Currency $currency): int
    {
        return self::list()->subunitFor($currency);
    }

    public static function list(): CurrencyList
    {
        return new CurrencyList(self::MINOR_UNITS);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::MINOR_UNITS);
    }
}
```

`src/Domain/Shared/MoneyParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Shared\Exception\InvalidAmount;
use Money\Currency;
use Money\Money;
use Money\Parser\DecimalMoneyParser;

/**
 * Turns an API decimal string into exact minor units. Stricter than MoneyPHP's
 * parser: no signs, exponents or excess precision, and the result must fit in
 * a 64-bit integer so it can be stored as BIGINT.
 */
final class MoneyParser
{
    private const string DECIMAL_PATTERN = '/^\d+(?:\.(\d+))?$/';

    public static function parsePositive(string $decimal, Currency $currency): Money
    {
        $decimal = trim($decimal);
        if (1 !== preg_match(self::DECIMAL_PATTERN, $decimal, $matches)) {
            throw InvalidAmount::notDecimal($decimal);
        }

        $minorUnits = SupportedCurrencies::minorUnits($currency);
        if (\strlen($matches[1] ?? '') > $minorUnits) {
            throw InvalidAmount::tooPrecise($decimal, $currency->getCode(), $minorUnits);
        }

        $money = new DecimalMoneyParser(SupportedCurrencies::list())->parse($decimal, $currency);

        if (!$money->isPositive()) {
            throw InvalidAmount::notPositive($decimal);
        }
        if (bccomp($money->getAmount(), (string) \PHP_INT_MAX) > 0) {
            throw InvalidAmount::tooLarge($decimal);
        }

        return $money;
    }
}
```

`src/Domain/Shared/MoneyFormatter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;

final class MoneyFormatter
{
    public static function format(Money $money): string
    {
        return new DecimalMoneyFormatter(SupportedCurrencies::list())->format($money);
    }
}
```

`src/Domain/Shared/DomainEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Symfony\Component\Uid\Uuid;

interface DomainEvent
{
    public function eventName(): string;

    public function eventVersion(): int;

    public function aggregateId(): Uuid;

    public function occurredAt(): \DateTimeImmutable;

    /** @return array<string, mixed> */
    public function payload(): array;
}
```

`src/Domain/Shared/RecordsEvents.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Shared;

trait RecordsEvents
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    /** @return list<DomainEvent> */
    public function pullEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    protected function record(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `docker compose exec ledger-service php bin/phpunit tests/Unit/Domain/Shared`
Expected: PASS (all data sets).

- [ ] **Step 5: Run the gates and commit**

```bash
git add -A && git commit -m "feat(ledger-service): add shared kernel for currencies, money parsing and domain events"
```

---

### Task 3: Account aggregate

**Files:**
- Create: `src/Domain/Ledger/Direction.php`
- Create: `src/Domain/Account/Account.php`, `AccountType.php`, `AccountStatus.php`, `AccountRepository.php`, `Event/AccountOpened.php`
- Create: `src/Domain/Account/Exception/AccountNotFound.php`, `AccountNotActive.php`, `InsufficientFunds.php`, `AccountHasBalance.php`, `SettlementAccountMissing.php`
- Test: `tests/Unit/Domain/Account/AccountTest.php`

**Interfaces:**
- Consumes (Task 2): `SupportedCurrencies`, `MoneyFormatter`, `RecordsEvents`, `DomainEvent`, `LedgerException`, `CurrencyMismatch`
- Produces:
  - `enum Direction: string { DEBIT = 'debit'; CREDIT = 'credit' }`
  - `enum AccountType: string { CUSTOMER = 'customer'; SETTLEMENT = 'settlement' }`
  - `enum AccountStatus: string { ACTIVE = 'active'; FROZEN = 'frozen'; CLOSED = 'closed' }`
  - `Account::openCustomer(Uuid $id, string $name, Currency $currency, \DateTimeImmutable $now): Account`, which records `AccountOpened`
  - `Account::openSettlement(Uuid $id, Currency $currency, \DateTimeImmutable $now): Account`
  - `Account->id(): Uuid`, `name(): string`, `type(): AccountType`, `currency(): Currency`, `status(): AccountStatus`, `balance(): Money`, `createdAt(): \DateTimeImmutable`
  - `Account->applyPosting(Direction $direction, Money $amount, \DateTimeImmutable $now): Money`, which returns the resulting balance
  - `Account->freeze(\DateTimeImmutable $now): void` and `close(\DateTimeImmutable $now): void`
  - the `AccountRepository` interface: `add(Account): void`, `get(Uuid): Account`, `getForUpdate(Uuid): Account`, `getSettlement(Currency): Account`
  - `AccountNotFound::withId(Uuid)`, `AccountNotActive::forAccount(Uuid, AccountStatus)`, `InsufficientFunds::forAccount(Uuid, Money $balance, Money $requested)`, `AccountHasBalance::forAccount(Uuid, Money)`, `SettlementAccountMissing::forCurrency(Currency)`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Domain/Account/AccountTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Account;

use App\Domain\Account\Account;
use App\Domain\Account\AccountStatus;
use App\Domain\Account\AccountType;
use App\Domain\Account\Event\AccountOpened;
use App\Domain\Account\Exception\AccountHasBalance;
use App\Domain\Account\Exception\AccountNotActive;
use App\Domain\Account\Exception\InsufficientFunds;
use App\Domain\Ledger\Direction;
use App\Domain\Shared\Exception\CurrencyMismatch;
use App\Domain\Shared\SupportedCurrencies;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AccountTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-24 10:00:00');
    }

    public function testOpeningACustomerAccountStartsActiveWithZeroBalanceAndRecordsEvent(): void
    {
        $id = Uuid::v7();
        $account = Account::openCustomer($id, 'Alice', SupportedCurrencies::get('USD'), $this->now);

        self::assertTrue($id->equals($account->id()));
        self::assertSame(AccountType::CUSTOMER, $account->type());
        self::assertSame(AccountStatus::ACTIVE, $account->status());
        self::assertTrue($account->balance()->equals($this->usd(0)));

        $events = $account->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(AccountOpened::class, $events[0]);
        self::assertSame('ledger.account_opened', $events[0]->eventName());
        self::assertSame(
            ['accountId' => $id->toRfc4122(), 'name' => 'Alice', 'type' => 'customer', 'currency' => 'USD', 'openedAt' => '2026-09-24T10:00:00.000+00:00'],
            $events[0]->payload(),
        );
    }

    public function testCreditIncreasesAndDebitDecreasesBalance(): void
    {
        $account = $this->customer();

        self::assertTrue($account->applyPosting(Direction::CREDIT, $this->usd(1000), $this->now)->equals($this->usd(1000)));
        self::assertTrue($account->applyPosting(Direction::DEBIT, $this->usd(400), $this->now)->equals($this->usd(600)));
        self::assertTrue($account->balance()->equals($this->usd(600)));
    }

    public function testCustomerAccountCannotGoNegative(): void
    {
        $account = $this->customer();
        $account->applyPosting(Direction::CREDIT, $this->usd(100), $this->now);

        try {
            $account->applyPosting(Direction::DEBIT, $this->usd(101), $this->now);
            self::fail('Expected InsufficientFunds');
        } catch (InsufficientFunds $e) {
            self::assertStringContainsString('balance 1.00 USD, requested 1.01 USD', $e->getMessage());
        }
        self::assertTrue($account->balance()->equals($this->usd(100)), 'balance must be unchanged');
    }

    public function testSettlementAccountMayGoNegative(): void
    {
        $settlement = Account::openSettlement(Uuid::v7(), SupportedCurrencies::get('USD'), $this->now);

        $balance = $settlement->applyPosting(Direction::DEBIT, $this->usd(500), $this->now);

        self::assertTrue($balance->equals($this->usd(-500)));
        self::assertSame(AccountType::SETTLEMENT, $settlement->type());
    }

    public function testRejectsPostingInAnotherCurrency(): void
    {
        $this->expectException(CurrencyMismatch::class);

        $this->customer()->applyPosting(Direction::CREDIT, new Money(100, SupportedCurrencies::get('EUR')), $this->now);
    }

    public function testFrozenAccountRejectsPostings(): void
    {
        $account = $this->customer();
        $account->freeze($this->now);

        $this->expectException(AccountNotActive::class);

        $account->applyPosting(Direction::CREDIT, $this->usd(100), $this->now);
    }

    public function testCloseRequiresZeroBalance(): void
    {
        $account = $this->customer();
        $account->applyPosting(Direction::CREDIT, $this->usd(1), $this->now);

        $this->expectException(AccountHasBalance::class);

        $account->close($this->now);
    }

    public function testClosedAccountCannotBeFrozen(): void
    {
        $account = $this->customer();
        $account->close($this->now);
        self::assertSame(AccountStatus::CLOSED, $account->status());

        $this->expectException(AccountNotActive::class);

        $account->freeze($this->now);
    }

    private function customer(): Account
    {
        return Account::openCustomer(Uuid::v7(), 'Alice', SupportedCurrencies::get('USD'), $this->now);
    }

    private function usd(int $minor): Money
    {
        return new Money($minor, SupportedCurrencies::get('USD'));
    }
}
```

The expected `openedAt` assumes the PHP default timezone is UTC. `docker/php.ini` sets it, and CI sets it through `setup-php`.

- [ ] **Step 2: Run the test to watch it fail**

Run: `docker compose exec ledger-service php bin/phpunit tests/Unit/Domain/Account`
Expected: FAIL with "Class App\Domain\Account\Account not found".

- [ ] **Step 3: Implement the enums, exceptions, event, repository port and aggregate**

`src/Domain/Ledger/Direction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

enum Direction: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';
}
```

`src/Domain/Account/AccountType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account;

enum AccountType: string
{
    case CUSTOMER = 'customer';
    case SETTLEMENT = 'settlement';
}
```

`src/Domain/Account/AccountStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account;

enum AccountStatus: string
{
    case ACTIVE = 'active';
    case FROZEN = 'frozen';
    case CLOSED = 'closed';
}
```

`src/Domain/Account/Exception/AccountNotFound.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account\Exception;

use App\Domain\Shared\LedgerException;
use Symfony\Component\Uid\Uuid;

final class AccountNotFound extends LedgerException
{
    public static function withId(Uuid $id): self
    {
        return new self(\sprintf('Account %s was not found.', $id->toRfc4122()));
    }
}
```

`src/Domain/Account/Exception/AccountNotActive.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account\Exception;

use App\Domain\Account\AccountStatus;
use App\Domain\Shared\LedgerException;
use Symfony\Component\Uid\Uuid;

final class AccountNotActive extends LedgerException
{
    public static function forAccount(Uuid $id, AccountStatus $status): self
    {
        return new self(\sprintf('Account %s is %s.', $id->toRfc4122(), $status->value));
    }
}
```

`src/Domain/Account/Exception/InsufficientFunds.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account\Exception;

use App\Domain\Shared\LedgerException;
use App\Domain\Shared\MoneyFormatter;
use Money\Money;
use Symfony\Component\Uid\Uuid;

final class InsufficientFunds extends LedgerException
{
    public static function forAccount(Uuid $id, Money $balance, Money $requested): self
    {
        return new self(\sprintf(
            'Account %s has insufficient funds: balance %s %s, requested %s %s.',
            $id->toRfc4122(),
            MoneyFormatter::format($balance),
            $balance->getCurrency()->getCode(),
            MoneyFormatter::format($requested),
            $requested->getCurrency()->getCode(),
        ));
    }
}
```

`src/Domain/Account/Exception/AccountHasBalance.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account\Exception;

use App\Domain\Shared\LedgerException;
use App\Domain\Shared\MoneyFormatter;
use Money\Money;
use Symfony\Component\Uid\Uuid;

final class AccountHasBalance extends LedgerException
{
    public static function forAccount(Uuid $id, Money $balance): self
    {
        return new self(\sprintf(
            'Account %s cannot be closed with a balance of %s %s.',
            $id->toRfc4122(),
            MoneyFormatter::format($balance),
            $balance->getCurrency()->getCode(),
        ));
    }
}
```

`src/Domain/Account/Exception/SettlementAccountMissing.php`. It's a configuration fault rather than a business rule, so it becomes a 500:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account\Exception;

use Money\Currency;

final class SettlementAccountMissing extends \LogicException
{
    public static function forCurrency(Currency $currency): self
    {
        return new self(\sprintf('No settlement account exists for %s; run the migrations.', $currency->getCode()));
    }
}
```

`src/Domain/Account/Event/AccountOpened.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account\Event;

use App\Domain\Account\AccountType;
use App\Domain\Shared\DomainEvent;
use Symfony\Component\Uid\Uuid;

final readonly class AccountOpened implements DomainEvent
{
    public function __construct(
        public Uuid $accountId,
        public string $name,
        public AccountType $type,
        public string $currency,
        public \DateTimeImmutable $openedAt,
    ) {
    }

    public function eventName(): string
    {
        return 'ledger.account_opened';
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function aggregateId(): Uuid
    {
        return $this->accountId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function payload(): array
    {
        return [
            'accountId' => $this->accountId->toRfc4122(),
            'name' => $this->name,
            'type' => $this->type->value,
            'currency' => $this->currency,
            'openedAt' => $this->openedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
```

`src/Domain/Account/AccountRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Domain\Account\Exception\AccountNotFound;
use App\Domain\Account\Exception\SettlementAccountMissing;
use Money\Currency;
use Symfony\Component\Uid\Uuid;

interface AccountRepository
{
    public function add(Account $account): void;

    /** @throws AccountNotFound */
    public function get(Uuid $id): Account;

    /**
     * Loads the account with a row lock (SELECT ... FOR UPDATE), refreshing any
     * copy already in memory. Must be called inside a transaction.
     *
     * @throws AccountNotFound
     */
    public function getForUpdate(Uuid $id): Account;

    /** @throws SettlementAccountMissing */
    public function getSettlement(Currency $currency): Account;
}
```

`src/Domain/Account/Account.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Domain\Account\Event\AccountOpened;
use App\Domain\Account\Exception\AccountHasBalance;
use App\Domain\Account\Exception\AccountNotActive;
use App\Domain\Account\Exception\InsufficientFunds;
use App\Domain\Ledger\Direction;
use App\Domain\Shared\Exception\CurrencyMismatch;
use App\Domain\Shared\RecordsEvents;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * A ledger account. The balance is a cache of the account's postings, kept in
 * step under a row lock; postings remain the source of truth.
 *
 * Sign convention: CREDIT increases the balance and DEBIT decreases it, for
 * every account type. Settlement accounts therefore hold the negative of the
 * money in custody, and balances within one currency always sum to zero.
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
#[ORM\Index(name: 'idx_accounts_type_currency', columns: ['type', 'currency'])]
class Account
{
    use RecordsEvents;

    #[ORM\Column(type: Types::BIGINT)]
    private int $balanceMinor = 0;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @param non-empty-string $currency */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid', unique: true)]
        private Uuid $id,
        #[ORM\Column(length: 120)]
        private string $name,
        #[ORM\Column(length: 20, enumType: AccountType::class)]
        private AccountType $type,
        #[ORM\Column(length: 3)]
        private string $currency,
        #[ORM\Column(length: 20, enumType: AccountStatus::class)]
        private AccountStatus $status,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public static function openCustomer(Uuid $id, string $name, Currency $currency, \DateTimeImmutable $now): self
    {
        $account = new self($id, $name, AccountType::CUSTOMER, $currency->getCode(), AccountStatus::ACTIVE, $now);
        $account->record(new AccountOpened($id, $name, AccountType::CUSTOMER, $currency->getCode(), $now));

        return $account;
    }

    public static function openSettlement(Uuid $id, Currency $currency, \DateTimeImmutable $now): self
    {
        $name = \sprintf('Settlement %s', $currency->getCode());
        $account = new self($id, $name, AccountType::SETTLEMENT, $currency->getCode(), AccountStatus::ACTIVE, $now);
        $account->record(new AccountOpened($id, $name, AccountType::SETTLEMENT, $currency->getCode(), $now));

        return $account;
    }

    /**
     * Applies one side of a journal entry and returns the resulting balance.
     */
    public function applyPosting(Direction $direction, Money $amount, \DateTimeImmutable $now): Money
    {
        if (!$amount->getCurrency()->equals($this->currency())) {
            throw CurrencyMismatch::between($this->currency(), $amount->getCurrency());
        }
        if (AccountStatus::ACTIVE !== $this->status) {
            throw AccountNotActive::forAccount($this->id, $this->status);
        }

        $balance = Direction::CREDIT === $direction
            ? $this->balance()->add($amount)
            : $this->balance()->subtract($amount);

        if (AccountType::CUSTOMER === $this->type && $balance->isNegative()) {
            throw InsufficientFunds::forAccount($this->id, $this->balance(), $amount);
        }

        $this->balanceMinor = (int) $balance->getAmount();
        $this->updatedAt = $now;

        return $balance;
    }

    public function freeze(\DateTimeImmutable $now): void
    {
        if (AccountStatus::CLOSED === $this->status) {
            throw AccountNotActive::forAccount($this->id, $this->status);
        }
        $this->status = AccountStatus::FROZEN;
        $this->updatedAt = $now;
    }

    public function close(\DateTimeImmutable $now): void
    {
        if (!$this->balance()->isZero()) {
            throw AccountHasBalance::forAccount($this->id, $this->balance());
        }
        $this->status = AccountStatus::CLOSED;
        $this->updatedAt = $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): AccountType
    {
        return $this->type;
    }

    public function currency(): Currency
    {
        return new Currency($this->currency);
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    public function balance(): Money
    {
        return new Money($this->balanceMinor, $this->currency());
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

- [ ] **Step 4: Run the test to see it pass**

Run: `docker compose exec ledger-service php bin/phpunit tests/Unit/Domain/Account`
Expected: PASS.

- [ ] **Step 5: Run the gates and commit**

```bash
git add -A && git commit -m "feat(ledger-service): add Account aggregate with balance rules and status gating"
```

---

### Task 4: Ledger (journal entries and postings)

**Files:**
- Create: `src/Domain/Ledger/PostingLine.php`, `JournalEntry.php`, `Posting.php`, `JournalEntryRepository.php`, `Exception/UnbalancedJournalEntry.php`
- Test: `tests/Unit/Domain/Ledger/JournalEntryTest.php`

**Interfaces:**
- Consumes: `Direction` (Task 3), `SupportedCurrencies` and `MoneyFormatter` (Task 2)
- Produces:
  - `final readonly class PostingLine(Uuid $accountId, Direction $direction, Money $amount, Money $balanceAfter)`
  - `JournalEntry::record(Uuid $id, \DateTimeImmutable $occurredAt, ?string $description, string $sourceType, Uuid $sourceId, list<PostingLine> $lines): JournalEntry`
  - `JournalEntry->id()`, `occurredAt()`, `description()`, `sourceType()`, `sourceId()`, and `postings(): list<Posting>`
  - `Posting->id(): Uuid`, `accountId(): Uuid`, `direction(): Direction`, `amount(): Money`, `balanceAfter(): Money`, `occurredAt(): \DateTimeImmutable`
  - the `JournalEntryRepository` interface: `add(JournalEntry): void`
  - `UnbalancedJournalEntry` with the factories `tooFewLines(int)`, `mixedCurrencies()`, `nonPositiveAmount()` and `debitsNotEqualCredits(Money, Money)`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Domain/Ledger/JournalEntryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ledger;

use App\Domain\Ledger\Direction;
use App\Domain\Ledger\Exception\UnbalancedJournalEntry;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\PostingLine;
use App\Domain\Shared\SupportedCurrencies;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class JournalEntryTest extends TestCase
{
    public function testRecordsBalancedEntryWithOnePostingPerLine(): void
    {
        $from = Uuid::v7();
        $to = Uuid::v7();
        $at = new \DateTimeImmutable('2026-09-24 10:00:00');

        $entry = JournalEntry::record(Uuid::v7(), $at, 'Rent', 'transfer', Uuid::v7(), [
            new PostingLine($from, Direction::DEBIT, $this->usd(5000), $this->usd(1000)),
            new PostingLine($to, Direction::CREDIT, $this->usd(5000), $this->usd(5000)),
        ]);

        $postings = $entry->postings();
        self::assertCount(2, $postings);
        self::assertTrue($from->equals($postings[0]->accountId()));
        self::assertSame(Direction::DEBIT, $postings[0]->direction());
        self::assertTrue($postings[0]->amount()->equals($this->usd(5000)));
        self::assertTrue($postings[0]->balanceAfter()->equals($this->usd(1000)));
        self::assertEquals($at, $postings[1]->occurredAt());
        self::assertSame('Rent', $entry->description());
    }

    public function testRejectsDebitsNotEqualToCredits(): void
    {
        $this->expectException(UnbalancedJournalEntry::class);
        $this->expectExceptionMessage('debits 50.00 do not equal credits 49.99');

        $this->record([
            new PostingLine(Uuid::v7(), Direction::DEBIT, $this->usd(5000), $this->usd(0)),
            new PostingLine(Uuid::v7(), Direction::CREDIT, $this->usd(4999), $this->usd(0)),
        ]);
    }

    public function testRejectsSingleLine(): void
    {
        $this->expectException(UnbalancedJournalEntry::class);

        $this->record([new PostingLine(Uuid::v7(), Direction::DEBIT, $this->usd(1), $this->usd(0))]);
    }

    public function testRejectsMixedCurrencies(): void
    {
        $this->expectException(UnbalancedJournalEntry::class);

        $eur = new Money(100, SupportedCurrencies::get('EUR'));
        $this->record([
            new PostingLine(Uuid::v7(), Direction::DEBIT, $this->usd(100), $this->usd(0)),
            new PostingLine(Uuid::v7(), Direction::CREDIT, $eur, $eur),
        ]);
    }

    public function testRejectsNonPositiveAmounts(): void
    {
        $this->expectException(UnbalancedJournalEntry::class);

        $this->record([
            new PostingLine(Uuid::v7(), Direction::DEBIT, $this->usd(0), $this->usd(0)),
            new PostingLine(Uuid::v7(), Direction::CREDIT, $this->usd(0), $this->usd(0)),
        ]);
    }

    /** @param list<PostingLine> $lines */
    private function record(array $lines): JournalEntry
    {
        return JournalEntry::record(Uuid::v7(), new \DateTimeImmutable(), null, 'transfer', Uuid::v7(), $lines);
    }

    private function usd(int $minor): Money
    {
        return new Money($minor, SupportedCurrencies::get('USD'));
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run: `docker compose exec ledger-service php bin/phpunit tests/Unit/Domain/Ledger`
Expected: FAIL with "Class App\Domain\Ledger\PostingLine not found".

- [ ] **Step 3: Implement the ledger types**

`src/Domain/Ledger/PostingLine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Money\Money;
use Symfony\Component\Uid\Uuid;

/** One side of a journal entry before it is recorded. */
final readonly class PostingLine
{
    public function __construct(
        public Uuid $accountId,
        public Direction $direction,
        public Money $amount,
        public Money $balanceAfter,
    ) {
    }
}
```

`src/Domain/Ledger/Exception/UnbalancedJournalEntry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exception;

use App\Domain\Shared\LedgerException;
use App\Domain\Shared\MoneyFormatter;
use Money\Money;

final class UnbalancedJournalEntry extends LedgerException
{
    public static function tooFewLines(int $count): self
    {
        return new self(\sprintf('A journal entry needs at least two postings, %d given.', $count));
    }

    public static function mixedCurrencies(): self
    {
        return new self('All postings in a journal entry must share one currency.');
    }

    public static function nonPositiveAmount(): self
    {
        return new self('Posting amounts must be greater than zero.');
    }

    public static function debitsNotEqualCredits(Money $debits, Money $credits): self
    {
        return new self(\sprintf(
            'Unbalanced journal entry: debits %s do not equal credits %s.',
            MoneyFormatter::format($debits),
            MoneyFormatter::format($credits),
        ));
    }
}
```

`src/Domain/Ledger/JournalEntryRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

interface JournalEntryRepository
{
    public function add(JournalEntry $entry): void;
}
```

`src/Domain/Ledger/JournalEntry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exception\UnbalancedJournalEntry;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * An immutable, balanced accounting record: total debits equal total credits,
 * in a single currency. Created only through record().
 */
#[ORM\Entity]
#[ORM\Table(name: 'journal_entries')]
#[ORM\Index(name: 'idx_journal_entries_source', columns: ['source_type', 'source_id'])]
class JournalEntry
{
    /** @var Collection<int, Posting> */
    #[ORM\OneToMany(targetEntity: Posting::class, mappedBy: 'journalEntry', cascade: ['persist'])]
    private Collection $postings;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid', unique: true)]
        private Uuid $id,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $description,
        #[ORM\Column(length: 30)]
        private string $sourceType,
        #[ORM\Column(type: 'uuid')]
        private Uuid $sourceId,
    ) {
        $this->postings = new ArrayCollection();
    }

    /** @param list<PostingLine> $lines */
    public static function record(
        Uuid $id,
        \DateTimeImmutable $occurredAt,
        ?string $description,
        string $sourceType,
        Uuid $sourceId,
        array $lines,
    ): self {
        self::assertBalanced($lines);

        $entry = new self($id, $occurredAt, $description, $sourceType, $sourceId);
        foreach ($lines as $line) {
            $entry->postings->add(new Posting(Uuid::v7(), $entry, $line, $occurredAt));
        }

        return $entry;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function sourceId(): Uuid
    {
        return $this->sourceId;
    }

    /** @return list<Posting> */
    public function postings(): array
    {
        return array_values($this->postings->toArray());
    }

    /** @param list<PostingLine> $lines */
    private static function assertBalanced(array $lines): void
    {
        if (\count($lines) < 2) {
            throw UnbalancedJournalEntry::tooFewLines(\count($lines));
        }

        $currency = $lines[0]->amount->getCurrency();
        $debits = new Money(0, $currency);
        $credits = new Money(0, $currency);

        foreach ($lines as $line) {
            if (!$line->amount->getCurrency()->equals($currency) || !$line->balanceAfter->getCurrency()->equals($currency)) {
                throw UnbalancedJournalEntry::mixedCurrencies();
            }
            if (!$line->amount->isPositive()) {
                throw UnbalancedJournalEntry::nonPositiveAmount();
            }

            if (Direction::DEBIT === $line->direction) {
                $debits = $debits->add($line->amount);
            } else {
                $credits = $credits->add($line->amount);
            }
        }

        if (!$debits->equals($credits)) {
            throw UnbalancedJournalEntry::debitsNotEqualCredits($debits, $credits);
        }
    }
}
```

`src/Domain/Ledger/Posting.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * One immutable line of a journal entry. Stores the account balance after this
 * posting, so statements read running balances instead of recomputing them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'postings')]
#[ORM\Index(name: 'idx_postings_account_time', columns: ['account_id', 'occurred_at', 'id'])]
class Posting
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: JournalEntry::class, inversedBy: 'postings')]
    #[ORM\JoinColumn(nullable: false)]
    private JournalEntry $journalEntry;

    #[ORM\Column(type: 'uuid')]
    private Uuid $accountId;

    #[ORM\Column(length: 6, enumType: Direction::class)]
    private Direction $direction;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amountMinor;

    #[ORM\Column(type: Types::BIGINT)]
    private int $balanceAfterMinor;

    /** @var non-empty-string */
    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** @internal Created only by JournalEntry::record() */
    public function __construct(Uuid $id, JournalEntry $journalEntry, PostingLine $line, \DateTimeImmutable $occurredAt)
    {
        $this->id = $id;
        $this->journalEntry = $journalEntry;
        $this->accountId = $line->accountId;
        $this->direction = $line->direction;
        $this->amountMinor = (int) $line->amount->getAmount();
        $this->balanceAfterMinor = (int) $line->balanceAfter->getAmount();
        $this->currency = $line->amount->getCurrency()->getCode();
        $this->occurredAt = $occurredAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function journalEntry(): JournalEntry
    {
        return $this->journalEntry;
    }

    public function accountId(): Uuid
    {
        return $this->accountId;
    }

    public function direction(): Direction
    {
        return $this->direction;
    }

    public function amount(): Money
    {
        return new Money($this->amountMinor, new Currency($this->currency));
    }

    public function balanceAfter(): Money
    {
        return new Money($this->balanceAfterMinor, new Currency($this->currency));
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
```

- [ ] **Step 4: Run the test to see it pass**

Run: `docker compose exec ledger-service php bin/phpunit tests/Unit/Domain/Ledger`
Expected: PASS.

- [ ] **Step 5: Run the gates and commit**

```bash
git add -A && git commit -m "feat(ledger-service): add balanced JournalEntry and immutable Posting"
```

---

### Task 5: Transfer aggregate and its events

**Files:**
- Create: `src/Domain/Transfer/Transfer.php`, `TransferKind.php`, `TransferStatus.php`, `TransferRepository.php`
- Create: `src/Domain/Transfer/Event/TransferEvent.php`, `TransferCompleted.php`, `DepositRecorded.php`, `WithdrawalRecorded.php`
- Create: `src/Domain/Transfer/Exception/SameAccountTransfer.php`, `TransferNotFound.php`, `IdempotencyConflict.php`
- Test: `tests/Unit/Domain/Transfer/TransferTest.php`

**Interfaces:**
- Consumes: `RecordsEvents`, `DomainEvent`, `MoneyFormatter`, `InvalidAmount` (Task 2)
- Produces:
  - `enum TransferKind: string { INTERNAL='internal'; DEPOSIT='deposit'; WITHDRAWAL='withdrawal' }`
  - `enum TransferStatus: string { COMPLETED='completed' }`
  - `Transfer::complete(Uuid $id, TransferKind $kind, Uuid $sourceAccountId, Uuid $destinationAccountId, Money $amount, ?string $description, string $idempotencyKey, string $requestHash, Uuid $journalEntryId, \DateTimeImmutable $now): Transfer`
  - getters `id()`, `kind()`, `sourceAccountId()`, `destinationAccountId()`, `amount(): Money`, `description()`, `idempotencyKey()`, `status()`, `journalEntryId()`, `createdAt()`
  - `matchesRequest(string $requestHash): bool`
  - the `TransferRepository` interface: `add(Transfer): void`, `get(Uuid): Transfer`, `findByIdempotencyKey(string): ?Transfer`
  - `TransferNotFound::withId(Uuid)`, `IdempotencyConflict::forKey(string)`, `SameAccountTransfer::forAccount(Uuid)`
  - event names: `ledger.transfer_completed`, `ledger.deposit_recorded`, `ledger.withdrawal_recorded`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Domain/Transfer/TransferTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Transfer;

use App\Domain\Shared\SupportedCurrencies;
use App\Domain\Transfer\Event\DepositRecorded;
use App\Domain\Transfer\Event\TransferCompleted;
use App\Domain\Transfer\Event\WithdrawalRecorded;
use App\Domain\Transfer\Exception\SameAccountTransfer;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferKind;
use App\Domain\Transfer\TransferStatus;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TransferTest extends TestCase
{
    public function testCompletesAndRecordsTransferCompletedWithContractPayload(): void
    {
        $id = Uuid::v7();
        $source = Uuid::v7();
        $destination = Uuid::v7();
        $entry = Uuid::v7();
        $at = new \DateTimeImmutable('2026-09-24 10:00:00');

        $transfer = Transfer::complete($id, TransferKind::INTERNAL, $source, $destination, new Money(12550, SupportedCurrencies::get('USD')), 'Dinner', 'key-1', str_repeat('a', 64), $entry, $at);

        self::assertSame(TransferStatus::COMPLETED, $transfer->status());
        self::assertTrue($transfer->matchesRequest(str_repeat('a', 64)));
        self::assertFalse($transfer->matchesRequest(str_repeat('b', 64)));

        $events = $transfer->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TransferCompleted::class, $events[0]);
        self::assertSame([
            'transferId' => $id->toRfc4122(),
            'journalEntryId' => $entry->toRfc4122(),
            'sourceAccountId' => $source->toRfc4122(),
            'destinationAccountId' => $destination->toRfc4122(),
            'amount' => '125.50',
            'amountMinor' => 12550,
            'currency' => 'USD',
            'description' => 'Dinner',
            'completedAt' => '2026-09-24T10:00:00.000+00:00',
        ], $events[0]->payload());
    }

    /** @return iterable<string, array{TransferKind, class-string, string}> */
    public static function kinds(): iterable
    {
        yield 'internal' => [TransferKind::INTERNAL, TransferCompleted::class, 'ledger.transfer_completed'];
        yield 'deposit' => [TransferKind::DEPOSIT, DepositRecorded::class, 'ledger.deposit_recorded'];
        yield 'withdrawal' => [TransferKind::WITHDRAWAL, WithdrawalRecorded::class, 'ledger.withdrawal_recorded'];
    }

    /** @param class-string $eventClass */
    #[DataProvider('kinds')]
    public function testRecordsEventMatchingKind(TransferKind $kind, string $eventClass, string $eventName): void
    {
        $transfer = Transfer::complete(Uuid::v7(), $kind, Uuid::v7(), Uuid::v7(), new Money(1, SupportedCurrencies::get('USD')), null, 'k', 'h', Uuid::v7(), new \DateTimeImmutable());

        $event = $transfer->pullEvents()[0];
        self::assertInstanceOf($eventClass, $event);
        self::assertSame($eventName, $event->eventName());
        self::assertTrue($transfer->id()->equals($event->aggregateId()));
    }

    public function testRejectsSameSourceAndDestination(): void
    {
        $account = Uuid::v7();

        $this->expectException(SameAccountTransfer::class);

        Transfer::complete(Uuid::v7(), TransferKind::INTERNAL, $account, Uuid::fromString($account->toRfc4122()), new Money(1, SupportedCurrencies::get('USD')), null, 'k', 'h', Uuid::v7(), new \DateTimeImmutable());
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run: `docker compose exec ledger-service php bin/phpunit tests/Unit/Domain/Transfer`
Expected: FAIL with "Class App\Domain\Transfer\Transfer not found".

- [ ] **Step 3: Implement**

`src/Domain/Transfer/TransferKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

enum TransferKind: string
{
    case INTERNAL = 'internal';
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
}
```

`src/Domain/Transfer/TransferStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

/**
 * Stage 1 posts transfers synchronously, so only COMPLETED is persisted.
 * PENDING and similar states arrive with two-phase transfers in stage 2.
 */
enum TransferStatus: string
{
    case COMPLETED = 'completed';
}
```

`src/Domain/Transfer/Exception/SameAccountTransfer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Exception;

use App\Domain\Shared\LedgerException;
use Symfony\Component\Uid\Uuid;

final class SameAccountTransfer extends LedgerException
{
    public static function forAccount(Uuid $accountId): self
    {
        return new self(\sprintf('Source and destination must differ; both are %s.', $accountId->toRfc4122()));
    }
}
```

`src/Domain/Transfer/Exception/TransferNotFound.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Exception;

use App\Domain\Shared\LedgerException;
use Symfony\Component\Uid\Uuid;

final class TransferNotFound extends LedgerException
{
    public static function withId(Uuid $id): self
    {
        return new self(\sprintf('Transfer %s was not found.', $id->toRfc4122()));
    }
}
```

`src/Domain/Transfer/Exception/IdempotencyConflict.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Exception;

use App\Domain\Shared\LedgerException;

final class IdempotencyConflict extends LedgerException
{
    public static function forKey(string $key): self
    {
        return new self(\sprintf('Idempotency-Key "%s" was already used with a different request.', $key));
    }
}
```

`src/Domain/Transfer/Event/TransferEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Event;

use App\Domain\Shared\DomainEvent;
use App\Domain\Shared\MoneyFormatter;
use Money\Money;
use Symfony\Component\Uid\Uuid;

/** Shared shape of the three money-movement events (contract v1). */
abstract readonly class TransferEvent implements DomainEvent
{
    public function __construct(
        public Uuid $transferId,
        public Uuid $journalEntryId,
        public Uuid $sourceAccountId,
        public Uuid $destinationAccountId,
        public Money $amount,
        public ?string $description,
        public \DateTimeImmutable $completedAt,
    ) {
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function aggregateId(): Uuid
    {
        return $this->transferId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function payload(): array
    {
        return [
            'transferId' => $this->transferId->toRfc4122(),
            'journalEntryId' => $this->journalEntryId->toRfc4122(),
            'sourceAccountId' => $this->sourceAccountId->toRfc4122(),
            'destinationAccountId' => $this->destinationAccountId->toRfc4122(),
            'amount' => MoneyFormatter::format($this->amount),
            'amountMinor' => (int) $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'description' => $this->description,
            'completedAt' => $this->completedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
```

`src/Domain/Transfer/Event/TransferCompleted.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Event;

final readonly class TransferCompleted extends TransferEvent
{
    public function eventName(): string
    {
        return 'ledger.transfer_completed';
    }
}
```

`src/Domain/Transfer/Event/DepositRecorded.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Event;

final readonly class DepositRecorded extends TransferEvent
{
    public function eventName(): string
    {
        return 'ledger.deposit_recorded';
    }
}
```

`src/Domain/Transfer/Event/WithdrawalRecorded.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Event;

final readonly class WithdrawalRecorded extends TransferEvent
{
    public function eventName(): string
    {
        return 'ledger.withdrawal_recorded';
    }
}
```

`src/Domain/Transfer/TransferRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Transfer\Exception\TransferNotFound;
use Symfony\Component\Uid\Uuid;

interface TransferRepository
{
    public function add(Transfer $transfer): void;

    /** @throws TransferNotFound */
    public function get(Uuid $id): Transfer;

    public function findByIdempotencyKey(string $idempotencyKey): ?Transfer;
}
```

`src/Domain/Transfer/Transfer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Shared\Exception\InvalidAmount;
use App\Domain\Shared\MoneyFormatter;
use App\Domain\Shared\RecordsEvents;
use App\Domain\Transfer\Event\DepositRecorded;
use App\Domain\Transfer\Event\TransferCompleted;
use App\Domain\Transfer\Event\WithdrawalRecorded;
use App\Domain\Transfer\Exception\SameAccountTransfer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * The business request to move money, linked to the journal entry that
 * records it. The idempotency key is unique, so a retried request can be
 * answered with the original result.
 */
#[ORM\Entity]
#[ORM\Table(name: 'transfers')]
class Transfer
{
    use RecordsEvents;

    /** @param non-empty-string $currency */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid', unique: true)]
        private Uuid $id,
        #[ORM\Column(length: 20, enumType: TransferKind::class)]
        private TransferKind $kind,
        #[ORM\Column(type: 'uuid')]
        private Uuid $sourceAccountId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $destinationAccountId,
        #[ORM\Column(type: Types::BIGINT)]
        private int $amountMinor,
        #[ORM\Column(length: 3)]
        private string $currency,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $description,
        #[ORM\Column(length: 255, unique: true)]
        private string $idempotencyKey,
        #[ORM\Column(length: 64)]
        private string $requestHash,
        #[ORM\Column(length: 20, enumType: TransferStatus::class)]
        private TransferStatus $status,
        #[ORM\Column(type: 'uuid')]
        private Uuid $journalEntryId,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function complete(
        Uuid $id,
        TransferKind $kind,
        Uuid $sourceAccountId,
        Uuid $destinationAccountId,
        Money $amount,
        ?string $description,
        string $idempotencyKey,
        string $requestHash,
        Uuid $journalEntryId,
        \DateTimeImmutable $now,
    ): self {
        if ($sourceAccountId->equals($destinationAccountId)) {
            throw SameAccountTransfer::forAccount($sourceAccountId);
        }
        if (!$amount->isPositive()) {
            throw InvalidAmount::notPositive(MoneyFormatter::format($amount));
        }

        $transfer = new self(
            $id,
            $kind,
            $sourceAccountId,
            $destinationAccountId,
            (int) $amount->getAmount(),
            $amount->getCurrency()->getCode(),
            $description,
            $idempotencyKey,
            $requestHash,
            TransferStatus::COMPLETED,
            $journalEntryId,
            $now,
        );

        $eventArgs = [$id, $journalEntryId, $sourceAccountId, $destinationAccountId, $amount, $description, $now];
        $transfer->record(match ($kind) {
            TransferKind::INTERNAL => new TransferCompleted(...$eventArgs),
            TransferKind::DEPOSIT => new DepositRecorded(...$eventArgs),
            TransferKind::WITHDRAWAL => new WithdrawalRecorded(...$eventArgs),
        });

        return $transfer;
    }

    public function matchesRequest(string $requestHash): bool
    {
        return hash_equals($this->requestHash, $requestHash);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function kind(): TransferKind
    {
        return $this->kind;
    }

    public function sourceAccountId(): Uuid
    {
        return $this->sourceAccountId;
    }

    public function destinationAccountId(): Uuid
    {
        return $this->destinationAccountId;
    }

    public function amount(): Money
    {
        return new Money($this->amountMinor, new Currency($this->currency));
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function status(): TransferStatus
    {
        return $this->status;
    }

    public function journalEntryId(): Uuid
    {
        return $this->journalEntryId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

- [ ] **Step 4: Run the test to see it pass**

Run: `docker compose exec ledger-service php bin/phpunit tests/Unit/Domain/Transfer`
Expected: PASS.

- [ ] **Step 5: Run the gates and commit**

```bash
git add -A && git commit -m "feat(ledger-service): add Transfer aggregate with idempotency hash and money-movement events"
```

---

### Task 6: Doctrine persistence, transactions and schema

**Files:**
- Create: `src/Application/Port/TransactionManager.php`, `src/Application/Port/DuplicateRecord.php`
- Create: `src/Infrastructure/Persistence/Doctrine/DoctrineTransactionManager.php`, `DoctrineAccountRepository.php`, `DoctrineJournalEntryRepository.php`, `DoctrineTransferRepository.php`
- Create: `migrations/Version<generated>.php` (schema, from diff) and `migrations/Version<generated>.php` (settlement seed)
- Create: `tests/Support/IntegrationTestCase.php`
- Test: `tests/Integration/Persistence/DoctrineAccountRepositoryTest.php`, `DoctrineTransactionManagerTest.php`

**Interfaces:**
- Consumes: the entities and repository ports from Tasks 3–5
- Produces:
  - `TransactionManager::transactional(callable(): T $operation): T`, which flushes and commits, or rolls back, discards the EM state and rethrows. A unique violation is translated to `DuplicateRecord`.
  - `DuplicateRecord extends \RuntimeException`
  - `IntegrationTestCase::service<T>(class-string<T>): T`, `em(): EntityManagerInterface` and `connection(): Connection`
  - Settlement account IDs, as constants on the seed migration class: USD `0199a000-0000-7000-8000-000000000840`, EUR `…000000000978`, GBP `…000000000826`, TZS `…000000000834`, JPY `…000000000392`

- [ ] **Step 1: Write the ports and the adapter code**

`src/Application/Port/TransactionManager.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Port;

interface TransactionManager
{
    /**
     * Runs the operation in one database transaction, flushing pending changes
     * before commit. On any exception the transaction is rolled back, unsaved
     * state is discarded and the exception is rethrown.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     *
     * @throws DuplicateRecord when a unique constraint rejects the changes
     */
    public function transactional(callable $operation): mixed;
}
```

`src/Application/Port/DuplicateRecord.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Port;

/** A write lost a race against another write with the same unique key. */
final class DuplicateRecord extends \RuntimeException
{
}
```

`src/Infrastructure/Persistence/Doctrine/DoctrineTransactionManager.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\DuplicateRecord;
use App\Application\Port\TransactionManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $result = $operation();
            $entityManager->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->discardUnitOfWork();

            if ($e instanceof UniqueConstraintViolationException) {
                throw new DuplicateRecord($e->getMessage(), previous: $e);
            }

            throw $e;
        }
    }

    /**
     * Domain rejections leave the EntityManager open but dirty, so clear it.
     * A failed flush closes it, so replace it; the injected EntityManager
     * service is a lazy object that the registry resets in place.
     */
    private function discardUnitOfWork(): void
    {
        $entityManager = $this->entityManager();
        if ($entityManager->isOpen()) {
            $entityManager->clear();

            return;
        }

        $this->registry->resetManager();
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager();
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }
}
```

`src/Infrastructure/Persistence/Doctrine/DoctrineAccountRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Account\AccountType;
use App\Domain\Account\Exception\AccountNotFound;
use App\Domain\Account\Exception\SettlementAccountMissing;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Money\Currency;
use Symfony\Component\Uid\Uuid;

final class DoctrineAccountRepository implements AccountRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function add(Account $account): void
    {
        $this->entityManager->persist($account);
    }

    public function get(Uuid $id): Account
    {
        return $this->entityManager->find(Account::class, $id) ?? throw AccountNotFound::withId($id);
    }

    public function getForUpdate(Uuid $id): Account
    {
        $account = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Account::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            // An earlier non-locking read may have cached a stale balance.
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $account instanceof Account ? $account : throw AccountNotFound::withId($id);
    }

    public function getSettlement(Currency $currency): Account
    {
        $account = $this->entityManager->getRepository(Account::class)->findOneBy([
            'type' => AccountType::SETTLEMENT,
            'currency' => $currency->getCode(),
        ]);

        return $account ?? throw SettlementAccountMissing::forCurrency($currency);
    }
}
```

`src/Infrastructure/Persistence/Doctrine/DoctrineJournalEntryRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\JournalEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineJournalEntryRepository implements JournalEntryRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function add(JournalEntry $entry): void
    {
        $this->entityManager->persist($entry);
    }
}
```

`src/Infrastructure/Persistence/Doctrine/DoctrineTransferRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Transfer\Exception\TransferNotFound;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineTransferRepository implements TransferRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function add(Transfer $transfer): void
    {
        $this->entityManager->persist($transfer);
    }

    public function get(Uuid $id): Transfer
    {
        return $this->entityManager->find(Transfer::class, $id) ?? throw TransferNotFound::withId($id);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?Transfer
    {
        return $this->entityManager->getRepository(Transfer::class)->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }
}
```

- [ ] **Step 2: Generate the schema migration and add the settlement seed**

```bash
docker compose exec ledger-service php bin/console doctrine:migrations:diff --no-interaction
docker compose exec ledger-service php bin/console doctrine:migrations:generate --no-interaction
```

Open the generated migration and check it creates `accounts`, `journal_entries`, `postings` and `transfers`, with a unique index on `transfers.idempotency_key` and a foreign key from `postings.journal_entry_id`. Rename the empty generated class body to seed the settlement accounts. This is data only; the schema still comes from the diff.

```php
    public const array SETTLEMENT_ACCOUNTS = [
        'USD' => '0199a000-0000-7000-8000-000000000840',
        'EUR' => '0199a000-0000-7000-8000-000000000978',
        'GBP' => '0199a000-0000-7000-8000-000000000826',
        'TZS' => '0199a000-0000-7000-8000-000000000834',
        'JPY' => '0199a000-0000-7000-8000-000000000392',
    ];

    public function getDescription(): string
    {
        return 'Seed one settlement account per supported currency';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SETTLEMENT_ACCOUNTS as $currency => $id) {
            $this->addSql(
                "INSERT INTO accounts (id, name, type, currency, status, balance_minor, version, created_at, updated_at)
                 VALUES (:id, :name, 'settlement', :currency, 'active', 0, 1, NOW(), NOW())",
                ['id' => $id, 'name' => 'Settlement '.$currency, 'currency' => $currency],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM accounts WHERE type = 'settlement'");
    }
```

Apply both, then check the mapping:

```bash
docker compose exec ledger-service php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec ledger-service php bin/console doctrine:migrations:migrate --no-interaction --env=test
docker compose exec ledger-service php bin/console doctrine:schema:validate
```

Expected: `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.`

- [ ] **Step 3: Write the failing integration tests**

`tests/Support/IntegrationTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class IntegrationTestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }

    protected function em(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    protected function connection(): Connection
    {
        return $this->service(Connection::class);
    }
}
```

`tests/Integration/Persistence/DoctrineAccountRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Application\Port\TransactionManager;
use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Account\AccountType;
use App\Domain\Account\Exception\AccountNotFound;
use App\Domain\Ledger\Direction;
use App\Domain\Shared\SupportedCurrencies;
use App\Tests\Support\IntegrationTestCase;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

final class DoctrineAccountRepositoryTest extends IntegrationTestCase
{
    public function testPersistsAndReloadsAccount(): void
    {
        $repository = $this->service(AccountRepository::class);
        $account = Account::openCustomer(Uuid::v7(), 'Alice', SupportedCurrencies::get('TZS'), new \DateTimeImmutable());
        $account->applyPosting(Direction::CREDIT, new Money(123456, SupportedCurrencies::get('TZS')), new \DateTimeImmutable());
        $repository->add($account);
        $this->em()->flush();
        $this->em()->clear();

        $reloaded = $repository->get($account->id());

        self::assertSame('Alice', $reloaded->name());
        self::assertSame('TZS', $reloaded->currency()->getCode());
        self::assertSame('123456', $reloaded->balance()->getAmount());
    }

    public function testGetThrowsForUnknownAccount(): void
    {
        $this->expectException(AccountNotFound::class);

        $this->service(AccountRepository::class)->get(Uuid::v7());
    }

    public function testGetForUpdateRefreshesStaleInMemoryBalance(): void
    {
        $repository = $this->service(AccountRepository::class);
        $account = Account::openCustomer(Uuid::v7(), 'Bob', SupportedCurrencies::get('USD'), new \DateTimeImmutable());
        $repository->add($account);
        $this->em()->flush();

        $this->connection()->executeStatement(
            'UPDATE accounts SET balance_minor = 999 WHERE id = :id',
            ['id' => $account->id()->toRfc4122()],
        );

        $locked = $this->service(TransactionManager::class)->transactional(
            fn (): Account => $repository->getForUpdate($account->id()),
        );

        self::assertSame('999', $locked->balance()->getAmount());
    }

    /** @return iterable<string, array{string}> */
    public static function supportedCurrencies(): iterable
    {
        foreach (SupportedCurrencies::codes() as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('supportedCurrencies')]
    public function testEverySupportedCurrencyHasASettlementAccount(string $code): void
    {
        $settlement = $this->service(AccountRepository::class)->getSettlement(SupportedCurrencies::get($code));

        self::assertSame(AccountType::SETTLEMENT, $settlement->type());
        self::assertSame($code, $settlement->currency()->getCode());
    }
}
```

`tests/Integration/Persistence/DoctrineTransactionManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Application\Port\DuplicateRecord;
use App\Application\Port\TransactionManager;
use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Shared\SupportedCurrencies;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferKind;
use App\Domain\Transfer\TransferRepository;
use App\Tests\Support\IntegrationTestCase;
use Money\Money;
use Symfony\Component\Uid\Uuid;

final class DoctrineTransactionManagerTest extends IntegrationTestCase
{
    public function testCommitsFlushedChanges(): void
    {
        $account = $this->newAccount();

        $this->service(TransactionManager::class)->transactional(function () use ($account): void {
            $this->service(AccountRepository::class)->add($account);
        });

        self::assertSame(1, $this->countAccounts($account->id()));
    }

    public function testRollsBackAndStaysUsableAfterAnException(): void
    {
        $account = $this->newAccount();

        try {
            $this->service(TransactionManager::class)->transactional(function () use ($account): void {
                $this->service(AccountRepository::class)->add($account);
                throw new \RuntimeException('boom');
            });
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(0, $this->countAccounts($account->id()));

        $next = $this->newAccount();
        $this->service(TransactionManager::class)->transactional(fn () => $this->service(AccountRepository::class)->add($next));
        self::assertSame(1, $this->countAccounts($next->id()));
    }

    public function testTranslatesUniqueViolationAndRecoversEntityManager(): void
    {
        $transactions = $this->service(TransactionManager::class);
        $transfers = $this->service(TransferRepository::class);
        $transactions->transactional(fn () => $transfers->add($this->transfer('dup-key')));

        try {
            $transactions->transactional(fn () => $transfers->add($this->transfer('dup-key')));
            self::fail('Expected DuplicateRecord');
        } catch (DuplicateRecord) {
        }

        self::assertNotNull($transfers->findByIdempotencyKey('dup-key'));
        $next = $this->newAccount();
        $transactions->transactional(fn () => $this->service(AccountRepository::class)->add($next));
        self::assertSame(1, $this->countAccounts($next->id()));
    }

    private function newAccount(): Account
    {
        return Account::openCustomer(Uuid::v7(), 'Tx', SupportedCurrencies::get('USD'), new \DateTimeImmutable());
    }

    private function transfer(string $key): Transfer
    {
        return Transfer::complete(Uuid::v7(), TransferKind::INTERNAL, Uuid::v7(), Uuid::v7(), new Money(1, SupportedCurrencies::get('USD')), null, $key, 'hash', Uuid::v7(), new \DateTimeImmutable());
    }

    private function countAccounts(Uuid $id): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM accounts WHERE id = :id', ['id' => $id->toRfc4122()]);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec ledger-service php bin/phpunit tests/Integration/Persistence`
Expected: PASS. If `testTranslatesUniqueViolationAndRecoversEntityManager` fails with "EntityManager is closed", the injected EM is not reset in place. Fix it by having the three repositories take `ManagerRegistry` and call `$registry->getManagerForClass(X::class)` on each use, then re-run.

- [ ] **Step 5: Run the gates and commit**

```bash
git add -A && git commit -m "feat(ledger-service): add Doctrine repositories, transaction manager and initial schema

Settlement accounts are seeded per supported currency by migration so the
money-movement path never has to race to create them."
```

---

### Task 7: Transactional outbox writer

**Files:**
- Create: `src/Application/Port/EventPublisher.php`
- Create: `src/Infrastructure/Persistence/Doctrine/Outbox/OutboxMessage.php`, `OutboxEventPublisher.php`
- Create: `migrations/Version<generated>.php` (from diff)
- Test: `tests/Integration/Persistence/OutboxEventPublisherTest.php`

**Interfaces:**
- Consumes: `DomainEvent` (Task 2), `TransactionManager` (Task 6)
- Produces:
  - `EventPublisher::publish(DomainEvent $event): void`, which only persists and flushes with the caller's transaction
  - `OutboxMessage::fromDomainEvent(DomainEvent): OutboxMessage`
  - `->id(): Uuid`, `eventName(): string`, `payload(): array<string,mixed>`, `publishedAt(): ?\DateTimeImmutable`, `attempts(): int`, `lastError(): ?string`
  - `markPublished(\DateTimeImmutable)`, `markFailed(string)`
  - `toEnvelope(): array{eventId:string, eventName:string, eventVersion:int, occurredAt:string, aggregateId:string, payload:array<string,mixed>}`

- [ ] **Step 1: Write the failing test**

`tests/Integration/Persistence/OutboxEventPublisherTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Application\Port\EventPublisher;
use App\Application\Port\TransactionManager;
use App\Domain\Account\AccountType;
use App\Domain\Account\Event\AccountOpened;
use App\Infrastructure\Persistence\Doctrine\Outbox\OutboxMessage;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\Uid\Uuid;

final class OutboxEventPublisherTest extends IntegrationTestCase
{
    public function testEventIsStoredWithTheTransaction(): void
    {
        $event = $this->event();

        $this->service(TransactionManager::class)->transactional(fn () => $this->service(EventPublisher::class)->publish($event));

        $message = $this->em()->getRepository(OutboxMessage::class)->findOneBy(['aggregateId' => $event->accountId]);
        self::assertInstanceOf(OutboxMessage::class, $message);
        self::assertSame('ledger.account_opened', $message->eventName());
        self::assertSame($event->payload(), $message->payload());
        self::assertNull($message->publishedAt());

        $envelope = $message->toEnvelope();
        self::assertSame($message->id()->toRfc4122(), $envelope['eventId']);
        self::assertSame(1, $envelope['eventVersion']);
        self::assertSame($event->accountId->toRfc4122(), $envelope['aggregateId']);
    }

    public function testEventIsDiscardedWhenTheTransactionRollsBack(): void
    {
        $event = $this->event();

        try {
            $this->service(TransactionManager::class)->transactional(function () use ($event): void {
                $this->service(EventPublisher::class)->publish($event);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM outbox_messages WHERE aggregate_id = :id',
            ['id' => $event->accountId->toRfc4122()],
        ));
    }

    private function event(): AccountOpened
    {
        return new AccountOpened(Uuid::v7(), 'Alice', AccountType::CUSTOMER, 'USD', new \DateTimeImmutable('2026-09-24 10:00:00'));
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run: `docker compose exec ledger-service php bin/phpunit tests/Integration/Persistence/OutboxEventPublisherTest.php`
Expected: FAIL with "Class App\Application\Port\EventPublisher not found".

- [ ] **Step 3: Implement**

`src/Application/Port/EventPublisher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Shared\DomainEvent;

interface EventPublisher
{
    /**
     * Records the event for delivery. It is only stored when the surrounding
     * TransactionManager::transactional() call commits.
     */
    public function publish(DomainEvent $event): void;
}
```

`src/Infrastructure/Persistence/Doctrine/Outbox/OutboxMessage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Outbox;

use App\Domain\Shared\DomainEvent;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'outbox_messages')]
#[ORM\Index(name: 'idx_outbox_pending', columns: ['published_at', 'occurred_at'])]
class OutboxMessage
{
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $attempts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    /** @param array<string, mixed> $payload */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid', unique: true)]
        private Uuid $id,
        #[ORM\Column(length: 100)]
        private string $eventName,
        #[ORM\Column(type: Types::INTEGER)]
        private int $eventVersion,
        #[ORM\Column(type: 'uuid')]
        private Uuid $aggregateId,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $payload,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
    ) {
    }

    public static function fromDomainEvent(DomainEvent $event): self
    {
        return new self(Uuid::v7(), $event->eventName(), $event->eventVersion(), $event->aggregateId(), $event->payload(), $event->occurredAt());
    }

    public function markPublished(\DateTimeImmutable $at): void
    {
        $this->publishedAt = $at;
        ++$this->attempts;
        $this->lastError = null;
    }

    public function markFailed(string $error): void
    {
        ++$this->attempts;
        $this->lastError = mb_substr($error, 0, 2000);
    }

    /**
     * @return array{eventId: string, eventName: string, eventVersion: int, occurredAt: string, aggregateId: string, payload: array<string, mixed>}
     */
    public function toEnvelope(): array
    {
        return [
            'eventId' => $this->id->toRfc4122(),
            'eventName' => $this->eventName,
            'eventVersion' => $this->eventVersion,
            'occurredAt' => $this->occurredAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'aggregateId' => $this->aggregateId->toRfc4122(),
            'payload' => $this->payload,
        ];
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function eventName(): string
    {
        return $this->eventName;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function publishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
```

`src/Infrastructure/Persistence/Doctrine/Outbox/OutboxEventPublisher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Outbox;

use App\Application\Port\EventPublisher;
use App\Domain\Shared\DomainEvent;
use Doctrine\ORM\EntityManagerInterface;

final class OutboxEventPublisher implements EventPublisher
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function publish(DomainEvent $event): void
    {
        $this->entityManager->persist(OutboxMessage::fromDomainEvent($event));
    }
}
```

Delete `src/Infrastructure/Persistence/Doctrine/Outbox/.gitkeep`.

- [ ] **Step 4: Generate and apply the migration, then run the tests**

```bash
docker compose exec ledger-service php bin/console doctrine:migrations:diff --no-interaction
docker compose exec ledger-service php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec ledger-service php bin/console doctrine:migrations:migrate --no-interaction --env=test
docker compose exec ledger-service php bin/console doctrine:schema:validate
docker compose exec ledger-service php bin/phpunit tests/Integration/Persistence
```

Expected: the schema is in sync and the tests PASS.

- [ ] **Step 5: Run the gates and commit**

```bash
git add -A && git commit -m "feat(ledger-service): write domain events to a transactional outbox"
```

---

### Task 8: Accounts API with problem+json errors

**Files:**
- Create: `src/Application/Account/OpenAccountCommand.php`, `OpenAccountHandler.php`, `GetAccountHandler.php`, `AccountView.php`
- Create: `src/Infrastructure/Http/Request/OpenAccountRequest.php`
- Create: `src/Infrastructure/Http/Controller/AccountController.php`
- Create: `src/Infrastructure/Http/ProblemDetails/ProblemDetailsListener.php`
- Create: `tests/Support/ApiTestCase.php`
- Test: `tests/Functional/AccountApiTest.php`

**Interfaces:**
- Consumes: `AccountRepository`, `TransactionManager`, `EventPublisher`, `SupportedCurrencies` and `MoneyFormatter`, plus `Psr\Clock\ClockInterface` (symfony/clock)
- Produces:
  - `OpenAccountHandler::handle(OpenAccountCommand): AccountView`
  - `GetAccountHandler::handle(Uuid): AccountView`
  - `AccountView{id, name, type, currency, status, balance, createdAt}`, all strings, via `AccountView::fromAccount(Account)`
  - the route names `account_open` and `account_show`
  - problem+json responses for every exception
  - `ApiTestCase` helpers: `request(string $method, string $uri, ?array $body = null, array $headers = []): Response`, `json(Response): array<string,mixed>`, `openAccount(string $currency = 'USD', string $name = 'Alice'): string`, `deposit(string $accountId, string $amount, ?string $key = null): Response`, `assertProblem(Response, int $status, string $type): array<string,mixed>`

- [ ] **Step 1: Write the failing functional test**

`tests/Support/ApiTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    protected function request(string $method, string $uri, ?array $body = null, array $headers = []): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /** @return array<string, mixed> */
    protected function json(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($data));

        /** @var array<string, mixed> $data */
        return $data;
    }

    protected function openAccount(string $currency = 'USD', string $name = 'Alice'): string
    {
        $response = $this->request('POST', '/accounts', ['name' => $name, 'currency' => $currency]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->json($response)['id'];
        \assert(\is_string($id));

        return $id;
    }

    protected function deposit(string $accountId, string $amount, ?string $key = null): Response
    {
        return $this->request('POST', "/accounts/{$accountId}/deposits", ['amount' => $amount], ['Idempotency-Key' => $key ?? bin2hex(random_bytes(8))]);
    }

    /** @return array<string, mixed> */
    protected function assertProblem(Response $response, int $status, string $type): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $problem = $this->json($response);
        self::assertSame($type, $problem['type']);
        self::assertSame($status, $problem['status']);

        return $problem;
    }
}
```

`tests/Functional/AccountApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;

final class AccountApiTest extends ApiTestCase
{
    public function testOpensAccountAndReturnsView(): void
    {
        $response = $this->request('POST', '/accounts', ['name' => 'Alice', 'currency' => 'USD']);

        self::assertSame(201, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('/accounts/'.$body['id'], $response->headers->get('Location'));
        self::assertSame('Alice', $body['name']);
        self::assertSame('customer', $body['type']);
        self::assertSame('USD', $body['currency']);
        self::assertSame('active', $body['status']);
        self::assertSame('0.00', $body['balance']);
    }

    public function testYenBalanceHasNoDecimals(): void
    {
        $id = $this->openAccount('JPY');

        self::assertSame('0', $this->json($this->request('GET', "/accounts/{$id}"))['balance']);
    }

    public function testShowReturnsTheOpenedAccount(): void
    {
        $id = $this->openAccount('EUR', 'Bob');

        $body = $this->json($this->request('GET', "/accounts/{$id}"));

        self::assertSame($id, $body['id']);
        self::assertSame('Bob', $body['name']);
    }

    public function testOpeningWritesAccountOpenedToTheOutbox(): void
    {
        $id = $this->openAccount();

        $count = self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM outbox_messages WHERE aggregate_id = :id AND event_name = 'ledger.account_opened'",
            ['id' => $id],
        );
        self::assertSame(1, (int) $count);
    }

    public function testUnknownAccountIsNotFoundProblem(): void
    {
        $problem = $this->assertProblem(
            $this->request('GET', '/accounts/0199a000-0000-7000-8000-00000000dead'),
            404,
            'urn:ledger:problem:account-not-found',
        );
        self::assertSame('/accounts/0199a000-0000-7000-8000-00000000dead', $problem['instance']);
    }

    public function testNonUuidPathIsNotFound(): void
    {
        $this->assertProblem($this->request('GET', '/accounts/not-a-uuid'), 404, 'about:blank');
    }

    public function testValidationErrorsListViolations(): void
    {
        $problem = $this->assertProblem(
            $this->request('POST', '/accounts', ['currency' => 'usd']),
            422,
            'urn:ledger:problem:validation-failed',
        );

        $fields = array_column((array) $problem['violations'], 'field');
        self::assertContains('name', $fields);
        self::assertContains('currency', $fields);
    }

    public function testUnsupportedCurrencyIsRejected(): void
    {
        $this->assertProblem(
            $this->request('POST', '/accounts', ['name' => 'Alice', 'currency' => 'CHF']),
            422,
            'urn:ledger:problem:unsupported-currency',
        );
    }

    public function testMalformedJsonIsBadRequest(): void
    {
        $this->client->request('POST', '/accounts', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":');

        $this->assertProblem($this->client->getResponse(), 400, 'about:blank');
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run: `docker compose exec ledger-service php bin/phpunit tests/Functional/AccountApiTest.php`
Expected: FAIL (404 for `POST /accounts`).

- [ ] **Step 3: Implement the application layer**

`src/Application/Account/OpenAccountCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Account;

final readonly class OpenAccountCommand
{
    public function __construct(public string $name, public string $currency)
    {
    }
}
```

`src/Application/Account/AccountView.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Account\Account;
use App\Domain\Shared\MoneyFormatter;

final readonly class AccountView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public string $currency,
        public string $status,
        public string $balance,
        public string $createdAt,
    ) {
    }

    public static function fromAccount(Account $account): self
    {
        return new self(
            $account->id()->toRfc4122(),
            $account->name(),
            $account->type()->value,
            $account->currency()->getCode(),
            $account->status()->value,
            MoneyFormatter::format($account->balance()),
            $account->createdAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
        );
    }
}
```

`src/Application/Account/OpenAccountHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Port\EventPublisher;
use App\Application\Port\TransactionManager;
use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Shared\SupportedCurrencies;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class OpenAccountHandler
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly TransactionManager $transactions,
        private readonly EventPublisher $events,
        private readonly ClockInterface $clock,
    ) {
    }

    public function handle(OpenAccountCommand $command): AccountView
    {
        $currency = SupportedCurrencies::get($command->currency);

        $account = $this->transactions->transactional(function () use ($command, $currency): Account {
            $account = Account::openCustomer(Uuid::v7(), trim($command->name), $currency, $this->clock->now());
            $this->accounts->add($account);
            foreach ($account->pullEvents() as $event) {
                $this->events->publish($event);
            }

            return $account;
        });

        return AccountView::fromAccount($account);
    }
}
```

`src/Application/Account/GetAccountHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Account\AccountRepository;
use Symfony\Component\Uid\Uuid;

final class GetAccountHandler
{
    public function __construct(private readonly AccountRepository $accounts)
    {
    }

    public function handle(Uuid $id): AccountView
    {
        return AccountView::fromAccount($this->accounts->get($id));
    }
}
```

- [ ] **Step 4: Implement the HTTP layer**

`src/Infrastructure/Http/Request/OpenAccountRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OpenAccountRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public string $name = '',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^[A-Z]{3}$/', message: 'Currency must be a three-letter uppercase ISO-4217 code.')]
        public string $currency = '',
    ) {
    }
}
```

`src/Infrastructure/Http/Controller/AccountController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Account\GetAccountHandler;
use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Infrastructure\Http\Request\OpenAccountRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route('/accounts')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly OpenAccountHandler $openAccount,
        private readonly GetAccountHandler $getAccount,
    ) {
    }

    #[Route('', name: 'account_open', methods: ['POST'])]
    public function open(#[MapRequestPayload] OpenAccountRequest $request): JsonResponse
    {
        $view = $this->openAccount->handle(new OpenAccountCommand($request->name, $request->currency));

        return $this->json($view, Response::HTTP_CREATED, [
            'Location' => $this->generateUrl('account_show', ['id' => $view->id]),
        ]);
    }

    #[Route('/{id}', name: 'account_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(Uuid $id): JsonResponse
    {
        return $this->json($this->getAccount->handle($id));
    }
}
```

`src/Infrastructure/Http/ProblemDetails/ProblemDetailsListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\ProblemDetails;

use App\Domain\Account\Exception\AccountNotFound;
use App\Domain\Shared\LedgerException;
use App\Domain\Transfer\Exception\IdempotencyConflict;
use App\Domain\Transfer\Exception\TransferNotFound;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Renders every error as RFC 9457 problem+json. Domain exceptions map to a
 * status here, so the domain stays free of HTTP concerns.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ProblemDetailsListener
{
    private const string TYPE_PREFIX = 'urn:ledger:problem:';

    /** Domain exceptions not listed here are business-rule violations: 422. */
    private const array DOMAIN_STATUS = [
        AccountNotFound::class => Response::HTTP_NOT_FOUND,
        TransferNotFound::class => Response::HTTP_NOT_FOUND,
        IdempotencyConflict::class => Response::HTTP_CONFLICT,
    ];

    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        $problem = match (true) {
            $exception instanceof LedgerException => $this->fromDomain($exception),
            $exception instanceof HttpExceptionInterface => $this->fromHttp($exception),
            default => $this->fromUnexpected($exception),
        };
        $problem['instance'] = $event->getRequest()->getPathInfo();

        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
        $headers['Content-Type'] = 'application/problem+json';

        $event->setResponse(new JsonResponse($problem, $problem['status'], $headers));
    }

    /** @return array{type: string, title: string, status: int, detail: string} */
    private function fromDomain(LedgerException $exception): array
    {
        $name = new \ReflectionClass($exception)->getShortName();

        return [
            'type' => self::TYPE_PREFIX.strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name)),
            'title' => trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name)),
            'status' => self::DOMAIN_STATUS[$exception::class] ?? Response::HTTP_UNPROCESSABLE_ENTITY,
            'detail' => $exception->getMessage(),
        ];
    }

    /** @return array{type: string, title: string, status: int, detail: string, violations?: list<array{field: string, message: string}>} */
    private function fromHttp(HttpExceptionInterface $exception): array
    {
        $status = $exception->getStatusCode();
        $previous = $exception instanceof \Throwable ? $exception->getPrevious() : null;

        if ($previous instanceof ValidationFailedException) {
            $violations = [];
            foreach ($previous->getViolations() as $violation) {
                $violations[] = ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
            }

            return [
                'type' => self::TYPE_PREFIX.'validation-failed',
                'title' => 'Validation Failed',
                'status' => $status,
                'detail' => 'The request contains invalid fields.',
                'violations' => $violations,
            ];
        }

        return [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $exception instanceof \Throwable ? $exception->getMessage() : '',
        ];
    }

    /** @return array{type: string, title: string, status: int, detail: string} */
    private function fromUnexpected(\Throwable $exception): array
    {
        $this->logger->error('Unhandled exception: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

        return [
            'type' => 'about:blank',
            'title' => 'Internal Server Error',
            'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
            'detail' => $this->debug ? $exception->getMessage() : 'An unexpected error occurred.',
        ];
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `docker compose exec ledger-service php bin/phpunit tests/Functional/AccountApiTest.php`
Expected: PASS. If `testMalformedJsonIsBadRequest` gets 422 instead of 400, check `vendor/symfony/http-kernel/Controller/ArgumentResolver/RequestPayloadValueResolver.php` to see which exception Symfony 8.1 throws for a syntax error, and align the test with the framework (400 `BadRequestHttpException` is expected).

- [ ] **Step 6: Run the gates and commit**

```bash
git add -A && git commit -m "feat(ledger-service): add accounts API with RFC 9457 problem responses"
```

---

### Task 9: Money movement (transfers, deposits, withdrawals) with idempotency

**Files:**
- Create: `src/Application/Transfer/PostTransferCommand.php`, `PostTransferResult.php`, `PostTransferService.php`, `TransferView.php`, `TransferMoneyCommand.php`, `TransferMoneyHandler.php`, `RecordDepositCommand.php`, `RecordDepositHandler.php`, `RecordWithdrawalCommand.php`, `RecordWithdrawalHandler.php`, `GetTransferHandler.php`
- Create: `src/Infrastructure/Http/Request/TransferRequest.php`, `FundsRequest.php`, `IdempotencyKey.php`, `IdempotencyKeyValueResolver.php`
- Create: `src/Infrastructure/Http/Controller/TransferController.php`
- Test: `tests/Integration/Application/PostTransferServiceTest.php`, `tests/Functional/TransferApiTest.php`

**Interfaces:**
- Consumes: the repositories, `TransactionManager`, `DuplicateRecord`, `EventPublisher`, `JournalEntry::record`, `Transfer::complete`, `Account::applyPosting`, `MoneyParser` and the clock
- Produces:
  - `PostTransferService::post(PostTransferCommand): PostTransferResult`
  - `PostTransferCommand(TransferKind $kind, Uuid $sourceAccountId, Uuid $destinationAccountId, string $amount, ?string $description, string $idempotencyKey)` with a computed `string $requestHash`
  - `PostTransferResult(TransferView $transfer, bool $replayed)`
  - `TransferView{id, kind, sourceAccountId, destinationAccountId, amount, currency, description, status, journalEntryId, createdAt}` via `TransferView::fromTransfer(Transfer)`
  - `TransferMoneyHandler::handle(TransferMoneyCommand(Uuid $sourceAccountId, Uuid $destinationAccountId, string $amount, ?string $description, string $idempotencyKey)): PostTransferResult`
  - `RecordDepositHandler::handle(RecordDepositCommand(Uuid $accountId, string $amount, ?string $description, string $idempotencyKey)): PostTransferResult`
  - `RecordWithdrawalHandler::handle(RecordWithdrawalCommand(Uuid $accountId, string $amount, ?string $description, string $idempotencyKey)): PostTransferResult`
  - `GetTransferHandler::handle(Uuid): TransferView`
  - the route names `transfer_create`, `transfer_show`, `account_deposit` and `account_withdrawal`
  - the response header `Idempotent-Replayed: true` on replays

- [ ] **Step 1: Write the failing integration test**

`tests/Integration/Application/PostTransferServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application;

use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Application\Port\TransactionManager;
use App\Application\Transfer\RecordDepositCommand;
use App\Application\Transfer\RecordDepositHandler;
use App\Application\Transfer\TransferMoneyCommand;
use App\Application\Transfer\TransferMoneyHandler;
use App\Domain\Account\AccountRepository;
use App\Domain\Account\Exception\AccountNotActive;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\Uid\Uuid;

final class PostTransferServiceTest extends IntegrationTestCase
{
    public function testTransferWritesBalancedEntryBalancesAndOneEvent(): void
    {
        $alice = $this->open('Alice');
        $bob = $this->open('Bob');
        $this->deposit($alice, '100.00');

        $result = $this->service(TransferMoneyHandler::class)->handle(
            new TransferMoneyCommand(Uuid::fromString($alice), Uuid::fromString($bob), '40.00', 'Rent', 'transfer-1'),
        );

        self::assertFalse($result->replayed);
        self::assertSame('40.00', $result->transfer->amount);

        $postings = $this->connection()->fetchAllAssociative(
            'SELECT account_id, direction, amount_minor, balance_after_minor FROM postings WHERE journal_entry_id = :id ORDER BY direction',
            ['id' => $result->transfer->journalEntryId],
        );
        self::assertEquals([
            ['account_id' => $bob, 'direction' => 'credit', 'amount_minor' => 4000, 'balance_after_minor' => 4000],
            ['account_id' => $alice, 'direction' => 'debit', 'amount_minor' => 4000, 'balance_after_minor' => 6000],
        ], $postings);

        self::assertSame(1, (int) $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM outbox_messages WHERE aggregate_id = :id AND event_name = 'ledger.transfer_completed'",
            ['id' => $result->transfer->id],
        ));
    }

    public function testFrozenAccountRejectsPostingAndPersistsNothing(): void
    {
        $alice = $this->open('Alice');
        $bob = $this->open('Bob');
        $this->deposit($alice, '100.00');
        $this->service(TransactionManager::class)->transactional(function () use ($bob): void {
            $this->service(AccountRepository::class)->get(Uuid::fromString($bob))->freeze(new \DateTimeImmutable());
        });
        $transfersBefore = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM transfers');
        $outboxBefore = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM outbox_messages');

        try {
            $this->service(TransferMoneyHandler::class)->handle(
                new TransferMoneyCommand(Uuid::fromString($alice), Uuid::fromString($bob), '10.00', null, 'frozen-1'),
            );
            self::fail('Expected AccountNotActive');
        } catch (AccountNotActive) {
        }

        self::assertSame($transfersBefore, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM transfers'));
        self::assertSame($outboxBefore, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM outbox_messages'));
        self::assertSame(10000, (int) $this->connection()->fetchOne('SELECT balance_minor FROM accounts WHERE id = :id', ['id' => $alice]));
    }

    private function open(string $name): string
    {
        return $this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand($name, 'USD'))->id;
    }

    private function deposit(string $accountId, string $amount): void
    {
        $this->service(RecordDepositHandler::class)->handle(
            new RecordDepositCommand(Uuid::fromString($accountId), $amount, null, 'deposit-'.$accountId),
        );
    }
}
```

- [ ] **Step 2: Write the failing functional test**

`tests/Functional/TransferApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TransferApiTest extends ApiTestCase
{
    private const string USD_SETTLEMENT = '0199a000-0000-7000-8000-000000000840';

    public function testDepositCreditsAccountFromSettlement(): void
    {
        $alice = $this->openAccount();

        $response = $this->deposit($alice, '100.00', 'dep-1');

        self::assertSame(201, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('deposit', $body['kind']);
        self::assertSame(self::USD_SETTLEMENT, $body['sourceAccountId']);
        self::assertSame($alice, $body['destinationAccountId']);
        self::assertSame('100.00', $body['amount']);
        self::assertSame('USD', $body['currency']);
        self::assertSame('completed', $body['status']);
        self::assertSame('100.00', $this->balance($alice));
    }

    public function testReplayingAKeyReturnsTheOriginalTransferOnce(): void
    {
        $alice = $this->openAccount();
        $first = $this->deposit($alice, '100.00', 'dep-replay');

        $second = $this->deposit($alice, '100.00', 'dep-replay');

        self::assertSame(200, $second->getStatusCode());
        self::assertSame('true', $second->headers->get('Idempotent-Replayed'));
        self::assertSame($this->json($first), $this->json($second));
        self::assertSame('100.00', $this->balance($alice));
    }

    public function testReusingAKeyWithADifferentRequestConflicts(): void
    {
        $alice = $this->openAccount();
        $this->deposit($alice, '100.00', 'dep-conflict');

        $this->assertProblem($this->deposit($alice, '99.00', 'dep-conflict'), 409, 'urn:ledger:problem:idempotency-conflict');
    }

    public function testMissingIdempotencyKeyIsBadRequest(): void
    {
        $alice = $this->openAccount();

        $problem = $this->assertProblem(
            $this->request('POST', "/accounts/{$alice}/deposits", ['amount' => '1.00']),
            400,
            'about:blank',
        );
        self::assertStringContainsString('Idempotency-Key', (string) $problem['detail']);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function malformedAmounts(): iterable
    {
        yield 'json number' => [10.5, 'urn:ledger:problem:validation-failed'];
        yield 'negative' => ['-5', 'urn:ledger:problem:validation-failed'];
        yield 'exponent' => ['1e3', 'urn:ledger:problem:validation-failed'];
        yield 'trailing dot' => ['10.', 'urn:ledger:problem:validation-failed'];
        yield 'too precise' => ['10.001', 'urn:ledger:problem:invalid-amount'];
        yield 'zero' => ['0', 'urn:ledger:problem:invalid-amount'];
        yield 'overflow' => ['9999999999999999999999999', 'urn:ledger:problem:invalid-amount'];
    }

    #[DataProvider('malformedAmounts')]
    public function testRejectsMalformedAmounts(mixed $amount, string $type): void
    {
        $alice = $this->openAccount();

        $response = $this->request('POST', "/accounts/{$alice}/deposits", ['amount' => $amount], ['Idempotency-Key' => 'bad-amount']);

        $this->assertProblem($response, 422, $type);
        self::assertSame('0.00', $this->balance($alice));
    }

    public function testDepositToUnknownAccountIsNotFound(): void
    {
        $this->assertProblem($this->deposit('0199a000-0000-7000-8000-00000000dead', '1.00'), 404, 'urn:ledger:problem:account-not-found');
    }

    public function testTransferMovesMoneyAndIsRetrievable(): void
    {
        [$alice, $bob] = [$this->openAccount(), $this->openAccount('USD', 'Bob')];
        $this->deposit($alice, '100.00');

        $response = $this->transfer($alice, $bob, '40.00', 'tr-1');

        self::assertSame(201, $response->getStatusCode());
        $transfer = $this->json($response);
        self::assertSame('/transfers/'.$transfer['id'], $response->headers->get('Location'));
        self::assertSame('60.00', $this->balance($alice));
        self::assertSame('40.00', $this->balance($bob));
        self::assertSame($transfer, $this->json($this->request('GET', '/transfers/'.$transfer['id'])));
    }

    public function testInsufficientFundsChangesNothing(): void
    {
        [$alice, $bob] = [$this->openAccount(), $this->openAccount('USD', 'Bob')];
        $this->deposit($alice, '10.00');

        $this->assertProblem($this->transfer($alice, $bob, '10.01', 'tr-nsf'), 422, 'urn:ledger:problem:insufficient-funds');

        self::assertSame('10.00', $this->balance($alice));
        self::assertSame('0.00', $this->balance($bob));
    }

    public function testCurrencyMismatchIsRejected(): void
    {
        [$usd, $eur] = [$this->openAccount(), $this->openAccount('EUR', 'Euro')];
        $this->deposit($usd, '10.00');

        $this->assertProblem($this->transfer($usd, $eur, '1.00', 'tr-fx'), 422, 'urn:ledger:problem:currency-mismatch');
    }

    public function testSameAccountTransferIsRejected(): void
    {
        $alice = $this->openAccount();

        $this->assertProblem($this->transfer($alice, $alice, '1.00', 'tr-self'), 422, 'urn:ledger:problem:same-account-transfer');
    }

    public function testWithdrawalDebitsAccountAndRespectsBalance(): void
    {
        $alice = $this->openAccount();
        $this->deposit($alice, '50.00');

        $ok = $this->request('POST', "/accounts/{$alice}/withdrawals", ['amount' => '20.00'], ['Idempotency-Key' => 'wd-1']);
        self::assertSame(201, $ok->getStatusCode());
        self::assertSame('withdrawal', $this->json($ok)['kind']);
        self::assertSame(self::USD_SETTLEMENT, $this->json($ok)['destinationAccountId']);
        self::assertSame('30.00', $this->balance($alice));

        $this->assertProblem(
            $this->request('POST', "/accounts/{$alice}/withdrawals", ['amount' => '30.01'], ['Idempotency-Key' => 'wd-2']),
            422,
            'urn:ledger:problem:insufficient-funds',
        );
    }

    public function testUnknownTransferIsNotFound(): void
    {
        $this->assertProblem($this->request('GET', '/transfers/0199a000-0000-7000-8000-00000000dead'), 404, 'urn:ledger:problem:transfer-not-found');
    }

    private function transfer(string $from, string $to, string $amount, string $key): \Symfony\Component\HttpFoundation\Response
    {
        return $this->request('POST', '/transfers', [
            'sourceAccountId' => $from,
            'destinationAccountId' => $to,
            'amount' => $amount,
        ], ['Idempotency-Key' => $key]);
    }

    private function balance(string $accountId): string
    {
        $balance = $this->json($this->request('GET', "/accounts/{$accountId}"))['balance'];
        \assert(\is_string($balance));

        return $balance;
    }
}
```

- [ ] **Step 3: Run the tests to watch them fail**

Run: `docker compose exec ledger-service php bin/phpunit tests/Integration/Application tests/Functional/TransferApiTest.php`
Expected: FAIL with "Class App\Application\Transfer\TransferMoneyHandler not found".

- [ ] **Step 4: Implement the application layer**

`src/Application/Transfer/PostTransferCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Transfer\TransferKind;
use Symfony\Component\Uid\Uuid;

final readonly class PostTransferCommand
{
    /** Fingerprint of the request, compared when an idempotency key is replayed. */
    public string $requestHash;

    public function __construct(
        public TransferKind $kind,
        public Uuid $sourceAccountId,
        public Uuid $destinationAccountId,
        public string $amount,
        public ?string $description,
        public string $idempotencyKey,
    ) {
        $this->requestHash = hash('sha256', json_encode([
            $kind->value,
            $sourceAccountId->toRfc4122(),
            $destinationAccountId->toRfc4122(),
            trim($amount),
            $description,
        ], \JSON_THROW_ON_ERROR));
    }
}
```

`src/Application/Transfer/PostTransferResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Transfer;

final readonly class PostTransferResult
{
    public function __construct(public TransferView $transfer, public bool $replayed)
    {
    }
}
```

`src/Application/Transfer/TransferView.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Shared\MoneyFormatter;
use App\Domain\Transfer\Transfer;

final readonly class TransferView
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $sourceAccountId,
        public string $destinationAccountId,
        public string $amount,
        public string $currency,
        public ?string $description,
        public string $status,
        public string $journalEntryId,
        public string $createdAt,
    ) {
    }

    public static function fromTransfer(Transfer $transfer): self
    {
        return new self(
            $transfer->id()->toRfc4122(),
            $transfer->kind()->value,
            $transfer->sourceAccountId()->toRfc4122(),
            $transfer->destinationAccountId()->toRfc4122(),
            MoneyFormatter::format($transfer->amount()),
            $transfer->amount()->getCurrency()->getCode(),
            $transfer->description(),
            $transfer->status()->value,
            $transfer->journalEntryId()->toRfc4122(),
            $transfer->createdAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
        );
    }
}
```

`src/Application/Transfer/PostTransferService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Application\Port\DuplicateRecord;
use App\Application\Port\EventPublisher;
use App\Application\Port\TransactionManager;
use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Ledger\Direction;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\JournalEntryRepository;
use App\Domain\Ledger\PostingLine;
use App\Domain\Shared\Exception\CurrencyMismatch;
use App\Domain\Shared\MoneyParser;
use App\Domain\Transfer\Exception\IdempotencyConflict;
use App\Domain\Transfer\Exception\SameAccountTransfer;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Posts every kind of money movement as one balanced journal entry, in one
 * transaction, with both accounts row-locked in a fixed order.
 */
final class PostTransferService
{
    private const string SOURCE_TYPE = 'transfer';

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly JournalEntryRepository $journalEntries,
        private readonly TransferRepository $transfers,
        private readonly TransactionManager $transactions,
        private readonly EventPublisher $events,
        private readonly ClockInterface $clock,
    ) {
    }

    public function post(PostTransferCommand $command): PostTransferResult
    {
        $existing = $this->transfers->findByIdempotencyKey($command->idempotencyKey);
        if (null !== $existing) {
            return $this->replay($existing, $command);
        }

        try {
            $transfer = $this->transactions->transactional(fn (): Transfer => $this->execute($command));
        } catch (DuplicateRecord $duplicate) {
            // A concurrent request with the same key committed first.
            $existing = $this->transfers->findByIdempotencyKey($command->idempotencyKey);
            if (null === $existing) {
                throw $duplicate;
            }

            return $this->replay($existing, $command);
        }

        return new PostTransferResult(TransferView::fromTransfer($transfer), false);
    }

    private function execute(PostTransferCommand $command): Transfer
    {
        if ($command->sourceAccountId->equals($command->destinationAccountId)) {
            throw SameAccountTransfer::forAccount($command->sourceAccountId);
        }

        [$source, $destination] = $this->lockInOrder($command->sourceAccountId, $command->destinationAccountId);
        if (!$source->currency()->equals($destination->currency())) {
            throw CurrencyMismatch::between($source->currency(), $destination->currency());
        }

        $amount = MoneyParser::parsePositive($command->amount, $source->currency());
        $now = $this->clock->now();

        $sourceBalance = $source->applyPosting(Direction::DEBIT, $amount, $now);
        $destinationBalance = $destination->applyPosting(Direction::CREDIT, $amount, $now);

        $transferId = Uuid::v7();
        $journalEntryId = Uuid::v7();
        $entry = JournalEntry::record($journalEntryId, $now, $command->description, self::SOURCE_TYPE, $transferId, [
            new PostingLine($source->id(), Direction::DEBIT, $amount, $sourceBalance),
            new PostingLine($destination->id(), Direction::CREDIT, $amount, $destinationBalance),
        ]);
        $transfer = Transfer::complete(
            $transferId,
            $command->kind,
            $source->id(),
            $destination->id(),
            $amount,
            $command->description,
            $command->idempotencyKey,
            $command->requestHash,
            $journalEntryId,
            $now,
        );

        $this->journalEntries->add($entry);
        $this->transfers->add($transfer);
        foreach ([...$transfer->pullEvents(), ...$source->pullEvents(), ...$destination->pullEvents()] as $event) {
            $this->events->publish($event);
        }

        return $transfer;
    }

    /**
     * Locks both rows in UUID order so opposite-direction transfers between
     * the same pair cannot deadlock.
     *
     * @return array{Account, Account} [source, destination]
     */
    private function lockInOrder(Uuid $sourceId, Uuid $destinationId): array
    {
        $sourceFirst = strcmp($sourceId->toRfc4122(), $destinationId->toRfc4122()) < 0;

        if ($sourceFirst) {
            $source = $this->accounts->getForUpdate($sourceId);
            $destination = $this->accounts->getForUpdate($destinationId);
        } else {
            $destination = $this->accounts->getForUpdate($destinationId);
            $source = $this->accounts->getForUpdate($sourceId);
        }

        return [$source, $destination];
    }

    private function replay(Transfer $existing, PostTransferCommand $command): PostTransferResult
    {
        if (!$existing->matchesRequest($command->requestHash)) {
            throw IdempotencyConflict::forKey($command->idempotencyKey);
        }

        return new PostTransferResult(TransferView::fromTransfer($existing), true);
    }
}
```

`src/Application/Transfer/TransferMoneyCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use Symfony\Component\Uid\Uuid;

final readonly class TransferMoneyCommand
{
    public function __construct(
        public Uuid $sourceAccountId,
        public Uuid $destinationAccountId,
        public string $amount,
        public ?string $description,
        public string $idempotencyKey,
    ) {
    }
}
```

`src/Application/Transfer/TransferMoneyHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Transfer\TransferKind;

final class TransferMoneyHandler
{
    public function __construct(private readonly PostTransferService $poster)
    {
    }

    public function handle(TransferMoneyCommand $command): PostTransferResult
    {
        return $this->poster->post(new PostTransferCommand(
            TransferKind::INTERNAL,
            $command->sourceAccountId,
            $command->destinationAccountId,
            $command->amount,
            $command->description,
            $command->idempotencyKey,
        ));
    }
}
```

`src/Application/Transfer/RecordDepositCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use Symfony\Component\Uid\Uuid;

final readonly class RecordDepositCommand
{
    public function __construct(
        public Uuid $accountId,
        public string $amount,
        public ?string $description,
        public string $idempotencyKey,
    ) {
    }
}
```

`src/Application/Transfer/RecordDepositHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Account\AccountRepository;
use App\Domain\Transfer\TransferKind;

/** Money arriving from outside the ledger: settlement → customer. */
final class RecordDepositHandler
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PostTransferService $poster,
    ) {
    }

    public function handle(RecordDepositCommand $command): PostTransferResult
    {
        $account = $this->accounts->get($command->accountId);
        $settlement = $this->accounts->getSettlement($account->currency());

        return $this->poster->post(new PostTransferCommand(
            TransferKind::DEPOSIT,
            $settlement->id(),
            $account->id(),
            $command->amount,
            $command->description,
            $command->idempotencyKey,
        ));
    }
}
```

`src/Application/Transfer/RecordWithdrawalCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use Symfony\Component\Uid\Uuid;

final readonly class RecordWithdrawalCommand
{
    public function __construct(
        public Uuid $accountId,
        public string $amount,
        public ?string $description,
        public string $idempotencyKey,
    ) {
    }
}
```

`src/Application/Transfer/RecordWithdrawalHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Account\AccountRepository;
use App\Domain\Transfer\TransferKind;

/** Money leaving the ledger: customer → settlement. */
final class RecordWithdrawalHandler
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PostTransferService $poster,
    ) {
    }

    public function handle(RecordWithdrawalCommand $command): PostTransferResult
    {
        $account = $this->accounts->get($command->accountId);
        $settlement = $this->accounts->getSettlement($account->currency());

        return $this->poster->post(new PostTransferCommand(
            TransferKind::WITHDRAWAL,
            $account->id(),
            $settlement->id(),
            $command->amount,
            $command->description,
            $command->idempotencyKey,
        ));
    }
}
```

`src/Application/Transfer/GetTransferHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Transfer\TransferRepository;
use Symfony\Component\Uid\Uuid;

final class GetTransferHandler
{
    public function __construct(private readonly TransferRepository $transfers)
    {
    }

    public function handle(Uuid $id): TransferView
    {
        return TransferView::fromTransfer($this->transfers->get($id));
    }
}
```

- [ ] **Step 5: Implement the HTTP layer**

`src/Infrastructure/Http/Request/IdempotencyKey.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Request;

final readonly class IdempotencyKey
{
    public function __construct(public string $value)
    {
    }
}
```

`src/Infrastructure/Http/Request/IdempotencyKeyValueResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Request;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Resolves an IdempotencyKey action argument from the required request header. */
final class IdempotencyKeyValueResolver implements ValueResolverInterface
{
    public const string HEADER = 'Idempotency-Key';
    private const int MAX_LENGTH = 255;

    /** @return iterable<IdempotencyKey> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (IdempotencyKey::class !== $argument->getType()) {
            return [];
        }

        $value = trim((string) $request->headers->get(self::HEADER, ''));
        if ('' === $value || mb_strlen($value) > self::MAX_LENGTH) {
            throw new BadRequestHttpException(\sprintf('The "%s" header is required and must be 1-%d characters.', self::HEADER, self::MAX_LENGTH));
        }

        return [new IdempotencyKey($value)];
    }
}
```

`src/Infrastructure/Http/Request/TransferRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TransferRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $sourceAccountId = '',
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $destinationAccountId = '',
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: FundsRequest::AMOUNT_PATTERN, message: FundsRequest::AMOUNT_MESSAGE)]
        public string $amount = '',
        #[Assert\Length(max: 255)]
        public ?string $description = null,
    ) {
    }
}
```

`src/Infrastructure/Http/Request/FundsRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class FundsRequest
{
    public const string AMOUNT_PATTERN = '/^\d+(\.\d+)?$/';
    public const string AMOUNT_MESSAGE = 'Amount must be a positive decimal string such as "10.50".';

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: self::AMOUNT_PATTERN, message: self::AMOUNT_MESSAGE)]
        public string $amount = '',
        #[Assert\Length(max: 255)]
        public ?string $description = null,
    ) {
    }
}
```

`src/Infrastructure/Http/Controller/TransferController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Transfer\GetTransferHandler;
use App\Application\Transfer\PostTransferResult;
use App\Application\Transfer\RecordDepositCommand;
use App\Application\Transfer\RecordDepositHandler;
use App\Application\Transfer\RecordWithdrawalCommand;
use App\Application\Transfer\RecordWithdrawalHandler;
use App\Application\Transfer\TransferMoneyCommand;
use App\Application\Transfer\TransferMoneyHandler;
use App\Infrastructure\Http\Request\FundsRequest;
use App\Infrastructure\Http\Request\IdempotencyKey;
use App\Infrastructure\Http\Request\TransferRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

final class TransferController extends AbstractController
{
    public function __construct(
        private readonly TransferMoneyHandler $transferMoney,
        private readonly RecordDepositHandler $recordDeposit,
        private readonly RecordWithdrawalHandler $recordWithdrawal,
        private readonly GetTransferHandler $getTransfer,
    ) {
    }

    #[Route('/transfers', name: 'transfer_create', methods: ['POST'])]
    public function transfer(#[MapRequestPayload] TransferRequest $request, IdempotencyKey $key): JsonResponse
    {
        return $this->respond($this->transferMoney->handle(new TransferMoneyCommand(
            Uuid::fromString($request->sourceAccountId),
            Uuid::fromString($request->destinationAccountId),
            $request->amount,
            $request->description,
            $key->value,
        )));
    }

    #[Route('/accounts/{id}/deposits', name: 'account_deposit', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function deposit(Uuid $id, #[MapRequestPayload] FundsRequest $request, IdempotencyKey $key): JsonResponse
    {
        return $this->respond($this->recordDeposit->handle(
            new RecordDepositCommand($id, $request->amount, $request->description, $key->value),
        ));
    }

    #[Route('/accounts/{id}/withdrawals', name: 'account_withdrawal', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function withdraw(Uuid $id, #[MapRequestPayload] FundsRequest $request, IdempotencyKey $key): JsonResponse
    {
        return $this->respond($this->recordWithdrawal->handle(
            new RecordWithdrawalCommand($id, $request->amount, $request->description, $key->value),
        ));
    }

    #[Route('/transfers/{id}', name: 'transfer_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(Uuid $id): JsonResponse
    {
        return $this->json($this->getTransfer->handle($id));
    }

    private function respond(PostTransferResult $result): JsonResponse
    {
        $headers = ['Location' => $this->generateUrl('transfer_show', ['id' => $result->transfer->id])];
        if ($result->replayed) {
            $headers['Idempotent-Replayed'] = 'true';
        }

        return $this->json($result->transfer, $result->replayed ? Response::HTTP_OK : Response::HTTP_CREATED, $headers);
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `docker compose exec ledger-service php bin/phpunit tests/Integration/Application tests/Functional`
Expected: PASS.
- If the `json number` data set returns 400 instead of 422, Symfony reported the type error as a bad request. Read `RequestPayloadValueResolver` to confirm, then change that data set's expectation to `400, 'about:blank'`. The requirement is "a clear 4xx, never 500".
- If `postings` comparison fails on `amount_minor` types, cast the integer columns in the SQL (`amount_minor::int`) or compare with `assertEquals` as written; DBAL returns ints for bigint on pgsql.

- [ ] **Step 7: Run the gates and commit**

```bash
git add -A && git commit -m "feat(ledger-service): post transfers, deposits and withdrawals with locking and idempotency"
```

---

### Task 10: Account statements

**Files:**
- Create: `src/Application/Port/StatementReader.php`, `src/Application/Port/StatementLine.php`
- Create: `src/Application/Statement/StatementQuery.php`, `GetAccountStatementHandler.php`, `StatementView.php`, `StatementLineView.php`
- Create: `src/Domain/Ledger/Exception/InvalidStatementPeriod.php`
- Create: `src/Infrastructure/Persistence/Doctrine/DbalStatementReader.php`
- Create: `src/Infrastructure/Http/Request/StatementRequest.php`, `src/Infrastructure/Http/Controller/StatementController.php`
- Test: `tests/Functional/StatementApiTest.php`

**Interfaces:**
- Consumes: `AccountRepository::get`, `MoneyFormatter`, the clock, and `ApiTestCase` helpers
- Produces:
  - `StatementReader::openingBalanceMinor(Uuid $accountId, \DateTimeImmutable $before): int`
  - `StatementReader::lines(Uuid $accountId, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): list<StatementLine>`
  - `StatementLine(string $postingId, \DateTimeImmutable $occurredAt, ?string $description, string $transferId, Direction $direction, int $amountMinor, int $balanceAfterMinor)`
  - `GetAccountStatementHandler::handle(StatementQuery(Uuid $accountId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to)): StatementView`
  - the route `account_statement`

- [ ] **Step 1: Write the failing test**

`tests/Functional/StatementApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class StatementApiTest extends ApiTestCase
{
    use ClockSensitiveTrait;

    public function testStatementHasOpeningLinesWithRunningBalanceAndClosing(): void
    {
        $alice = $this->openAccount();
        $bob = $this->openAccount('USD', 'Bob');

        self::mockTime('2026-03-01 09:00:00');
        $this->deposit($alice, '100.00', 'st-dep');
        self::mockTime('2026-03-05 12:00:00');
        $this->transfer($alice, $bob, '30.00', 'st-t1', 'Groceries');
        self::mockTime('2026-03-06 23:59:59');
        $this->transfer($alice, $bob, '5.00', 'st-t2', 'Coffee');
        self::mockTime('2026-03-07 00:00:00');
        $this->transfer($alice, $bob, '1.00', 'st-t3', 'Next day');

        $statement = $this->json($this->request('GET', "/accounts/{$alice}/statement?from=2026-03-05&to=2026-03-06"));

        self::assertSame('USD', $statement['currency']);
        self::assertSame('2026-03-05', $statement['from']);
        self::assertSame('2026-03-06', $statement['to']);
        self::assertSame('100.00', $statement['openingBalance']);
        self::assertSame('65.00', $statement['closingBalance']);
        $lines = $statement['lines'];
        \assert(\is_array($lines));
        self::assertCount(2, $lines);
        self::assertSame(['debit', '30.00', '70.00', 'Groceries'], [$lines[0]['direction'], $lines[0]['amount'], $lines[0]['balanceAfter'], $lines[0]['description']]);
        self::assertSame(['debit', '5.00', '65.00', 'Coffee'], [$lines[1]['direction'], $lines[1]['amount'], $lines[1]['balanceAfter'], $lines[1]['description']]);
    }

    public function testFromDateIsInclusiveAtMidnight(): void
    {
        $alice = $this->openAccount();
        self::mockTime('2026-04-10 00:00:00');
        $this->deposit($alice, '10.00', 'st-midnight');

        $statement = $this->json($this->request('GET', "/accounts/{$alice}/statement?from=2026-04-10&to=2026-04-10"));

        self::assertSame('0.00', $statement['openingBalance']);
        self::assertCount(1, (array) $statement['lines']);
        self::assertSame('10.00', $statement['closingBalance']);
    }

    public function testEmptyPeriodClosesAtOpeningBalance(): void
    {
        $alice = $this->openAccount();
        self::mockTime('2026-05-01 10:00:00');
        $this->deposit($alice, '10.00', 'st-empty');

        $statement = $this->json($this->request('GET', "/accounts/{$alice}/statement?from=2026-06-01&to=2026-06-30"));

        self::assertSame('10.00', $statement['openingBalance']);
        self::assertSame([], $statement['lines']);
        self::assertSame('10.00', $statement['closingBalance']);
    }

    public function testDefaultsToTheLastThirtyDays(): void
    {
        $alice = $this->openAccount();
        self::mockTime('2026-07-31 15:00:00');

        $statement = $this->json($this->request('GET', "/accounts/{$alice}/statement"));

        self::assertSame('2026-07-01', $statement['from']);
        self::assertSame('2026-07-31', $statement['to']);
    }

    public function testRejectsInvalidPeriods(): void
    {
        $alice = $this->openAccount();

        $this->assertProblem($this->request('GET', "/accounts/{$alice}/statement?from=2026-03-10&to=2026-03-01"), 422, 'urn:ledger:problem:invalid-statement-period');
        $this->assertProblem($this->request('GET', "/accounts/{$alice}/statement?from=2025-01-01&to=2026-03-01"), 422, 'urn:ledger:problem:invalid-statement-period');
        $this->assertProblem($this->request('GET', "/accounts/{$alice}/statement?from=03/01/2026"), 422, 'urn:ledger:problem:validation-failed');
    }

    public function testUnknownAccountIsNotFound(): void
    {
        $this->assertProblem($this->request('GET', '/accounts/0199a000-0000-7000-8000-00000000dead/statement'), 404, 'urn:ledger:problem:account-not-found');
    }

    private function transfer(string $from, string $to, string $amount, string $key, string $description): void
    {
        $response = $this->request('POST', '/transfers', [
            'sourceAccountId' => $from,
            'destinationAccountId' => $to,
            'amount' => $amount,
            'description' => $description,
        ], ['Idempotency-Key' => $key]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run: `docker compose exec ledger-service php bin/phpunit tests/Functional/StatementApiTest.php`
Expected: FAIL (404 for the statement route).

- [ ] **Step 3: Implement**

`src/Domain/Ledger/Exception/InvalidStatementPeriod.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exception;

use App\Domain\Shared\LedgerException;

final class InvalidStatementPeriod extends LedgerException
{
    public static function fromAfterTo(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        return new self(\sprintf('Statement "from" (%s) must not be after "to" (%s).', $from->format('Y-m-d'), $to->format('Y-m-d')));
    }

    public static function tooLong(int $maxDays): self
    {
        return new self(\sprintf('A statement may cover at most %d days.', $maxDays));
    }
}
```

`src/Application/Port/StatementLine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Ledger\Direction;

final readonly class StatementLine
{
    public function __construct(
        public string $postingId,
        public \DateTimeImmutable $occurredAt,
        public ?string $description,
        public string $transferId,
        public Direction $direction,
        public int $amountMinor,
        public int $balanceAfterMinor,
    ) {
    }
}
```

`src/Application/Port/StatementReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Port;

use Symfony\Component\Uid\Uuid;

/** Read model over postings; bypasses the entity layer. */
interface StatementReader
{
    public function openingBalanceMinor(Uuid $accountId, \DateTimeImmutable $before): int;

    /** @return list<StatementLine> ordered by (occurred_at, posting id) */
    public function lines(Uuid $accountId, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array;
}
```

`src/Application/Statement/StatementQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Statement;

use Symfony\Component\Uid\Uuid;

final readonly class StatementQuery
{
    public function __construct(
        public Uuid $accountId,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
    ) {
    }
}
```

`src/Application/Statement/StatementLineView.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Statement;

final readonly class StatementLineView
{
    public function __construct(
        public string $postingId,
        public string $occurredAt,
        public ?string $description,
        public string $transferId,
        public string $direction,
        public string $amount,
        public string $balanceAfter,
    ) {
    }
}
```

`src/Application/Statement/StatementView.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Statement;

final readonly class StatementView
{
    /** @param list<StatementLineView> $lines */
    public function __construct(
        public string $accountId,
        public string $currency,
        public string $from,
        public string $to,
        public string $openingBalance,
        public string $closingBalance,
        public array $lines,
    ) {
    }
}
```

`src/Application/Statement/GetAccountStatementHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Statement;

use App\Application\Port\StatementLine;
use App\Application\Port\StatementReader;
use App\Domain\Account\AccountRepository;
use App\Domain\Ledger\Exception\InvalidStatementPeriod;
use App\Domain\Shared\MoneyFormatter;
use Money\Money;
use Psr\Clock\ClockInterface;

final class GetAccountStatementHandler
{
    private const int DEFAULT_DAYS = 30;
    private const int MAX_DAYS = 366;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly StatementReader $reader,
        private readonly ClockInterface $clock,
    ) {
    }

    public function handle(StatementQuery $query): StatementView
    {
        $account = $this->accounts->get($query->accountId);

        $to = ($query->to ?? $this->clock->now())->setTime(0, 0);
        $from = ($query->from ?? $to->modify(\sprintf('-%d days', self::DEFAULT_DAYS)))->setTime(0, 0);
        if ($from > $to) {
            throw InvalidStatementPeriod::fromAfterTo($from, $to);
        }
        if ((int) $from->diff($to)->days > self::MAX_DAYS) {
            throw InvalidStatementPeriod::tooLong(self::MAX_DAYS);
        }

        $currency = $account->currency();
        $opening = new Money($this->reader->openingBalanceMinor($account->id(), $from), $currency);
        $lines = $this->reader->lines($account->id(), $from, $to->modify('+1 day'));
        $closing = [] === $lines ? $opening : new Money($lines[array_key_last($lines)]->balanceAfterMinor, $currency);

        return new StatementView(
            $account->id()->toRfc4122(),
            $currency->getCode(),
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            MoneyFormatter::format($opening),
            MoneyFormatter::format($closing),
            array_map(static fn (StatementLine $line): StatementLineView => new StatementLineView(
                $line->postingId,
                $line->occurredAt->format(\DateTimeInterface::RFC3339_EXTENDED),
                $line->description,
                $line->transferId,
                $line->direction->value,
                MoneyFormatter::format(new Money($line->amountMinor, $currency)),
                MoneyFormatter::format(new Money($line->balanceAfterMinor, $currency)),
            ), $lines),
        );
    }
}
```

The default window is `to` = today and `from` = today minus 30 days. For 2026-07-31 that gives `from` = 2026-07-01, which matches the test.

`src/Infrastructure/Persistence/Doctrine/DbalStatementReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\StatementLine;
use App\Application\Port\StatementReader;
use App\Domain\Ledger\Direction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

final class DbalStatementReader implements StatementReader
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function openingBalanceMinor(Uuid $accountId, \DateTimeImmutable $before): int
    {
        $balance = $this->connection->fetchOne(
            'SELECT balance_after_minor FROM postings
             WHERE account_id = :account AND occurred_at < :before
             ORDER BY occurred_at DESC, id DESC
             LIMIT 1',
            ['account' => $accountId->toRfc4122(), 'before' => $before],
            ['before' => Types::DATETIME_IMMUTABLE],
        );

        return false === $balance ? 0 : (int) $balance;
    }

    public function lines(Uuid $accountId, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.occurred_at, p.direction, p.amount_minor, p.balance_after_minor, j.description, j.source_id
             FROM postings p
             JOIN journal_entries j ON j.id = p.journal_entry_id
             WHERE p.account_id = :account AND p.occurred_at >= :from AND p.occurred_at < :to
             ORDER BY p.occurred_at, p.id',
            ['account' => $accountId->toRfc4122(), 'from' => $from, 'to' => $toExclusive],
            ['from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE],
        );

        return array_map(static fn (array $row): StatementLine => new StatementLine(
            (string) $row['id'],
            new \DateTimeImmutable((string) $row['occurred_at']),
            null === $row['description'] ? null : (string) $row['description'],
            (string) $row['source_id'],
            Direction::from((string) $row['direction']),
            (int) $row['amount_minor'],
            (int) $row['balance_after_minor'],
        ), $rows);
    }
}
```

`src/Infrastructure/Http/Request/StatementRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class StatementRequest
{
    public function __construct(
        #[Assert\Date(message: 'Use the YYYY-MM-DD format.')]
        public ?string $from = null,
        #[Assert\Date(message: 'Use the YYYY-MM-DD format.')]
        public ?string $to = null,
    ) {
    }
}
```

`src/Infrastructure/Http/Controller/StatementController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Statement\GetAccountStatementHandler;
use App\Application\Statement\StatementQuery;
use App\Infrastructure\Http\Request\StatementRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

final class StatementController extends AbstractController
{
    public function __construct(private readonly GetAccountStatementHandler $getStatement)
    {
    }

    #[Route('/accounts/{id}/statement', name: 'account_statement', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(Uuid $id, #[MapQueryString] ?StatementRequest $query = null): JsonResponse
    {
        return $this->json($this->getStatement->handle(new StatementQuery(
            $id,
            self::date($query?->from),
            self::date($query?->to),
        )));
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable($value.' 00:00:00', new \DateTimeZone('UTC'));
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec ledger-service php bin/phpunit tests/Functional/StatementApiTest.php`
Expected: PASS. `ClockSensitiveTrait` sets the global clock, which the autowired `ClockInterface` reads through `Symfony\Component\Clock\Clock`. If `mockTime` has no effect, check that `bin/console debug:container clock` shows `Symfony\Component\Clock\Clock` before investigating further.

- [ ] **Step 5: Run the gates and commit**

```bash
git add -A && git commit -m "feat(ledger-service): add account statements derived from postings"
```

---

### Task 11: Outbox relay to Redis, plus event contracts

**Files:**
- Create: `src/Infrastructure/Messaging/IntegrationEvent.php`, `OutboxRelay.php`, `RelayResult.php`
- Create: `src/Infrastructure/Console/OutboxRelayCommand.php`
- Modify: `config/packages/messenger.yaml` (routing)
- Create: `contracts/events/ledger.account_opened.v1.schema.json`, `ledger.transfer_completed.v1.schema.json`, `ledger.deposit_recorded.v1.schema.json`, `ledger.withdrawal_recorded.v1.schema.json` (repo root)
- Test: `tests/Integration/Messaging/OutboxRelayTest.php`, `tests/Integration/Messaging/EventContractTest.php`

**Interfaces:**
- Consumes: `OutboxMessage` (Task 7), `TransactionManager` (Task 6), and the handlers from Tasks 8–9
- Produces:
  - `OutboxRelay::relay(int $batchSize): RelayResult`
  - `RelayResult(int $published, int $failed)`
  - the command `ledger:outbox:relay [--batch=100] [--loop] [--sleep=1000]`
  - `IntegrationEvent{eventId, eventName, eventVersion, occurredAt, aggregateId, payload}`, routed to the `ledger_events` transport
  - `contracts/events/*.v1.schema.json`

- [ ] **Step 1: Write the event contracts**

Write `contracts/events/ledger.transfer_completed.v1.schema.json`:

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "urn:ledger-platform:contracts:events:ledger.transfer_completed:v1",
  "title": "ledger.transfer_completed v1",
  "description": "Money moved between two customer accounts. Delivered at least once; deduplicate on eventId.",
  "type": "object",
  "additionalProperties": false,
  "required": ["eventId", "eventName", "eventVersion", "occurredAt", "aggregateId", "payload"],
  "properties": {
    "eventId": { "type": "string", "format": "uuid" },
    "eventName": { "const": "ledger.transfer_completed" },
    "eventVersion": { "const": 1 },
    "occurredAt": { "type": "string", "format": "date-time" },
    "aggregateId": { "type": "string", "format": "uuid" },
    "payload": {
      "type": "object",
      "additionalProperties": false,
      "required": ["transferId", "journalEntryId", "sourceAccountId", "destinationAccountId", "amount", "amountMinor", "currency", "description", "completedAt"],
      "properties": {
        "transferId": { "type": "string", "format": "uuid" },
        "journalEntryId": { "type": "string", "format": "uuid" },
        "sourceAccountId": { "type": "string", "format": "uuid" },
        "destinationAccountId": { "type": "string", "format": "uuid" },
        "amount": { "type": "string", "pattern": "^\\d+(\\.\\d+)?$" },
        "amountMinor": { "type": "integer", "minimum": 1 },
        "currency": { "type": "string", "pattern": "^[A-Z]{3}$" },
        "description": { "type": ["string", "null"] },
        "completedAt": { "type": "string", "format": "date-time" }
      }
    }
  }
}
```

Write `ledger.deposit_recorded.v1.schema.json` and `ledger.withdrawal_recorded.v1.schema.json` with identical content, except:
- replace the `$id` suffix, `title` and `eventName.const` with the respective names
- descriptions: "Money entered the ledger from the settlement account." and "Money left the ledger to the settlement account."

Write `contracts/events/ledger.account_opened.v1.schema.json`:

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "urn:ledger-platform:contracts:events:ledger.account_opened:v1",
  "title": "ledger.account_opened v1",
  "description": "A ledger account was opened. Delivered at least once; deduplicate on eventId.",
  "type": "object",
  "additionalProperties": false,
  "required": ["eventId", "eventName", "eventVersion", "occurredAt", "aggregateId", "payload"],
  "properties": {
    "eventId": { "type": "string", "format": "uuid" },
    "eventName": { "const": "ledger.account_opened" },
    "eventVersion": { "const": 1 },
    "occurredAt": { "type": "string", "format": "date-time" },
    "aggregateId": { "type": "string", "format": "uuid" },
    "payload": {
      "type": "object",
      "additionalProperties": false,
      "required": ["accountId", "name", "type", "currency", "openedAt"],
      "properties": {
        "accountId": { "type": "string", "format": "uuid" },
        "name": { "type": "string", "minLength": 1 },
        "type": { "enum": ["customer", "settlement"] },
        "currency": { "type": "string", "pattern": "^[A-Z]{3}$" },
        "openedAt": { "type": "string", "format": "date-time" }
      }
    }
  }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Integration/Messaging/OutboxRelayTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Application\Port\TransactionManager;
use App\Infrastructure\Messaging\IntegrationEvent;
use App\Infrastructure\Messaging\OutboxRelay;
use App\Tests\Support\IntegrationTestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class OutboxRelayTest extends IntegrationTestCase
{
    public function testPublishesPendingEventsOnceAndMarksThemPublished(): void
    {
        $account = $this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Alice', 'USD'));

        $first = $this->service(OutboxRelay::class)->relay(100);
        $second = $this->service(OutboxRelay::class)->relay(100);

        self::assertSame(1, $first->published);
        self::assertSame(0, $second->published);
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(IntegrationEvent::class, $message);
        self::assertSame('ledger.account_opened', $message->eventName);
        self::assertSame($account->id, $message->aggregateId);
        self::assertNotNull($this->connection()->fetchOne('SELECT published_at FROM outbox_messages WHERE aggregate_id = :id', ['id' => $account->id]));
    }

    public function testFailedPublishKeepsMessagePendingAndRecordsError(): void
    {
        $account = $this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Alice', 'USD'));
        $failingBus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('redis down');
            }
        };
        $relay = new OutboxRelay($this->em(), $this->service(TransactionManager::class), $failingBus, $this->service(ClockInterface::class), new NullLogger());

        $result = $relay->relay(100);

        self::assertSame(0, $result->published);
        self::assertSame(1, $result->failed);
        $row = $this->connection()->fetchAssociative('SELECT published_at, attempts, last_error FROM outbox_messages WHERE aggregate_id = :id', ['id' => $account->id]);
        self::assertSame(['published_at' => null, 'attempts' => 1, 'last_error' => 'redis down'], $row);
    }

    public function testCommandRelaysABatch(): void
    {
        $this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Alice', 'USD'));
        $this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Bob', 'USD'));
        $tester = new CommandTester(new Application(self::$kernel)->find('ledger:outbox:relay'));

        $tester->execute(['--batch' => '1']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Published 1, failed 0', $tester->getDisplay());
        self::assertCount(1, $this->transport()->getSent());
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.ledger_events');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
```

`tests/Integration/Messaging/EventContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Application\Transfer\RecordDepositCommand;
use App\Application\Transfer\RecordDepositHandler;
use App\Application\Transfer\RecordWithdrawalCommand;
use App\Application\Transfer\RecordWithdrawalHandler;
use App\Application\Transfer\TransferMoneyCommand;
use App\Application\Transfer\TransferMoneyHandler;
use App\Infrastructure\Messaging\IntegrationEvent;
use App\Infrastructure\Messaging\OutboxRelay;
use App\Tests\Support\IntegrationTestCase;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class EventContractTest extends IntegrationTestCase
{
    public function testEveryEmittedEventMatchesItsPublishedSchema(): void
    {
        $alice = Uuid::fromString($this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Alice', 'USD'))->id);
        $bob = Uuid::fromString($this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Bob', 'USD'))->id);
        $this->service(RecordDepositHandler::class)->handle(new RecordDepositCommand($alice, '100.00', 'Salary', 'c-dep'));
        $this->service(TransferMoneyHandler::class)->handle(new TransferMoneyCommand($alice, $bob, '10.00', null, 'c-tr'));
        $this->service(RecordWithdrawalHandler::class)->handle(new RecordWithdrawalCommand($bob, '5.00', 'ATM', 'c-wd'));

        $this->service(OutboxRelay::class)->relay(100);

        $transport = self::getContainer()->get('messenger.transport.ledger_events');
        \assert($transport instanceof InMemoryTransport);
        $seen = [];
        foreach ($transport->getSent() as $envelope) {
            $event = $envelope->getMessage();
            \assert($event instanceof IntegrationEvent);
            $this->assertMatchesSchema($event);
            $seen[$event->eventName] = true;
        }

        self::assertEqualsCanonicalizing(
            ['ledger.account_opened', 'ledger.deposit_recorded', 'ledger.transfer_completed', 'ledger.withdrawal_recorded'],
            array_keys($seen),
        );
    }

    private function assertMatchesSchema(IntegrationEvent $event): void
    {
        $dir = $_SERVER['CONTRACTS_DIR'] ?? $_ENV['CONTRACTS_DIR'] ?? \dirname(__DIR__, 5).'/contracts';
        \assert(\is_string($dir));
        $schemaFile = \sprintf('%s/events/%s.v%d.schema.json', $dir, $event->eventName, $event->eventVersion);
        self::assertFileExists($schemaFile);

        $data = json_decode(json_encode($event->toArray(), \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR);
        $result = new Validator()->validate($data, (string) file_get_contents($schemaFile));

        self::assertTrue(
            $result->isValid(),
            $event->eventName.': '.json_encode(null === $result->error() ? [] : new ErrorFormatter()->format($result->error())),
        );
    }
}
```

- [ ] **Step 3: Run the tests to watch them fail**

Run: `docker compose exec ledger-service php bin/phpunit tests/Integration/Messaging`
Expected: FAIL with "Class App\Infrastructure\Messaging\OutboxRelay not found".

- [ ] **Step 4: Implement the relay**

`src/Infrastructure/Messaging/IntegrationEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Infrastructure\Persistence\Doctrine\Outbox\OutboxMessage;

/** Wire format published to other services; see /contracts/events. */
final readonly class IntegrationEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $eventId,
        public string $eventName,
        public int $eventVersion,
        public string $occurredAt,
        public string $aggregateId,
        public array $payload,
    ) {
    }

    public static function fromOutbox(OutboxMessage $message): self
    {
        $envelope = $message->toEnvelope();

        return new self(
            $envelope['eventId'],
            $envelope['eventName'],
            $envelope['eventVersion'],
            $envelope['occurredAt'],
            $envelope['aggregateId'],
            $envelope['payload'],
        );
    }

    /** @return array{eventId: string, eventName: string, eventVersion: int, occurredAt: string, aggregateId: string, payload: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'eventId' => $this->eventId,
            'eventName' => $this->eventName,
            'eventVersion' => $this->eventVersion,
            'occurredAt' => $this->occurredAt,
            'aggregateId' => $this->aggregateId,
            'payload' => $this->payload,
        ];
    }
}
```

`src/Infrastructure/Messaging/RelayResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

final readonly class RelayResult
{
    public function __construct(public int $published, public int $failed)
    {
    }
}
```

`src/Infrastructure/Messaging/OutboxRelay.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Port\TransactionManager;
use App\Infrastructure\Persistence\Doctrine\Outbox\OutboxMessage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Publishes committed outbox rows in order. Delivery is at-least-once: a
 * crash between dispatch and commit republishes the same eventId.
 */
final class OutboxRelay
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionManager $transactions,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function relay(int $batchSize): RelayResult
    {
        $result = $this->transactions->transactional(function () use ($batchSize): RelayResult {
            $messages = $this->claimBatch(max(1, $batchSize));
            $published = 0;
            $failed = 0;

            foreach ($messages as $message) {
                try {
                    $this->bus->dispatch(IntegrationEvent::fromOutbox($message));
                    $message->markPublished($this->clock->now());
                    ++$published;
                } catch (\Throwable $e) {
                    $message->markFailed($e->getMessage());
                    ++$failed;
                    $this->logger->warning('Outbox publish failed for {id}: {error}', ['id' => $message->id()->toRfc4122(), 'error' => $e->getMessage()]);
                }
            }

            return new RelayResult($published, $failed);
        });

        // Long-running loops must not accumulate managed entities.
        $this->entityManager->clear();

        return $result;
    }

    /** @return list<OutboxMessage> */
    private function claimBatch(int $batchSize): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            \sprintf('SELECT id FROM outbox_messages WHERE published_at IS NULL ORDER BY occurred_at, id LIMIT %d FOR UPDATE SKIP LOCKED', $batchSize),
        );
        if ([] === $ids) {
            return [];
        }

        /** @var list<OutboxMessage> */
        return $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(OutboxMessage::class, 'm')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', array_map('strval', $ids), ArrayParameterType::STRING)
            ->orderBy('m.occurredAt')
            ->addOrderBy('m.id')
            ->getQuery()
            ->getResult();
    }
}
```

`src/Infrastructure/Console/OutboxRelayCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Infrastructure\Messaging\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'ledger:outbox:relay', description: 'Publish pending outbox events to the ledger_events transport')]
final class OutboxRelayCommand
{
    private const string LOCK_NAME = 'ledger-outbox-relay';

    public function __construct(
        private readonly OutboxRelay $relay,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Maximum events per batch')] int $batch = 100,
        #[Option(description: 'Keep polling instead of exiting after one batch')] bool $loop = false,
        #[Option(description: 'Milliseconds to wait when the outbox is empty')] int $sleep = 1000,
    ): int {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME);
        // In loop mode wait as a hot standby; a one-shot run just yields.
        if (!$lock->acquire($loop)) {
            $io->note('Another relay holds the lock; exiting.');

            return Command::SUCCESS;
        }

        try {
            do {
                $result = $this->relay->relay($batch);
                if ($result->published + $result->failed > 0 || !$loop) {
                    $io->writeln(\sprintf('Published %d, failed %d', $result->published, $result->failed));
                }
                if ($loop && 0 === $result->published) {
                    usleep(max(0, $sleep) * 1000);
                }
            } while ($loop);
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
```

Add the routing to `config/packages/messenger.yaml`:

```yaml
        routing:
            App\Infrastructure\Messaging\IntegrationEvent: ledger_events
```

- [ ] **Step 5: Run the tests**

Run: `docker compose exec ledger-service php bin/phpunit tests/Integration/Messaging`
Expected: PASS.
- If `testFailedPublishKeepsMessagePendingAndRecordsError` shows `attempts` as a string, compare with `assertEquals`.
- If `#[Option]` isn't available in Symfony 8.1 for invokable commands (check `vendor/symfony/console/Attribute/Option.php`), switch the class to `extends Command` with `configure()` options.

- [ ] **Step 6: Check against real Redis**

```bash
docker compose restart ledger-outbox-relay
curl -s -XPOST localhost:8081/accounts -H 'Content-Type: application/json' -d '{"name":"Relay check","currency":"USD"}'
sleep 3 && docker compose exec redis redis-cli XLEN ledger_events
```

Expected: `XLEN` ≥ 1.

- [ ] **Step 7: Run the gates and commit**

```bash
git add -A && git commit -m "feat(ledger-service): relay outbox events to Redis via Messenger with versioned JSON Schema contracts"
```

---

### Task 12: Balance verification, invariants and demo seed

**Files:**
- Create: `src/Infrastructure/Persistence/Doctrine/BalanceVerifier.php`, `BalanceReport.php`
- Create: `src/Infrastructure/Console/VerifyBalancesCommand.php`, `DemoSeedCommand.php`
- Test: `tests/Integration/LedgerInvariantTest.php`, `tests/Integration/Console/DemoSeedCommandTest.php`

**Interfaces:**
- Consumes: the handlers from Tasks 8–9
- Produces:
  - `BalanceVerifier::verify(): BalanceReport`
  - `BalanceReport{accountMismatches: list<array{accountId:string, cachedMinor:int, computedMinor:int}>, currencyImbalances: list<array{currency:string, totalMinor:int}>}` with `isClean(): bool`
  - the commands `ledger:verify-balances` (exit 1 on mismatch) and `ledger:demo:seed`

- [ ] **Step 1: Write the failing tests**

`tests/Integration/LedgerInvariantTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Application\Transfer\RecordDepositCommand;
use App\Application\Transfer\RecordDepositHandler;
use App\Application\Transfer\RecordWithdrawalCommand;
use App\Application\Transfer\RecordWithdrawalHandler;
use App\Application\Transfer\TransferMoneyCommand;
use App\Application\Transfer\TransferMoneyHandler;
use App\Domain\Shared\LedgerException;
use App\Infrastructure\Persistence\Doctrine\BalanceVerifier;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class LedgerInvariantTest extends IntegrationTestCase
{
    public function testBalancesMatchPostingsAndSumToZeroAfterMixedActivity(): void
    {
        $usd = array_map(fn (string $n): Uuid => $this->open($n, 'USD'), ['A', 'B', 'C']);
        $jpy = $this->open('Yen', 'JPY');
        $step = 0;
        $run = function (callable $operation) use (&$step): void {
            ++$step;
            try {
                $operation("inv-{$step}");
            } catch (LedgerException) {
                // Rejected operations are part of the script; they must leave no trace.
            }
        };

        $run(fn (string $k) => $this->service(RecordDepositHandler::class)->handle(new RecordDepositCommand($usd[0], '500.00', null, $k)));
        $run(fn (string $k) => $this->service(RecordDepositHandler::class)->handle(new RecordDepositCommand($jpy, '12000', null, $k)));
        $run(fn (string $k) => $this->service(TransferMoneyHandler::class)->handle(new TransferMoneyCommand($usd[0], $usd[1], '120.25', null, $k)));
        $run(fn (string $k) => $this->service(TransferMoneyHandler::class)->handle(new TransferMoneyCommand($usd[1], $usd[2], '20.25', null, $k)));
        $run(fn (string $k) => $this->service(TransferMoneyHandler::class)->handle(new TransferMoneyCommand($usd[2], $usd[0], '999.00', null, $k)));
        $run(fn (string $k) => $this->service(RecordWithdrawalHandler::class)->handle(new RecordWithdrawalCommand($usd[1], '50.00', null, $k)));
        $run(fn (string $k) => $this->service(RecordWithdrawalHandler::class)->handle(new RecordWithdrawalCommand($jpy, '500', null, $k)));
        $run(fn (string $k) => $this->service(TransferMoneyHandler::class)->handle(new TransferMoneyCommand($jpy, $usd[0], '1', null, $k)));

        $report = $this->service(BalanceVerifier::class)->verify();

        self::assertTrue($report->isClean(), json_encode($report, \JSON_THROW_ON_ERROR));
        self::assertSame(['379.75', '50.00', '20.25'], array_map(fn (Uuid $id): string => $this->balance($id), $usd));
    }

    public function testVerifierAndCommandReportACorruptedBalance(): void
    {
        $account = $this->open('Victim', 'USD');
        $this->service(RecordDepositHandler::class)->handle(new RecordDepositCommand($account, '10.00', null, 'corrupt'));
        $this->connection()->executeStatement('UPDATE accounts SET balance_minor = balance_minor + 1 WHERE id = :id', ['id' => $account->toRfc4122()]);

        $report = $this->service(BalanceVerifier::class)->verify();
        self::assertFalse($report->isClean());
        self::assertSame([['accountId' => $account->toRfc4122(), 'cachedMinor' => 1001, 'computedMinor' => 1000]], $report->accountMismatches);
        self::assertSame([['currency' => 'USD', 'totalMinor' => 1]], $report->currencyImbalances);

        $tester = new CommandTester(new Application(self::$kernel)->find('ledger:verify-balances'));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString($account->toRfc4122(), $tester->getDisplay());
    }

    private function open(string $name, string $currency): Uuid
    {
        return Uuid::fromString($this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand($name, $currency))->id);
    }

    private function balance(Uuid $id): string
    {
        return $this->service(\App\Application\Account\GetAccountHandler::class)->handle($id)->balance;
    }
}
```

Expected balances after the script:
- A: 500.00 − 120.25 = 379.75. C's 999.00 transfer is rejected for insufficient funds.
- B: 120.25 − 20.25 − 50.00 = 50.00.
- C: 20.25.
- The JPY→USD transfer is rejected with a currency mismatch.

`tests/Integration/Console/DemoSeedCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Infrastructure\Persistence\Doctrine\BalanceVerifier;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class DemoSeedCommandTest extends IntegrationTestCase
{
    public function testSeedsOnceAndLeavesTheLedgerBalanced(): void
    {
        $tester = new CommandTester(new Application(self::$kernel)->find('ledger:demo:seed'));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Alice (demo)', $tester->getDisplay());
        self::assertSame(3, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM accounts WHERE type = 'customer'"));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('already', $tester->getDisplay());
        self::assertSame(3, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM accounts WHERE type = 'customer'"));

        self::assertTrue($this->service(BalanceVerifier::class)->verify()->isClean());
    }
}
```

- [ ] **Step 2: Run the tests to watch them fail**

Run: `docker compose exec ledger-service php bin/phpunit tests/Integration/LedgerInvariantTest.php tests/Integration/Console`
Expected: FAIL with "Class App\Infrastructure\Persistence\Doctrine\BalanceVerifier not found".

- [ ] **Step 3: Implement**

`src/Infrastructure/Persistence/Doctrine/BalanceReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

final readonly class BalanceReport
{
    /**
     * @param list<array{accountId: string, cachedMinor: int, computedMinor: int}> $accountMismatches
     * @param list<array{currency: string, totalMinor: int}>                        $currencyImbalances
     */
    public function __construct(public array $accountMismatches, public array $currencyImbalances)
    {
    }

    public function isClean(): bool
    {
        return [] === $this->accountMismatches && [] === $this->currencyImbalances;
    }
}
```

`src/Infrastructure/Persistence/Doctrine/BalanceVerifier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;

/**
 * Checks the two ledger invariants: each cached balance equals the net of
 * its postings, and each currency's balances sum to zero.
 */
final class BalanceVerifier
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function verify(): BalanceReport
    {
        $mismatches = $this->connection->fetchAllAssociative(
            "SELECT a.id, a.balance_minor,
                    COALESCE(SUM(CASE WHEN p.direction = 'credit' THEN p.amount_minor ELSE -p.amount_minor END), 0) AS computed
             FROM accounts a
             LEFT JOIN postings p ON p.account_id = a.id
             GROUP BY a.id, a.balance_minor
             HAVING a.balance_minor <> COALESCE(SUM(CASE WHEN p.direction = 'credit' THEN p.amount_minor ELSE -p.amount_minor END), 0)
             ORDER BY a.id",
        );
        $imbalances = $this->connection->fetchAllAssociative(
            'SELECT currency, SUM(balance_minor) AS total FROM accounts GROUP BY currency HAVING SUM(balance_minor) <> 0 ORDER BY currency',
        );

        return new BalanceReport(
            array_map(static fn (array $row): array => [
                'accountId' => (string) $row['id'],
                'cachedMinor' => (int) $row['balance_minor'],
                'computedMinor' => (int) $row['computed'],
            ], $mismatches),
            array_map(static fn (array $row): array => [
                'currency' => (string) $row['currency'],
                'totalMinor' => (int) $row['total'],
            ], $imbalances),
        );
    }
}
```

`src/Infrastructure/Console/VerifyBalancesCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Infrastructure\Persistence\Doctrine\BalanceVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ledger:verify-balances', description: 'Check cached balances against postings and per-currency zero sum')]
final class VerifyBalancesCommand
{
    public function __construct(private readonly BalanceVerifier $verifier)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $report = $this->verifier->verify();
        if ($report->isClean()) {
            $io->success('All balances match their postings and every currency sums to zero.');

            return Command::SUCCESS;
        }

        if ([] !== $report->accountMismatches) {
            $io->error('Cached balances that differ from their postings:');
            $io->table(['Account', 'Cached (minor)', 'From postings (minor)'], array_map(
                static fn (array $row): array => [$row['accountId'], $row['cachedMinor'], $row['computedMinor']],
                $report->accountMismatches,
            ));
        }
        if ([] !== $report->currencyImbalances) {
            $io->error('Currencies whose balances do not sum to zero:');
            $io->table(['Currency', 'Total (minor)'], array_map(
                static fn (array $row): array => [$row['currency'], $row['totalMinor']],
                $report->currencyImbalances,
            ));
        }

        return Command::FAILURE;
    }
}
```

`src/Infrastructure/Console/DemoSeedCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Account\GetAccountHandler;
use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Application\Transfer\RecordDepositCommand;
use App\Application\Transfer\RecordDepositHandler;
use App\Application\Transfer\TransferMoneyCommand;
use App\Application\Transfer\TransferMoneyHandler;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'ledger:demo:seed', description: 'Create demo accounts, deposits and a transfer (runs once)')]
final class DemoSeedCommand
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OpenAccountHandler $openAccount,
        private readonly GetAccountHandler $getAccount,
        private readonly RecordDepositHandler $recordDeposit,
        private readonly TransferMoneyHandler $transferMoney,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if ((int) $this->connection->fetchOne("SELECT COUNT(*) FROM accounts WHERE type = 'customer'") > 0) {
            $io->note('Customer accounts already exist; skipping the demo seed.');

            return Command::SUCCESS;
        }

        $alice = $this->open('Alice (demo)', 'USD');
        $bob = $this->open('Bob (demo)', 'USD');
        $amani = $this->open('Amani (demo)', 'TZS');

        $this->recordDeposit->handle(new RecordDepositCommand($alice, '1000.00', 'Opening deposit', 'demo-seed-deposit-alice'));
        $this->recordDeposit->handle(new RecordDepositCommand($amani, '250000.00', 'Opening deposit', 'demo-seed-deposit-amani'));
        $this->transferMoney->handle(new TransferMoneyCommand($alice, $bob, '125.50', 'Dinner split', 'demo-seed-transfer-1'));

        $io->table(['Account', 'Id', 'Balance'], array_map(function (Uuid $id): array {
            $view = $this->getAccount->handle($id);

            return [$view->name, $view->id, $view->balance.' '.$view->currency];
        }, [$alice, $bob, $amani]));
        $io->success('Demo data created.');

        return Command::SUCCESS;
    }

    private function open(string $name, string $currency): Uuid
    {
        return Uuid::fromString($this->openAccount->handle(new OpenAccountCommand($name, $currency))->id);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec ledger-service php bin/phpunit tests/Integration`
Expected: PASS.

- [ ] **Step 5: Run the gates and commit**

```bash
git add -A && git commit -m "feat(ledger-service): add balance verifier, invariant tests and demo seed command"
```

---

### Task 13: Concurrency guarantees under real parallel processes

**Files:**
- Create: `tests/Concurrency/transfer_worker.php`
- Test: `tests/Concurrency/ConcurrentTransferTest.php`

**Interfaces:**
- Consumes:
  - `TransferMoneyHandler` and `RecordDepositHandler`
  - `OpenAccountHandler` and `BalanceVerifier`
  - `symfony/process`
  - DAMA `StaticDriver`, to commit fixtures so worker processes can see them

- [ ] **Step 1: Check how DAMA lets a test commit**

Run: `grep -n "public static function" vendor/dama/doctrine-test-bundle/src/Doctrine/DBAL/StaticDriver.php`
Expected: `setKeepStaticConnections`, `isKeepStaticConnections`, `beginTransaction`, `commit` and `rollBack` exist. The test commits fixtures with `StaticDriver::commit()` followed by `beginTransaction()`, and cleans up the same way in `tearDown()`, so DAMA's own rollback always has a transaction to end.

- [ ] **Step 2: Write the worker script**

`tests/Concurrency/transfer_worker.php`:

```php
<?php

declare(strict_types=1);

use App\Application\Transfer\TransferMoneyCommand;
use App\Application\Transfer\TransferMoneyHandler;
use App\Domain\Shared\LedgerException;
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

// Usage: php transfer_worker.php <sourceId> <destinationId> <amount> <idempotencyKey> <startAtUnixFloat>
require dirname(__DIR__, 2).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
new Dotenv()->bootEnv(dirname(__DIR__, 2).'/.env');

[, $source, $destination, $amount, $key, $startAt] = $argv;

$kernel = new Kernel('test', false);
$kernel->boot();
$handler = $kernel->getContainer()->get('test.service_container')->get(TransferMoneyHandler::class);
assert($handler instanceof TransferMoneyHandler);

// Boot first, then wait for a shared start time so the workers really overlap.
usleep(max(0, (int) (((float) $startAt - microtime(true)) * 1_000_000)));

try {
    $result = $handler->handle(new TransferMoneyCommand(Uuid::fromString($source), Uuid::fromString($destination), $amount, 'concurrency', $key));
    echo json_encode(['result' => 'ok', 'transferId' => $result->transfer->id, 'replayed' => $result->replayed]);
} catch (LedgerException $e) {
    echo json_encode(['result' => new ReflectionClass($e)->getShortName()]);
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'message' => $e::class.': '.$e->getMessage()]);
    exit(1);
}
```

- [ ] **Step 3: Write the tests**

`tests/Concurrency/ConcurrentTransferTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Application\Transfer\RecordDepositCommand;
use App\Application\Transfer\RecordDepositHandler;
use App\Infrastructure\Persistence\Doctrine\BalanceVerifier;
use App\Tests\Support\IntegrationTestCase;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * Runs real parallel PHP processes against PostgreSQL. Fixtures are committed
 * (outside DAMA's per-test rollback) and removed again in tearDown().
 */
final class ConcurrentTransferTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $accountIds = [];

    protected function tearDown(): void
    {
        $ids = $this->accountIds;
        if ([] !== $ids) {
            $connection = $this->connection();
            $params = ['ids' => $ids];
            $types = ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING];
            $connection->executeStatement('DELETE FROM outbox_messages WHERE aggregate_id IN (SELECT id FROM transfers WHERE source_account_id IN (:ids) OR destination_account_id IN (:ids)) OR aggregate_id IN (:ids)', $params, $types);
            $connection->executeStatement('DELETE FROM postings WHERE journal_entry_id IN (SELECT journal_entry_id FROM transfers WHERE source_account_id IN (:ids) OR destination_account_id IN (:ids))', $params, $types);
            $connection->executeStatement('DELETE FROM journal_entries WHERE id IN (SELECT journal_entry_id FROM transfers WHERE source_account_id IN (:ids) OR destination_account_id IN (:ids))', $params, $types);
            $connection->executeStatement('DELETE FROM transfers WHERE source_account_id IN (:ids) OR destination_account_id IN (:ids)', $params, $types);
            $connection->executeStatement('DELETE FROM accounts WHERE id IN (:ids)', $params, $types);
            $connection->executeStatement(
                "UPDATE accounts s SET balance_minor = COALESCE((SELECT SUM(CASE WHEN p.direction = 'credit' THEN p.amount_minor ELSE -p.amount_minor END) FROM postings p WHERE p.account_id = s.id), 0) WHERE s.type = 'settlement'",
            );
            StaticDriver::commit();
            StaticDriver::beginTransaction();
        }
        parent::tearDown();
    }

    public function testParallelTransfersNeverOverdraw(): void
    {
        $source = $this->fundedAccount('100.00');
        $destination = $this->fundedAccount(null);
        $this->commitFixtures();

        $results = $this->runWorkers(array_map(
            static fn (int $i): array => [$source, $destination, '40.00', "overdraw-{$i}"],
            range(1, 5),
        ));

        self::assertSame(2, \count(array_filter($results, static fn (array $r): bool => 'ok' === $r['result'])), json_encode($results, \JSON_THROW_ON_ERROR));
        self::assertSame(3, \count(array_filter($results, static fn (array $r): bool => 'InsufficientFunds' === $r['result'])));
        self::assertSame(2000, $this->balanceMinor($source));
        self::assertSame(8000, $this->balanceMinor($destination));
        self::assertTrue($this->service(BalanceVerifier::class)->verify()->isClean());
    }

    public function testOppositeDirectionTransfersDoNotDeadlock(): void
    {
        $a = $this->fundedAccount('100.00');
        $b = $this->fundedAccount('100.00');
        $this->commitFixtures();

        $results = $this->runWorkers(array_map(
            static fn (int $i): array => 0 === $i % 2 ? [$a, $b, '1.00', "swap-{$i}"] : [$b, $a, '1.00', "swap-{$i}"],
            range(1, 10),
        ));

        self::assertSame(array_fill(0, 10, 'ok'), array_column($results, 'result'), json_encode($results, \JSON_THROW_ON_ERROR));
        self::assertSame(10000, $this->balanceMinor($a));
        self::assertSame(10000, $this->balanceMinor($b));
    }

    public function testSameIdempotencyKeyFromManyWorkersCreatesOneTransfer(): void
    {
        $source = $this->fundedAccount('100.00');
        $destination = $this->fundedAccount(null);
        $this->commitFixtures();

        $results = $this->runWorkers(array_fill(0, 5, [$source, $destination, '10.00', 'same-key']));

        self::assertSame(array_fill(0, 5, 'ok'), array_column($results, 'result'), json_encode($results, \JSON_THROW_ON_ERROR));
        self::assertCount(1, array_unique(array_column($results, 'transferId')));
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM transfers WHERE idempotency_key = 'same-key'"));
        self::assertSame(9000, $this->balanceMinor($source));
    }

    private function fundedAccount(?string $deposit): string
    {
        $id = $this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Concurrent', 'USD'))->id;
        $this->accountIds[] = $id;
        if (null !== $deposit) {
            $this->service(RecordDepositHandler::class)->handle(new RecordDepositCommand(Uuid::fromString($id), $deposit, null, 'fund-'.$id));
        }

        return $id;
    }

    private function commitFixtures(): void
    {
        StaticDriver::commit();
        StaticDriver::beginTransaction();
    }

    /**
     * @param list<array{string, string, string, string}> $jobs
     *
     * @return list<array<string, mixed>>
     */
    private function runWorkers(array $jobs): array
    {
        $startAt = (string) (microtime(true) + 3.0);
        $processes = [];
        foreach ($jobs as $job) {
            $process = new Process(['php', __DIR__.'/transfer_worker.php', ...$job, $startAt], env: ['APP_ENV' => 'test']);
            $process->setTimeout(60);
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $decoded = json_decode($process->getOutput(), true);
            self::assertIsArray($decoded, 'Worker output: '.$process->getOutput().$process->getErrorOutput());
            /** @var array<string, mixed> $decoded */
            $results[] = $decoded;
        }

        return $results;
    }

    private function balanceMinor(string $accountId): int
    {
        return (int) $this->connection()->fetchOne('SELECT balance_minor FROM accounts WHERE id = :id', ['id' => $accountId]);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec ledger-service php bin/phpunit tests/Concurrency`
Expected: PASS for all three.
- If a worker prints `error` with a deadlock or serialization message, the lock order is broken. Fix `PostTransferService::lockInOrder`, not the test.
- If `StaticDriver::commit()` throws "no active transaction", DAMA's extension isn't active for this test. Check `phpunit.dist.xml` (Task 1, Step 5).
- After the run, `php bin/console ledger:verify-balances --env=test` must print success. That proves the cleanup restored the test DB.

- [ ] **Step 5: Run the gates and commit**

```bash
git add -A && git commit -m "test(ledger-service): prove locking, deadlock ordering and idempotency under parallel processes"
```

---

### Task 14: API contract, documentation, ADRs and CI

**Files:**
- Create: `contracts/openapi/ledger-service.yaml`
- Create: `README.md` (repo root, replacing the stub), `services/ledger-service/README.md`
- Create: `docs/adr/0001-double-entry-postings-source-of-truth.md`, `0002-cached-balance-pessimistic-locking.md`, `0003-transactional-outbox.md`, `0004-no-auth-in-stage-1.md`, `0005-integer-minor-units.md`, `0006-php-fpm-nginx-single-container.md`, `0007-moneyphp-for-money-values.md`
- Create: `.github/workflows/ledger-service.yml`
- Modify: `docs/superpowers/specs/2026-09-23-ledger-service-stage-1-design.md`, adding a "Deviations recorded during implementation" section
- Remove: the `contracts/.gitkeep` and `docs/.gitkeep` stubs, if present

**Interfaces:**
- Consumes: every route, event and command from Tasks 1–13, used as the ground truth for the docs

- [ ] **Step 1: Write the OpenAPI 3.1 contract**

`contracts/openapi/ledger-service.yaml` must describe exactly the routes in `bin/console debug:router` (health, accounts open/show, deposits, withdrawals, transfers create/show, statement), including:
- `components.schemas`: `AccountView`, `TransferView`, `StatementView`, `StatementLine`, `OpenAccountRequest`, `FundsRequest`, `TransferRequest`, `Problem` (with optional `violations[]`)
- `components.parameters.IdempotencyKey`: a required header, 1–255 characters
- the `Idempotent-Replayed` response header on 200 replays
- the problem responses per endpoint, as in spec §5.1
- the amount pattern `^\d+(\.\d+)?$` everywhere an amount appears

Check it parses:

```bash
docker compose exec ledger-service php -r 'require "vendor/autoload.php"; var_dump(is_array(Symfony\Component\Yaml\Yaml::parseFile("/contracts/openapi/ledger-service.yaml")));'
```

Expected: `bool(true)`.

- [ ] **Step 2: Write the ADRs**

Each ADR has four headings: `# NNNN. Title`, `## Status` (Accepted, 2026-09-24), `## Context`, `## Decision` and `## Consequences`. The facts they must carry come from spec §2 and §4–§9:
- **0001:** Transfer → JournalEntry → Posting, append-only postings, and why "Transaction" is avoided.
- **0002:** the balance cache, `FOR UPDATE` in UUID order, the `ledger:verify-balances` safety net, and the rejected alternatives (sum on read, event sourcing).
- **0003:** the outbox in the same transaction, relay with `SKIP LOCKED` plus a `LockFactory` lock, at-least-once delivery with dedupe on `eventId`, and the flock store being single-host.
- **0004:** no auth; a gateway or private network is assumed; OAuth2 for Telegram/WhatsApp channel integrations is planned for stage 2.
- **0005:** integer minor units, amounts as JSON strings, and why floats are rejected.
- **0006:** php-fpm plus nginx under supervisord in one image, the relay as a separate compose service, and FrankenPHP deferred to a future experiment.
- **0007:** MoneyPHP, with the scalar-column mapping and the domain currency guard.

- [ ] **Step 3: Write the READMEs**

Root `README.md`:
- a one-paragraph pitch
- a table of the three services (ledger: stage 1 ✅; reconciliation and webhook: planned)
- a mermaid diagram:

```mermaid
flowchart LR
    client[API client] -->|HTTP JSON| ledger[ledger-service<br/>php-fpm + nginx]
    ledger -->|postings, balances, outbox<br/>one transaction| pg[(PostgreSQL 16)]
    relay[ledger-outbox-relay] -->|SKIP LOCKED batches| pg
    relay -->|ledger_events stream| redis[(Redis 7)]
    redis -.->|stage 2| recon[reconciliation-service]
    redis -.->|stage 3| hooks[webhook-service]
```

- the quickstart: `make up install migrate seed`, then `curl` examples for open, deposit (with `Idempotency-Key`), transfer, statement and a replay showing `Idempotent-Replayed: true`
- the roadmap, taken from spec §14
- links to the spec, the ADRs and `/contracts`

`services/ledger-service/README.md` covers:
- the domain model and sign convention
- the invariants
- the concurrency strategy and idempotency semantics, including the spec §6.3 simplification that rejected requests aren't stored, so retrying the key re-evaluates it
- the outbox guarantee
- the error catalogue: a table of `urn:ledger:problem:*` types against their status codes
- the console commands
- running the tests, with `make test` and what each suite proves
- configuration env vars
- the no-auth statement

- [ ] **Step 4: Write the CI workflow**

`.github/workflows/ledger-service.yml`:

```yaml
name: ledger-service

on:
  push:
    paths: ['services/ledger-service/**', 'contracts/**', '.github/workflows/ledger-service.yml']
  pull_request:
    paths: ['services/ledger-service/**', 'contracts/**', '.github/workflows/ledger-service.yml']

jobs:
  check:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: services/ledger-service
    services:
      postgres:
        image: postgres:16-alpine
        env:
          POSTGRES_USER: ledger
          POSTGRES_PASSWORD: ledger
          POSTGRES_DB: ledger
        ports: ['5432:5432']
        options: >-
          --health-cmd "pg_isready -U ledger -d ledger"
          --health-interval 5s --health-retries 10
      redis:
        image: redis:7-alpine
        ports: ['6379:6379']
    env:
      DATABASE_URL: postgresql://ledger:ledger@127.0.0.1:5432/ledger?serverVersion=16&charset=utf8
      LEDGER_EVENTS_TRANSPORT_DSN: redis://127.0.0.1:6379/ledger_events?serializer=0
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pdo_pgsql, intl, bcmath, redis, pcntl
          ini-values: date.timezone=UTC
          coverage: none
      - uses: ramsey/composer-install@v3
        with:
          working-directory: services/ledger-service
      - run: php bin/console lint:container
      - run: php bin/console cache:warmup --env=dev
      - run: php bin/console doctrine:database:create --if-not-exists --env=test
      - run: php bin/console doctrine:migrations:migrate --no-interaction --env=test
      - run: php bin/console doctrine:schema:validate --env=test
      - run: composer check
```

`.env.test` sets the transport to `in-memory://`, and CI's `DATABASE_URL` points at the service container with the `_test` suffix, which DAMA and the doctrine `when@test` config add.

- [ ] **Step 5: Record deviations in the spec**

Append to the spec:

```markdown
## 15. Deviations recorded during implementation

- Settlement accounts are seeded by a data migration (fixed UUIDs, ISO numeric suffix) instead of being created on first use; the race-safe creation path in §6.2 is no longer needed and a test asserts every supported currency has one.
- Partial indexes were replaced by plain indexes (`accounts(type, currency)`, `outbox_messages(published_at, occurred_at)`) to keep `doctrine:schema:validate` stable.
- `AccountReader` was dropped: `GetAccountHandler` maps the entity via `AccountRepository::get()`. Statements keep the DBAL `StatementReader`.
- `MoneyParser` and `MoneyFormatter` are stateless static helpers rather than injected services.
- Unique-constraint races surface as `App\Application\Port\DuplicateRecord`, thrown by `TransactionManager`, so the application layer never imports DBAL exceptions.
- The `ledger_events` DSN carries `?serializer=0`. phpredis defaults to PHP serialization of the stream field, which the Node `webhooks-service` cannot read; with it off, each entry is `message = {"body": "<envelope JSON>", "headers": {...}}`. `IntegrationEvent`'s public properties must be exactly the envelope fields so `body` is the envelope that `contracts/events/*.v1.schema.json` describes (webhook-service spec §8.1).
```

- [ ] **Step 6: Final end-to-end verification**

```bash
make down && make up && make install && make migrate && make seed && make check && make verify
curl -si localhost:8081/health
```

Expected:
- `composer check` prints no php-cs-fixer diff, `[OK] No errors` from PHPStan, and `OK` from PHPUnit
- `verify` prints the success line
- the health check returns 200

Also walk through the README quickstart `curl` commands exactly as written and confirm each output matches the README.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "docs: add OpenAPI contract, ADRs, READMEs and ledger-service CI workflow"
git log -5 --format='%B' | grep -icE 'claude|co-authored-by|generated with'   # expect 0
```

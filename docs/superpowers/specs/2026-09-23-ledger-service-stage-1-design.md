# Ledger Service — Stage 1 Design

- **Date:** 2026-09-23
- **Status:** Approved in design review, pending spec review
- **Scope:** `services/ledger-service` only, plus shared repo-root assets (`contracts/`, `docker-compose.yml`, `Makefile`, `README.md`, `docs/`, CI)

## 1. Purpose and success criteria

`ledger-platform` is a portfolio monorepo that will hold three services:
`ledger-service`, `reconciliation-service` and `webhook-service`. Stage 1
delivers **`ledger-service` end to end** as a credible double-entry ledger
that a reviewer can clone, start, exercise and read.

Stage 1 is done when:

1. `make up` (Docker Compose) starts the service, PostgreSQL, Redis and the
   outbox relay; `make migrate` and `make seed` prepare a demo dataset.
2. The HTTP API in §5 works as specified, including idempotent replay and
   RFC 9457 error responses.
3. `composer check` passes: php-cs-fixer (`@Symfony`), PHPStan level 9 and
   PHPUnit (unit, integration, functional, invariant and concurrency tests).
4. The GitHub Actions workflow runs the same checks against real Postgres and
   Redis.
5. The root README, the service README, the ADRs and the `/contracts`
   (OpenAPI plus event JSON Schemas) describe the system accurately.

## 2. Decisions

| # | Decision | Choice |
|---|---|---|
| D1 | Core model | Double-entry: `Transfer` → `JournalEntry` → `Posting`. Postings are the source of truth, and they are append-only. |
| D2 | Currency | Every account has one ISO-4217 currency. Transfers are same-currency only; a mismatch is rejected. There is no FX. |
| D3 | Transfer lifecycle | Posted immediately and synchronously. The status is `COMPLETED`, or the request is rejected with a domain error. `Idempotency-Key` is required. |
| D4 | Money in and out | Each currency has a system `SETTLEMENT` account. Deposits and withdrawals post against it. |
| D5 | Events | Transactional outbox. A relay command publishes via Symfony Messenger to a Redis streams transport. Delivery is at-least-once. |
| D6 | Balances | `accounts.balance_minor` is a cache, updated under `SELECT … FOR UPDATE` in id order. `ledger:verify-balances` checks it against postings. |
| D7 | Auth | None in stage 1; auth is documented as a gateway / private-network concern. OAuth2 for third-party channel integrations (Telegram/WhatsApp) is on the roadmap. |
| D8 | Mapping | Doctrine ORM **PHP attributes inside domain entities**, auto-mapped from `src/Domain`. |
| D9 | Statement | A derived read model (a query over postings), not a stored aggregate. |
| D10 | Runtime | A single Alpine image: `php:8.4-fpm-alpine` plus nginx under supervisord, with nginx on port 80. |
| D11 | Redis client | The phpredis extension (`ext-redis`), required by `symfony/redis-messenger`. `predis/predis` is removed. |
| D12 | Naming | Avoid "Transaction" as a domain term, because it collides with DB transactions. |

## 3. Architecture

Layered (ports and adapters) within one Symfony app, under the `App\` namespace:

```
src/
  Domain/                     # business rules; depends on nothing but Doctrine attributes and symfony/uid
    Shared/                   # Money, Currency, DomainEvent, RecordsEvents, LedgerException
    Account/                  # Account, AccountType, AccountStatus, AccountRepository, exceptions
    Ledger/                   # JournalEntry, Posting, Direction, JournalEntryRepository, exceptions
    Transfer/                 # Transfer, TransferKind, TransferStatus, TransferRepository, events, exceptions
  Application/                # use cases; depends on Domain and ports
    Port/                     # TransactionManager, EventPublisher, AccountReader, StatementReader, Clock
    Account/                  # OpenAccountHandler, GetAccountHandler, commands, views
    Transfer/                 # TransferMoneyHandler, RecordDepositHandler, RecordWithdrawalHandler,
                              # SettlementAccountProvider, commands, TransferView
    Statement/                # GetAccountStatementHandler, StatementQuery, StatementView, StatementLineView
  Infrastructure/
    Http/Controller/          # AccountController, TransferController, StatementController, HealthController
    Http/Request/             # readonly request DTOs with #[Assert\...]
    Http/ProblemDetails/      # kernel.exception listener → application/problem+json
    Persistence/Doctrine/     # repositories, DoctrineTransactionManager, DBAL readers
    Persistence/Doctrine/Outbox/  # OutboxMessage entity, OutboxEventPublisher
    Messaging/                # IntegrationEvent message, OutboxRelay service
    Console/                  # OutboxRelayCommand, VerifyBalancesCommand, DemoSeedCommand
```

Dependency rule: `Domain` never imports from `Application` or
`Infrastructure`. `Application` never imports from `Infrastructure`. Ports
(interfaces) live in `Domain` (repositories) or `Application/Port` (everything
else). Adapters are bound with autowiring. When an interface has exactly one
implementation, Symfony aliases it automatically. `#[AsAlias]` is used only
where autowiring can't pick the implementation on its own.

The empty skeleton folders `src/Entity`, `src/Repository` and `src/Controller`
are removed. `config/packages/doctrine.yaml` maps
`dir: %kernel.project_dir%/src/Domain`, `prefix: App\Domain`, `type: attribute`.

## 4. Domain model

### 4.1 Shared kernel

- **`Currency`** is a `final readonly` value object with `code` (ISO-4217,
  uppercase) and `minorUnits` (exponent). Instances come from a supported
  list: USD 2, EUR 2, GBP 2, TZS 2, JPY 0. An unknown code throws
  `UnsupportedCurrency`. It is persisted as a 3-character string column.
- **`Money`** is a `final readonly` value object with `int $minor` and
  `Currency $currency`, mapped as an `#[ORM\Embeddable]` with columns
  `*_minor` (bigint) and `*_currency` (char 3).
  - `Money::fromDecimalString(string, Currency)` parses exactly, with no
    floats. It accepts `^\d+(\.\d+)?$`, rejects more fraction digits than
    `minorUnits`, and rejects zero or negative amounts where amounts must be
    positive (via a separate `positive()` guard).
  - `toDecimalString()` formats for output.
  - `add()`, `subtract()`, `negate()`, `isNegative()`, `isZero()`,
    `equals()`, `compare()`.
  - Operations across different currencies throw `CurrencyMismatch`.
- **`DomainEvent`** is an interface with `eventName(): string`,
  `eventVersion(): int`, `aggregateId(): Uuid`, `occurredAt():
  DateTimeImmutable` and `payload(): array<string, mixed>`.
- **`RecordsEvents`** is a trait with `record(DomainEvent)` and
  `pullEvents(): list<DomainEvent>`.
- **`LedgerException`** is an abstract base for all domain exceptions and
  carries no HTTP code.

IDs are `Symfony\Component\Uid\Uuid` (v7, generated in the domain via
`Uuid::v7()`) and are stored in Doctrine `uuid` columns.

### 4.2 Account

`Account` fields:
- `id`, `name`
- `type: AccountType` (`CUSTOMER`, `SETTLEMENT`)
- `currency: Currency`
- `status: AccountStatus` (`ACTIVE`, `FROZEN`, `CLOSED`)
- `balance: Money` (the cache)
- `version: int` (`#[ORM\Version]`)
- `createdAt`, `updatedAt` (`DateTimeImmutable`)

Behaviour:
- `Account::openCustomer(name, currency, now)` and
  `Account::openSettlement(currency, now)` are the factories. Opening records
  `AccountOpened`.
- `freeze()`, `unfreeze()`, `close()`. `close()` requires a zero balance.
- `applyPosting(Direction, Money): Money` updates and returns the resulting
  balance. The sign convention is described below.
  - It rejects a currency mismatch.
  - It rejects any posting when the status isn't `ACTIVE` (`AccountNotActive`).
  - For `CUSTOMER` accounts it rejects a resulting negative balance with
    `InsufficientFunds`. `SETTLEMENT` accounts may go negative.

**Sign convention.** All accounts are treated as liability-style from the
platform's point of view: `CREDIT` increases the balance and `DEBIT`
decreases it. A settlement account's balance is therefore the negative of the
money held in custody, so across one currency every account's balance sums to
zero.

There are no public setters. The table has a unique index on `(type, currency)`
where `type = 'SETTLEMENT'`, which is a partial index created in the
migration.

### 4.3 Ledger

- **`Direction`** enum: `DEBIT`, `CREDIT`.
- **`JournalEntry`** fields: `id`, `occurredAt`, `description`, `sourceType`
  (string, e.g. `transfer`), `sourceId` (Uuid), and `postings`
  (`OneToMany`, cascade persist). There are no setters.
  - `JournalEntry::record(id, occurredAt, description, sourceType, sourceId,
    list<PostingLine>)` enforces all of these, or throws
    `UnbalancedJournalEntry`:
    - at least two lines
    - every line in the same currency
    - every amount positive
    - total debits equal total credits
- **`Posting`** fields: `id`, `journalEntry` (ManyToOne), `accountId` (Uuid,
  indexed), `direction`, `amount: Money`, `balanceAfter: Money` and
  `occurredAt`.
  - It is immutable.
  - The index `(account_id, occurred_at, id)` supports statements.

### 4.4 Transfer

- **`TransferKind`** enum: `INTERNAL`, `DEPOSIT`, `WITHDRAWAL`.
- **`TransferStatus`** enum: `COMPLETED`. Rejected requests are not persisted
  in stage 1 (see §6.3). The enum still exists, so adding `PENDING` in
  stage 2 isn't a schema-breaking change.
- **`Transfer`** fields:
  - `id`, `kind`
  - `sourceAccountId`, `destinationAccountId`
  - `amount: Money`, `description`
  - `idempotencyKey` (unique)
  - `requestHash` (sha256 of the canonicalised request)
  - `status`, `journalEntryId`, `createdAt`
- `Transfer::complete(...)` builds a completed transfer and records one of
  `TransferCompleted`, `DepositRecorded` or `WithdrawalRecorded`. It rejects
  `SameAccountTransfer` when source and destination are equal.

### 4.5 Domain exceptions

Every domain exception extends `LedgerException`:

- `AccountNotFound`
- `TransferNotFound`
- `InsufficientFunds`
- `CurrencyMismatch`
- `UnsupportedCurrency`
- `AccountNotActive`
- `AccountHasBalance` (close)
- `SameAccountTransfer`
- `InvalidAmount`
- `UnbalancedJournalEntry`
- `IdempotencyConflict`

## 5. HTTP API

Everything is JSON. Amounts are decimal strings in the account currency's
minor-unit precision (`"50.00"`, `"1200"` for JPY). Controllers extend
`AbstractController`, bind input with `#[MapRequestPayload]` or
`#[MapQueryString]`, and delegate to one handler.

| Method | Path | Body / query | Success |
|---|---|---|---|
| POST | `/accounts` | `{name, currency}` | 201 `AccountView` plus a `Location` header |
| GET | `/accounts/{id}` | — | 200 `AccountView` |
| POST | `/accounts/{id}/deposits` | `{amount, description?}` + `Idempotency-Key` | 201 `TransferView` (200 on replay) |
| POST | `/accounts/{id}/withdrawals` | `{amount, description?}` + `Idempotency-Key` | 201 `TransferView` (200 on replay) |
| POST | `/transfers` | `{sourceAccountId, destinationAccountId, amount, description?}` + `Idempotency-Key` | 201 `TransferView` (200 on replay) |
| GET | `/transfers/{id}` | — | 200 `TransferView` |
| GET | `/accounts/{id}/statement` | `?from=YYYY-MM-DD&to=YYYY-MM-DD` (default: the last 30 days, max 366) | 200 `StatementView` |
| GET | `/health` | — | 200 `{status: "ok", checks: {database}}` |

The views:

- **`AccountView`**: `id`, `name`, `type`, `currency`, `status`, `balance`
  (a decimal string), `createdAt`.
- **`TransferView`**: `id`, `kind`, `sourceAccountId`,
  `destinationAccountId`, `amount`, `currency`, `description`, `status`,
  `journalEntryId`, `createdAt`.
- **`StatementView`**:
  - `accountId`, `currency`, `from`, `to`
  - `openingBalance`, `closingBalance`
  - `lines[]`, each with `postingId`, `occurredAt`, `description`,
    `transferId`, `direction`, `amount`, `balanceAfter`
  - Lines are ordered by `(occurred_at, id)`.

`Idempotency-Key` is required on the three money-moving endpoints. It must be
1–255 characters. A missing key returns 400 problem+json.

### 5.1 Error mapping (RFC 9457 `application/problem+json`)

A `kernel.exception` listener (`#[AsEventListener]`) produces
`{type, title, status, detail, instance}`. The `type` is a stable URN such as
`urn:ledger:problem:insufficient-funds`. Validation failures add
`violations[]`.

| Exception | Status |
|---|---|
| `AccountNotFound`, `TransferNotFound` | 404 |
| `IdempotencyConflict` | 409 |
| `InsufficientFunds`, `CurrencyMismatch`, `AccountNotActive`, `SameAccountTransfer`, `InvalidAmount`, `UnsupportedCurrency`, `AccountHasBalance` | 422 |
| Validation failure (from `MapRequestPayload`) | 422 |
| Malformed JSON / missing `Idempotency-Key` | 400 |
| Anything else | 500 (generic detail; nothing internal leaks when `kernel.debug` is false) |

## 6. Application flows

### 6.1 TransactionManager port

```php
interface TransactionManager
{
    /** @template T @param callable(): T $operation @return T */
    public function transactional(callable $operation): mixed;
}
```

The Doctrine adapter uses `EntityManagerInterface::wrapInTransaction()`. If
an exception is thrown, it rolls back and the EntityManager is cleared.

### 6.2 Money movement (transfer, deposit, withdrawal)

Transfers, deposits and withdrawals share one private flow in a
`PostTransferService` (Application/Transfer). Each handler builds a
`PostTransferCommand` with `kind`, source, destination, amount, description,
idempotency key and request hash.

1. **Fast replay check**, outside the transaction:
   `transferRepository->findByIdempotencyKey(key)`.
   - With an equal `requestHash`, return the existing `TransferView` and
     `replayed = true`.
   - With a different hash, throw `IdempotencyConflict`.
2. Inside `transactional()`:
   1. For deposits and withdrawals, resolve the settlement account with
      `SettlementAccountProvider::forCurrency(currency)`. It finds the account
      or creates it. Creation is race-safe: it relies on the partial unique
      index, and on a violation it re-reads.
   2. Load both accounts with `accountRepository->getForUpdate(id)`
      (`PESSIMISTIC_WRITE`), **ordered by UUID ascending** so that
      concurrent opposite-direction transfers don't deadlock. A missing
      account throws `AccountNotFound`.
   3. Parse the amount with the **account's** currency, which enforces
      precision. Reject any currency mismatch between the two accounts.
   4. `source->applyPosting(DEBIT, amount)` and
      `destination->applyPosting(CREDIT, amount)`, capturing `balanceAfter`
      for each. Any domain rule violation throws, and the transaction rolls
      back.
   5. `JournalEntry::record(...)` with both lines and the balances after.
   6. `Transfer::complete(...)`.
   7. Persist the journal entry and the transfer. Then pull events from the
      transfer and accounts and pass them to `EventPublisher::publish()`,
      which persists `OutboxMessage` rows.
   8. Flush.
3. **Concurrent first requests with the same key:** the unique index on
   `transfers.idempotency_key` makes the second flush fail with
   `UniqueConstraintViolationException`.
   - The service catches it outside the rolled-back transaction, resets the
     EntityManager, and re-reads by key.
   - It then applies the step 1 logic.

Deposit: source = settlement, destination = customer.
Withdrawal: source = customer, destination = settlement.

### 6.3 Rejected requests

Domain rejections (insufficient funds and so on) roll back and aren't
persisted. Retrying with the same idempotency key re-evaluates the request,
so a deposit can make a previously rejected transfer succeed. The README
documents this as a deliberate stage 1 simplification. Persisting rejected
attempts is a stage 2 roadmap item.

### 6.4 Account opening

`OpenAccountHandler` rejects a currency outside the supported list with
`UnsupportedCurrency`. It persists the account and publishes `AccountOpened`
through the outbox, in one transaction. Settlement accounts can't be created
through the API.

### 6.5 Read side

- `AccountReader` and `StatementReader` are ports in `Application/Port`, with
  DBAL adapters that return view DTOs without hydrating entities.
- `TransferView` is built from the `Transfer` entity by the handler.
- **Statement query:**
  - the opening balance is `balance_after` of the last posting before `from`,
    or zero
  - the lines are postings with `from <= occurred_at < to + 1 day`, joined to
    `journal_entries` for the description and source id
  - the closing balance is the last line's `balance_after`, or the opening
    balance if there are no lines
  - no window function is needed, because `balance_after` is stored per
    posting and ordering is deterministic

## 7. Events and outbox

### 7.1 Events (v1)

| Event name | Aggregate | Payload |
|---|---|---|
| `ledger.account_opened` | account | `accountId`, `name`, `type`, `currency`, `openedAt` |
| `ledger.transfer_completed` | transfer | `transferId`, `journalEntryId`, `sourceAccountId`, `destinationAccountId`, `amount` (decimal string), `amountMinor`, `currency`, `description`, `completedAt` |
| `ledger.deposit_recorded` | transfer | same fields as `transfer_completed` |
| `ledger.withdrawal_recorded` | transfer | same fields as `transfer_completed` |

The envelope, written by the relay, is `{eventId, eventName, eventVersion,
occurredAt, aggregateId, payload}`. `eventId` is the outbox row id, and
consumers use it for deduplication.

### 7.2 Outbox

- **`outbox_messages` table** (the `OutboxMessage` entity):
  - `id` (uuid v7), `event_name`, `event_version`, `aggregate_id`
  - `payload` (jsonb), `occurred_at`
  - `published_at` (nullable), `attempts` (int), `last_error` (text,
    nullable)
  - a partial index on `occurred_at` where `published_at IS NULL`
- **`OutboxEventPublisher`** implements `EventPublisher`. It only calls
  `persist()`, and the surrounding transaction flushes.
- **`OutboxRelay`** is a service. `ledger:outbox:relay` is an `#[AsCommand]`
  with the options `--batch=100`, `--loop` and `--sleep=1000` (ms).
  1. Acquire the `LockFactory` lock `ledger-outbox-relay`. If it isn't
     acquired, exit 0 (non-loop) or wait (loop).
  2. In a transaction, select up to `batch` unpublished rows ordered by
     `occurred_at, id`, using `FOR UPDATE SKIP LOCKED`.
  3. Dispatch each row as `IntegrationEvent` (a readonly message carrying the
     envelope) on the Messenger bus. The bus routes `IntegrationEvent` to the
     `ledger_events` transport.
  4. On success, set `published_at`. On failure, increment `attempts`, set
     `last_error` and continue.
  5. Commit.
- **Transports:**
  - `ledger_events`: `redis://redis:6379/ledger_events` in dev and prod,
    `in-memory://` in test.
  - The lock store is `flock` locally. The README notes that a multi-host
    deployment should use a Redis or Postgres store.
- The delivery guarantee is **at-least-once**: a crash between dispatch and
  commit republishes the event.

### 7.3 Contracts

- `contracts/events/ledger.<name>.v1.schema.json`: JSON Schema (draft
  2020-12) for each envelope plus payload.
- `contracts/openapi/ledger-service.yaml`: OpenAPI 3.1, hand-written, for §5
  including the problem+json schema.
- A functional test validates emitted envelopes against these schemas using
  `opis/json-schema`.

## 8. Operations commands

- `ledger:outbox:relay`: see §7.2.
- `ledger:verify-balances`: for each account, compare `balance_minor` with
  `SUM(credits) - SUM(debits)` over its postings, and for each currency
  assert that the balances sum to zero.
  - It prints a table of mismatches.
  - It exits 1 if any are found.
- `ledger:demo:seed`: idempotent (fixed idempotency keys). It opens "Alice"
  and "Bob" USD accounts plus one TZS account, deposits funds, and makes one
  transfer.

## 9. Infrastructure

### 9.1 Packages

Installed with `composer require`, so Flex recipes wire the config:
`symfony/messenger`, `symfony/redis-messenger`, `symfony/lock`,
`symfony/monolog-bundle`.

Dev: `symfony/test-pack`, `dama/doctrine-test-bundle`.

Removed: `predis/predis`.

`symfony/serializer`, `symfony/validator` and `symfony/uid` are already
present. `composer.json` requires `ext-redis`, `ext-bcmath` is not needed
(integer arithmetic), and `ext-pdo_pgsql` is declared.

### 9.2 Container image (`services/ledger-service/Dockerfile`)

- Based on `php:8.4-fpm-alpine`.
- Extensions: `pdo_pgsql`, `intl`, `opcache`, `redis` (pecl).
- nginx and supervisor are installed from apk.
- `docker/nginx.conf`: `root /app/public`, with `try_files` to `index.php`
  and FastCGI to `127.0.0.1:9000`.
- `docker/supervisord.conf`: the programs `php-fpm -F` and
  `nginx -g 'daemon off;'`, logging to stdout/stderr.
- `docker/php.ini` and `docker/opcache.ini`.
- Multi-stage build:
  - `base` holds the extensions and config
  - `dev` adds composer, with the source mounted as a volume
  - `prod` runs `composer install --no-dev --classmap-authoritative`, warms
    the cache, and runs as a non-root user
- Entrypoint: `supervisord`, exposing port 80.

### 9.3 Compose (repo root `docker-compose.yml`)

| Service | Image / command | Ports / notes |
|---|---|---|
| `ledger-service` | build `services/ledger-service`, target `dev` | 8081:80, depends on healthy postgres and redis |
| `ledger-outbox-relay` | same image, `php bin/console ledger:outbox:relay --loop` | no port, restart: unless-stopped |
| `postgres` | `postgres:16-alpine` | healthcheck via `pg_isready`, named volume, db `ledger` |
| `redis` | `redis:7-alpine` | healthcheck via `redis-cli ping` |

`.env` holds only defaults that point at the compose hostnames. Secrets go in
`.env.local` or the vault.

### 9.4 Root Makefile

The targets are `help`, `up`, `down`, `build`, `logs`, `sh`, `migrate`,
`seed`, `test`, `check`, `verify`. All of them run inside the
`ledger-service` container via `docker compose exec`.

## 10. Testing strategy

| Layer | Base class | Covers |
|---|---|---|
| Unit | `PHPUnit\Framework\TestCase` | `Money`/`Currency` parsing and arithmetic; `JournalEntry` invariants; `Account` rules (no negative for customers, status gating, close at zero); `Transfer::complete` rules |
| Integration | `KernelTestCase` | repositories; `getForUpdate`; `StatementReader` (opening, lines, closing across date ranges); outbox rows committed with postings and absent after a rollback; `OutboxRelay` dispatches to the in-memory transport and marks rows published; envelopes validate against `/contracts` schemas |
| Functional | `WebTestCase` | every endpoint: success, problem+json errors, validation `violations[]`, idempotent replay (200, identical body), key reuse with a different body (409), missing key (400) |
| Invariant | `KernelTestCase` | a scripted mix of deposits, transfers and withdrawals, then balances per currency sum to zero and each cached balance equals the sum of its postings (shares logic with `ledger:verify-balances`) |
| Concurrency | `KernelTestCase` (no DAMA) | two child processes (`symfony/process` or `pcntl_fork`) each try to transfer the full balance out of one account; exactly one succeeds, the balance is never negative, and there are no deadlocks |

- The test DB is PostgreSQL (`ledger_test`), because locking semantics matter,
  so SQLite isn't an option.
- `dama/doctrine-test-bundle` isolates tests except the concurrency test,
  which cleans up after itself.
- A feature isn't done until it has a caller-level test (AGENTS.md).

## 11. Quality gates and CI

- `composer check` = `lint` (php-cs-fixer dry-run) + `stan` (PHPStan level 9
  with the doctrine and symfony extensions) + `test`.
- `.github/workflows/ledger-service.yml` runs on push or PR with path filters
  `services/ledger-service/**`, `contracts/**` and the workflow file itself.
  - Postgres 16 and Redis 7 run as service containers.
  - It sets up PHP 8.4 with `pdo_pgsql`, `intl` and `redis`.
  - Steps:
    1. `composer install`
    2. `lint:container`
    3. `doctrine:migrations:migrate --no-interaction` against the test DB
    4. `doctrine:schema:validate`
    5. `composer check`

## 12. Documentation

- **Root `README.md`**:
  - the platform vision and the three services' responsibilities
  - a mermaid architecture diagram (client → ledger-service → Postgres;
    outbox relay → Redis → future reconciliation and webhook consumers)
  - the stage roadmap
  - a quickstart (`make up migrate seed`) with curl examples
- **`services/ledger-service/README.md`**:
  - the domain model and invariants, sign convention, concurrency and
    idempotency strategy, the outbox guarantee and the error catalogue
  - how to run the tests
  - the decision to omit auth
  - the §6.3 simplification
- **`docs/adr/`**, each with context, decision and consequences:
  - 0001-double-entry-postings-source-of-truth
  - 0002-cached-balance-pessimistic-locking
  - 0003-transactional-outbox
  - 0004-no-auth-in-stage-1
  - 0005-integer-minor-units
  - 0006-php-fpm-nginx-single-container

## 13. Existing code disposition

The current `src/` draft can't be compiled (missing imports, empty files,
float money, setters). It is rewritten to this design rather than patched.
Concepts that carry over:

- the `Account`/`Transfer`/`Shared` domains
- the repository ports
- the `TransactionManager` and `EventPublisher` ports (with the new
  `transactional()` signature)
- the outbox publisher and relay command names

`AccountType` values `CHECKING`, `SAVINGS`, `CREDIT_CARD`, `LOAN` and `OTHER`
are replaced by `CUSTOMER` and `SETTLEMENT`. Product types are stage 2 and
would need normal-balance semantics. `customerId` is dropped until a
customer service exists.

## 14. Out of scope (roadmap)

- **Stage 2:**
  - holds and two-phase transfers (available vs ledger balance)
  - persisted rejected attempts
  - account product types
  - OAuth2 for third-party channel integrations (Telegram/WhatsApp)
  - `reconciliation-service`
- **Stage 3:** `webhook-service` consuming `ledger_events`, and statement
  snapshots or PDFs.
- **Not planned yet:** FX and cross-currency transfers, and a FrankenPHP
  runtime experiment.

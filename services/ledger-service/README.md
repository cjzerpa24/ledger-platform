# ledger-service

A double-entry ledger API: accounts, deposits, withdrawals, transfers and
statements. Every movement of money is a balanced journal entry, balances
are cached under row locks, and every change is announced on the
`ledger_events` Redis stream through a transactional outbox.

- API contract: [`contracts/openapi/ledger-service.yaml`](../../contracts/openapi/ledger-service.yaml)
- Event contracts: [`contracts/events/`](../../contracts/events/)
- Design: [`docs/superpowers/specs/2026-09-23-ledger-service-stage-1-design.md`](../../docs/superpowers/specs/2026-09-23-ledger-service-stage-1-design.md)
- Decisions: [`docs/adr/`](../../docs/adr/)

Stack: PHP 8.4, Symfony 8.1, Doctrine ORM 3 / DBAL 4 on PostgreSQL 16,
moneyphp/money, Symfony Messenger (Redis streams) and Lock, in one
php-fpm + nginx image.

## Run it

From the repo root:

```bash
make up-ledger-service   # postgres, redis, loki, grafana, the API on :8081 and the outbox relay
make install             # composer install, plus a local APP_SECRET
make migrate             # schema plus one settlement account per currency
make seed                # optional demo accounts (runs once)
make check               # php-cs-fixer, PHPStan level 9, PHPUnit
make verify              # cached balances vs postings
```

The root [README](../../README.md#quickstart) walks through the API with `curl`.

## Domain model

- **Account:** `customer` or `settlement`, one currency, a status
  (`active`, `frozen` or `closed`) and a cached balance. Each supported
  currency (USD, EUR, GBP, TZS with 2 decimals; JPY with 0) has one
  settlement account, seeded by a migration with a fixed id ending in the ISO
  numeric code (USD is `0199a000-0000-7000-8000-000000000840`).
- **Transfer:** one movement of money, kind `internal` (customer to
  customer), `deposit` (settlement to customer) or `withdrawal` (customer to
  settlement). Always the same currency on both sides.
- **JournalEntry:** the double-entry record of a transfer: postings whose
  debits equal their credits.
- **Posting:** one line of a journal entry against one account: a
  `direction`, an amount and the account's `balance_after`. Postings are
  append-only.

**Sign convention:** a `credit` posting increases an account's balance, a
`debit` decreases it. A deposit credits the customer and debits the
settlement account, so a settlement balance goes negative as money enters the
ledger, and every currency always sums to zero.

## Invariants

1. Every journal entry balances, in one currency.
2. Each account's cached `balance_minor` equals the net of its postings.
3. Each currency's balances sum to zero.
4. A customer balance never goes below zero.

`make verify` (`ledger:verify-balances`) checks 2 and 3 against the database
and exits 1 on any mismatch. The invariant, seed and overdraw tests run the
same check, and the unit and integration suites pin 1 and 4 at the domain
level.

## Concurrency and idempotency

- Money-moving requests lock both accounts with `SELECT … FOR UPDATE` in
  ascending UUID order, whatever the direction, so concurrent transfers on
  the same account serialize and opposite-direction transfers cannot
  deadlock ([ADR 0002](../../docs/adr/0002-cached-balance-pessimistic-locking.md)).
- Deposits, withdrawals and transfers require an `Idempotency-Key` header
  (1–255 characters). The key is stored with a hash of the request:
  - the same key with the same request returns the original transfer with
    `200` and `Idempotent-Replayed: true`, and nothing moves again;
  - the same key with a different request is a `409 idempotency-conflict`;
  - two simultaneous first requests with one key create exactly one transfer
    (a unique constraint decides the race; the loser replays the winner).
- **Rejected requests are not stored.** If a transfer is refused (say, for
  insufficient funds), retrying the same key re-evaluates it, so it can
  succeed after a deposit. This is a deliberate stage 1 simplification;
  persisting rejected attempts is on the roadmap.

## Outbox and events

Account openings and completed transfers, deposits and withdrawals are
written to `outbox_messages` in the same database transaction as the
postings. The `ledger-outbox-relay` container (`ledger:outbox:relay --loop`)
publishes them to the `ledger_events` Redis stream in order, so an event
exists exactly when its money movement committed. Delivery is at least once;
consumers deduplicate on `eventId`
([ADR 0003](../../docs/adr/0003-transactional-outbox.md)).

Each stream entry is Messenger's frame `message = {"body": "<envelope>",
"headers": {...}}`. The envelope is
`{eventId, eventName, eventVersion, occurredAt, aggregateId, payload}` and
matches `contracts/events/ledger.<name>.v1.schema.json`. The events are
`ledger.account_opened`, `ledger.transfer_completed`,
`ledger.deposit_recorded` and `ledger.withdrawal_recorded`.

## Errors

Every error is RFC 9457 `application/problem+json` with `type`, `title`,
`status`, `detail` and `instance`.

| `type` | Status | When |
|---|---|---|
| `urn:ledger:problem:validation-failed` | 422 | invalid body or query fields; lists `violations[]` |
| `urn:ledger:problem:invalid-amount` | 422 | zero, too many decimals for the currency, or too large |
| `urn:ledger:problem:unsupported-currency` | 422 | a currency other than USD, EUR, GBP, TZS or JPY |
| `urn:ledger:problem:insufficient-funds` | 422 | the source account can't cover the amount |
| `urn:ledger:problem:currency-mismatch` | 422 | a transfer between accounts in different currencies |
| `urn:ledger:problem:same-account-transfer` | 422 | source and destination are the same account |
| `urn:ledger:problem:account-not-active` | 422 | a frozen or closed account |
| `urn:ledger:problem:invalid-statement-period` | 422 | `from` after `to`, or more than 366 days |
| `urn:ledger:problem:account-not-found` | 404 | unknown account id |
| `urn:ledger:problem:transfer-not-found` | 404 | unknown transfer id |
| `urn:ledger:problem:idempotency-conflict` | 409 | a key reused with a different request |
| `about:blank` | 400 | malformed JSON, or a missing or over-long `Idempotency-Key` |
| `about:blank` | 500 | anything unexpected; no internals leak outside debug mode |

`unbalanced-journal-entry` and `settlement-account-missing` guard internal
invariants and should never reach a client. `account-has-balance` guards
closing an account, which has no endpoint yet.

## Console commands

| Command | What it does |
|---|---|
| `ledger:outbox:relay [--batch=100] [--loop] [--sleep=1000]` | publishes pending outbox rows; one relay at a time (Symfony Lock) |
| `ledger:verify-balances` | checks invariants 2 and 3; exit 1 and a table on mismatch |
| `ledger:demo:seed` | creates Alice and Bob (USD) and Amani (TZS) with deposits and a transfer; skips if customer accounts exist |

Run them with `make sh` then `php bin/console …`, or through `make verify`
and `make seed`.

## Tests

`make test` runs everything against a `ledger_test` database; each test runs
in a transaction that is rolled back (DAMA), except the concurrency suite.

| Suite | Proves |
|---|---|
| `tests/Unit` | money parsing and formatting edge cases, and the account, journal entry and transfer rules |
| `tests/Integration` | repositories and locking, the outbox written with the postings, the relay, statements over date boundaries, the balance invariants and the demo seed |
| `tests/Functional` | every endpoint over HTTP, including idempotent replay and conflict, malformed amounts and the problem responses (`account-not-active` is covered at integration level, as nothing can freeze an account over HTTP yet) |
| `tests/Concurrency` | real parallel PHP processes: no overdraw, no deadlock, one transfer per idempotency key |

## Configuration

| Variable | Default | |
|---|---|---|
| `DATABASE_URL` | `postgresql://ledger:ledger@postgres:5432/ledger?…` | the test env appends `_test` to the database name |
| `LEDGER_EVENTS_TRANSPORT_DSN` | `redis://redis:6379/ledger_events?serializer=0&auto_setup=false&delete_after_ack=false&stream_max_entries=100000` | plain JSON entries, no consumer group of its own, stream capped at about 100k entries; tests force `in-memory://` |
| `LOCK_DSN` | `flock` | single host only; use a Redis or PostgreSQL store across hosts |
| `APP_SECRET` | empty | `make install` writes a random one to the git-ignored `.env.dev.local` |

## Authentication

There is none in stage 1
([ADR 0004](../../docs/adr/0004-no-auth-in-stage-1.md)): anyone who can reach
the API can move money. Run it behind a gateway or on a private network.

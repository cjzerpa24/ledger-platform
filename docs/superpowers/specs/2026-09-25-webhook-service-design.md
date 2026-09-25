# Webhook Service — Stage 1 Design

- **Date:** 2026-09-25
- **Status:** Approved in design review, pending spec review
- **Scope:** `services/webhook-service`, its wiring in the repo-root `docker-compose.yaml` and `Makefile`, the shared `contracts/events/` schemas, and two configuration changes in `ledger-service` (§8)

## 1. Purpose and success criteria

`webhook-service` delivers ledger events to external subscribers. A client
registers an HTTPS URL and the event types it wants. The service consumes
the events that `ledger-service` publishes to Redis, and POSTs each matching
event to each subscriber, signed, retried with backoff, and recorded in a
delivery log.

Stage 1 is done when:

1. `make up-webhook-service` starts the service with Postgres and Redis, and
   `make webhook-migrate` prepares its database.
2. A subscription created through the API receives a correctly signed POST
   for every matching ledger event, including events published while the
   service was down.
3. A failing subscriber is retried on the §4.4 schedule and ends `dead`
   after 8 failed attempts. A `dead` delivery can be retried by hand.
4. Duplicate stream messages never produce duplicate deliveries.
5. `npm run lint`, `npm run typecheck`, `npm test` and `npm run test:e2e`
   pass, the last two against real Postgres and Redis (Testcontainers).

## 2. Decisions

| # | Decision | Choice |
|---|---|---|
| W1 | Role | Outbound fan-out: ledger events → registered subscribers. No inbound third-party webhooks. |
| W2 | Stack | NestJS on Node 24 LTS, TypeScript `strict`. |
| W3 | Event source | The `ledger_events` Redis stream, read with a consumer group (`XREADGROUP` / `XACK`). Delivery into the service is at-least-once. |
| W4 | Persistence | Prisma on PostgreSQL 16, in a dedicated `webhooks` database on the shared instance. Prisma types never leave `infrastructure/`. |
| W5 | Delivery queue | A `deliveries` table in Postgres, claimed with `FOR UPDATE SKIP LOCKED` and a lease. No BullMQ. |
| W6 | Signing | The [Standard Webhooks](https://www.standardwebhooks.com/) scheme: HMAC-SHA256 with `webhook-id`, `webhook-timestamp` and `webhook-signature` headers. |
| W7 | Payload | The ledger envelope is forwarded as-is. `payload` is opaque to this service. |
| W8 | Processes | One Nest app with three roles (`api`, `consumer`, `dispatcher`), selected by `ROLES`. All three run in one container by default. |
| W9 | Auth | None in stage 1. The API is an internal admin API on the compose network, the same stance as ledger-service D7. |
| W10 | Errors | RFC 9457 `application/problem+json`, `type` = `urn:webhooks:problem:<kebab-name>`. |

## 3. Architecture

Ports and adapters inside one bounded context, `webhooks`. The layers
depend inwards only: `infrastructure` → `application` → `domain`. An ESLint
`no-restricted-imports` rule enforces it: `domain` imports nothing from
Nest, Prisma, ioredis or the other layers, and `application` imports
nothing from `infrastructure`.

```
services/webhook-service/
  Dockerfile, .dockerignore, package.json, tsconfig*.json, eslint.config.mjs, jest configs
  prisma/schema.prisma, prisma/migrations/
  src/
    main.ts                      # bootstraps the roles listed in ROLES
    app.module.ts
    shared/
      config/                    # zod env schema, typed AppConfig
      health/                    # /health (terminus: prisma + redis)
      problem-details/           # ProblemDetailsFilter
    webhooks/
      domain/
        subscription/            # Subscription, TargetUrl, EventType, SigningSecret, SubscriptionRepository, errors
        delivery/                # Delivery, DeliveryAttempt, DeliveryStatus, RetryPolicy, DeliveryRepository, errors
        event/                   # LedgerEvent
      application/
        ports/                   # Clock, HttpSender, Signer, SecretGenerator, UnitOfWork
        register-subscription.ts, pause-subscription.ts, resume-subscription.ts,
        get-subscription.ts, list-subscriptions.ts,
        ingest-ledger-event.ts, dispatch-due-deliveries.ts,
        list-deliveries.ts, get-delivery.ts, retry-delivery.ts
      infrastructure/
        http/                    # SubscriptionsController, DeliveriesController, DTOs, error mapping
        persistence/             # PrismaService, Prisma repositories, mappers, PrismaUnitOfWork
        messaging/               # RedisStreamConsumer, MessengerEnvelopeDecoder
        delivery/                # FetchHttpSender, StandardWebhooksSigner, DispatcherLoop
        system/                  # SystemClock, CryptoSecretGenerator
      webhooks.module.ts
  test/
    unit/ (mirrors src/), integration/, e2e/, support/
```

Use cases are plain classes that take their ports through the constructor,
and Nest wires them with injection tokens. None of them extends or
decorates Nest types, so unit tests construct them directly.

## 4. Domain model

### 4.1 Subscription (aggregate)

| Field | Type | Rule |
|---|---|---|
| `id` | uuid v7 | generated in the domain factory |
| `url` | `TargetUrl` | absolute URL, `https` only; `http` allowed when `ALLOW_INSECURE_URLS=true`; max 2048 chars; no credentials in the URL |
| `eventTypes` | `EventType[]` | non-empty, no duplicates, each one of the four names in §4.3 |
| `secret` | `SigningSecret` | `whsec_` + base64 of 32 random bytes |
| `status` | `active` \| `paused` | starts `active` |
| `description` | `string \| null` | max 255 chars |
| `createdAt` | `Date` | from `Clock` |

Behaviour: `Subscription.register(...)`, `pause()`, `resume()` (both
idempotent), `matches(eventName)`, which is true when `active` and
`eventName` is among `eventTypes`.

The secret is returned only in the create response. It is stored in
plaintext because signing needs it; encryption at rest and rotation are out
of scope (§13).

### 4.2 LedgerEvent (value object)

The envelope written by the ledger outbox relay:
`{eventId, eventName, eventVersion, occurredAt, aggregateId, payload}`.

`LedgerEvent.parse(unknown)` accepts it when `eventId` and `aggregateId` are
UUIDs, `eventName` is a non-empty string, `eventVersion` is a positive
integer, `occurredAt` is an ISO-8601 timestamp and `payload` is a JSON
object. Otherwise it throws `MalformedLedgerEvent`. An unknown `eventName`
is **valid**; it simply matches no subscription.

### 4.3 EventType

`ledger.account_opened`, `ledger.transfer_completed`,
`ledger.deposit_recorded`, `ledger.withdrawal_recorded` (ledger spec §7.1).
Subscribing to anything else raises `UnknownEventType`.

### 4.4 Delivery (aggregate)

One event to one subscription.

| Field | Type | Rule |
|---|---|---|
| `id` | uuid v7 | |
| `subscriptionId`, `eventId` | uuid | unique together, which is the dedupe key |
| `eventName` | string | |
| `body` | JSON | snapshot of the full envelope, sent as the request body |
| `status` | `pending` \| `succeeded` \| `dead` | starts `pending` |
| `attemptCount` | int | |
| `nextAttemptAt` | `Date` | starts at creation time |
| `lastError` | `string \| null` | |
| `attempts` | `DeliveryAttempt[]` | the delivery log: `number`, `at`, `statusCode \| null`, `error \| null`, `durationMs` |

Behaviour:

- `recordSuccess(statusCode, durationMs, now)` → `succeeded`, appends an attempt.
- `recordFailure({statusCode?, error}, durationMs, now, policy)` appends an
  attempt and either sets `nextAttemptAt = now + policy.delayAfter(attemptCount)`
  or, once `attemptCount` reaches `policy.maxAttempts`, sets `dead`.
- `retry(now)` is allowed only from `dead` (otherwise `DeliveryNotRetryable`).
  It sets `pending`, `attemptCount = 0`, `nextAttemptAt = now`, and keeps the
  attempt history.

**RetryPolicy** is a fixed schedule: after failure 1…7 wait 10s, 30s, 2m,
10m, 30m, 1h, 3h; failure 8 makes the delivery `dead` (`maxAttempts = 8`).
No jitter, so tests are deterministic.

**Outcome of one attempt:** a 2xx status is a success. Any other status, a
timeout (`DELIVERY_TIMEOUT_MS`, default 10000) or a network error is a
failure. Redirects are not followed and count as failures. The response
body is not stored; `error` holds at most 500 chars of the reason.

### 4.5 Domain errors

`InvalidTargetUrl`, `UnknownEventType`, `SubscriptionNotFound`,
`DeliveryNotFound`, `DeliveryNotRetryable`, `MalformedLedgerEvent`. All
extend `WebhooksError` and carry no HTTP status.

## 5. HTTP API

| Method | Path | Success |
|---|---|---|
| `POST` | `/subscriptions` | 201, body `{url, eventTypes, description?}`; the response includes `secret` |
| `GET` | `/subscriptions` | 200, list without `secret` |
| `GET` | `/subscriptions/:id` | 200, without `secret` |
| `POST` | `/subscriptions/:id/pause` | 200 |
| `POST` | `/subscriptions/:id/resume` | 200 |
| `GET` | `/subscriptions/:id/deliveries?status=&limit=&cursor=` | 200, `{items, nextCursor}`; `limit` 1–100, default 20; newest first; `cursor` is the last delivery id |
| `GET` | `/deliveries/:id` | 200, with `attempts` |
| `POST` | `/deliveries/:id/retry` | 200 |
| `GET` | `/health` | 200 when Postgres and Redis respond, otherwise 503 |

Request DTOs use `class-validator` through a global `ValidationPipe`
(`whitelist`, `forbidNonWhitelisted`, `transform`). `:id` is parsed as a
UUID (`ParseUUIDPipe` with status 422), so a malformed id returns
`validation-failed`, not 404.

### 5.1 Error mapping

| Cause | Status | `type` suffix |
|---|---|---|
| DTO validation or a malformed `:id` | 422 | `validation-failed` (with `errors: [{field, message}]`) |
| `InvalidTargetUrl` | 422 | `invalid-target-url` |
| `UnknownEventType` | 422 | `unknown-event-type` |
| `SubscriptionNotFound`, `DeliveryNotFound` | 404 | `subscription-not-found`, `delivery-not-found` |
| `DeliveryNotRetryable` | 409 | `delivery-not-retryable` |
| Anything else | 500 | `internal-error`, with a generic `detail` |

## 6. Application flows

### 6.1 Ingest (role `consumer`)

`RedisStreamConsumer`:

1. On start, `XGROUP CREATE ledger_events webhook-service $ MKSTREAM`,
   ignoring `BUSYGROUP`. A new group starts at `$`: history from before the
   group existed is not replayed.
2. Drain its own pending entries first: `XREADGROUP GROUP webhook-service
   <CONSUMER_NAME> COUNT 50 STREAMS ledger_events 0` until empty. This
   resumes messages read but not acknowledged before a crash.
3. Loop: `XREADGROUP … COUNT 50 BLOCK 5000 STREAMS ledger_events >`.
4. For each entry, `MessengerEnvelopeDecoder` reads the `message` field,
   parses `{body, headers}` and parses `body` as JSON. `LedgerEvent.parse`
   validates it.
5. `IngestLedgerEvent.execute(event)`: in one transaction, load active
   subscriptions whose `eventTypes` contain `event.eventName`, and insert one
   `Delivery` each with `ON CONFLICT (subscription_id, event_id) DO NOTHING`.
6. `XACK` the entry after step 5 commits.

A decode or parse failure is logged at `error` level with the stream entry
id and acknowledged, so a poison message cannot block the stream. A
database error is not acknowledged: the loop logs, waits 1s and continues,
and the entry is picked up again from the pending list on the next start.
Shutdown (`SIGTERM`) finishes the current batch and stops.

### 6.2 Dispatch (role `dispatcher`)

`DispatcherLoop` calls `DispatchDueDeliveries.execute()` every
`DISPATCH_INTERVAL_MS` (default 1000), and again immediately when the
previous run claimed a full batch.

1. **Claim**, in a short transaction: select up to 20 deliveries where
   `status = 'pending' AND next_attempt_at <= now()` and whose subscription
   is `active`, ordered by `next_attempt_at, id`, `FOR UPDATE OF d SKIP
   LOCKED`. Set `next_attempt_at = now + LEASE_MS` (60000) on each. Commit.
2. **Send**, outside any transaction and concurrently (up to 20 in flight):
   sign and POST the body with `FetchHttpSender`.
3. **Record** each outcome through the aggregate (`recordSuccess` /
   `recordFailure`) and save it with its new attempt.

If the process dies between 1 and 3, the lease expires and the delivery is
claimed again. The subscriber may see the same `webhook-id` twice, which is
why Standard Webhooks tells receivers to deduplicate on it.

Deliveries of a paused subscription stay `pending` and are not claimed.
When the subscription resumes, any that are due go out on the next tick.

### 6.3 The outgoing request

```
POST <subscription url>
content-type: application/json
user-agent: ledger-platform-webhooks/1
webhook-id: <eventId>
webhook-timestamp: <unix seconds at send time>
webhook-signature: v1,<base64(HMAC-SHA256(key, "<webhook-id>.<webhook-timestamp>.<body>"))>

<envelope JSON>
```

The HMAC key is the base64-decoded part of the secret after `whsec_`, as
Standard Webhooks specifies. `StandardWebhooksSigner` is tested against the
specification's published test vector.

## 7. Persistence

Prisma schema, `webhooks` database:

- `subscriptions`: `id uuid pk`, `url text`, `event_types text[]`,
  `secret text`, `status text`, `description text null`,
  `created_at timestamptz`.
- `deliveries`: `id uuid pk`, `subscription_id uuid fk`, `event_id uuid`,
  `event_name text`, `body jsonb`, `status text`, `attempt_count int`,
  `next_attempt_at timestamptz`, `last_error text null`,
  `created_at timestamptz`; `unique (subscription_id, event_id)`;
  index `(status, next_attempt_at)`; index `(subscription_id, id desc)`.
- `delivery_attempts`: `id uuid pk`, `delivery_id uuid fk`, `number int`,
  `at timestamptz`, `status_code int null`, `error text null`,
  `duration_ms int`; index `(delivery_id, number)`.

The claim query (§6.2) and the idempotent insert (§6.1) are raw SQL via
`$queryRaw` / `$executeRaw`, because Prisma has no `SKIP LOCKED` or
`ON CONFLICT DO NOTHING` for this shape. Everything else uses the Prisma
client. Schema changes come only from `prisma migrate dev` (committed
migrations) and are applied with `prisma migrate deploy`.

## 8. Cross-service contract

### 8.1 Stream format

The consumer relies on exactly what Symfony Messenger's Redis transport
writes: `XADD ledger_events * message <json>`, where `<json>` is
`{"body": "<envelope JSON string>", "headers": {...}}`.

That holds only if `ledger-service` (its Task 11) configures:

1. **`serializer=0`** on the `ledger_events` transport DSN or options. The
   default, `\Redis::SERIALIZER_PHP`, wraps the whole field in PHP
   serialization, which Node cannot read.
2. **A JSON Messenger serializer** for `IntegrationEvent`
   (`messenger.transport.symfony_serializer` with the `json` format), with
   `IntegrationEvent`'s properties being exactly the envelope fields, so
   `body` is the envelope.

`MessengerEnvelopeDecoder` is the only class that knows this framing.

### 8.2 Event schemas

`contracts/events/ledger.<name>.v1.schema.json` (ledger spec §7.3) is the
shared definition. If ledger Task 11 has not landed when this work starts,
this project adds the four schema files as written in the ledger plan.
`webhook-service` tests validate their envelope fixtures against them with
`ajv`, so a contract change breaks the tests on both sides.

## 9. Configuration

Validated at startup with zod; the process exits with a readable error on
invalid config.

| Variable | Default | Meaning |
|---|---|---|
| `PORT` | `3000` | HTTP port |
| `DATABASE_URL` | required | `postgresql://…/webhooks` |
| `REDIS_URL` | required | `redis://redis:6379` |
| `LEDGER_STREAM` | `ledger_events` | stream key |
| `CONSUMER_GROUP` | `webhook-service` | |
| `CONSUMER_NAME` | hostname | unique per replica |
| `ROLES` | `api,consumer,dispatcher` | which loops this process runs |
| `ALLOW_INSECURE_URLS` | `false` | allow `http://` targets (`true` in compose dev) |
| `DELIVERY_TIMEOUT_MS` | `10000` | per-attempt timeout |
| `DISPATCH_INTERVAL_MS` | `1000` | dispatcher tick |
| `LEASE_MS` | `60000` | claim lease |
| `LOG_LEVEL` | `info` | |

Logs are structured JSON on stdout (`nestjs-pino`), ready for Loki.

## 10. Infrastructure

### 10.1 Packages

Runtime: `@nestjs/common`, `@nestjs/core`, `@nestjs/platform-express`,
`@nestjs/terminus`, `@prisma/client`, `ioredis`, `class-validator`,
`class-transformer`, `zod`, `nestjs-pino`, `pino-http`, `uuid` (v7).
Dev: `prisma`, `@nestjs/cli`, `@nestjs/testing`, `typescript`, `jest`,
`ts-jest`, `supertest`, `@testcontainers/postgresql`, `@testcontainers/redis`
(or `testcontainers` generic), `ajv`, `ajv-formats`, `eslint`,
`typescript-eslint`, `prettier`. Versions are the current majors at
implementation time, pinned by `package-lock.json`. HTTP delivery uses
Node's built-in `fetch` with `AbortSignal.timeout`.

### 10.2 Container image (`services/webhook-service/Dockerfile`)

Multi-stage on `node:24-alpine`:

| Stage | Purpose |
|---|---|
| `deps` | `npm ci`, cached on `package*.json` |
| `dev` | from `deps`; source bind-mounted; `npx prisma generate && npm run start:dev` |
| `build` | `prisma generate`, `nest build` |
| `prod-deps` | `npm ci --omit=dev`, `prisma generate` |
| `runtime` | non-root `node` user; `dist/`, production `node_modules`, `prisma/`; `EXPOSE 3000`; `HEALTHCHECK` with `node -e "fetch('http://localhost:3000/health')…"`; `CMD ["node", "dist/main.js"]` |

Migrations never run on container start.

### 10.3 Compose (repo-root `docker-compose.yaml`)

`webhook-service`: build target `dev`, `profiles: ["webhook-service"]`,
ports `8082:3000`, `DATABASE_URL=postgresql://ledger:ledger@postgres:5432/webhooks`,
`REDIS_URL=redis://redis:6379`, `ALLOW_INSECURE_URLS=true`, depends on
healthy postgres and redis, source mounted with an anonymous volume for
`node_modules`.

### 10.4 Root Makefile

- `webhook-migrate`: `CREATE DATABASE webhooks` if it is missing (through
  the postgres container), then `npx prisma migrate deploy` in the service
  container.
- `webhook-test`: unit, integration and e2e tests in the container.
- `webhook-sh`: a shell in the container.

`make up` and `make up-webhook-service` pick the service up automatically
once its Dockerfile exists.

## 11. Testing strategy

Test-first for every unit: write the failing test, watch it fail, then
implement.

| Level | Tooling | Covers |
|---|---|---|
| Unit | Jest | `TargetUrl`, `EventType`, `SigningSecret`, `Subscription`, `Delivery`, `RetryPolicy`, `LedgerEvent.parse`, `MessengerEnvelopeDecoder`, `StandardWebhooksSigner` (spec test vector); every use case against in-memory repositories, a fake clock and a fake sender |
| Integration | Jest + Testcontainers (Postgres 16, Redis 7) | Prisma repositories and mappers; idempotent delivery insert; two concurrent claims never return the same delivery; lease expiry makes a delivery claimable again; `RedisStreamConsumer` creates the group, drains pending entries after a simulated crash, acks poison messages |
| E2E | Jest + supertest + Testcontainers | every endpoint and every §5.1 error; the full flow: `XADD` a Messenger-framed envelope → consumer → dispatcher → a local HTTP receiver that verifies the signature; a failing receiver gets its next attempt scheduled per `RetryPolicy`; the same `XADD` twice produces one delivery |
| Contract | ajv | envelope fixtures used in the tests validate against `contracts/events/*.schema.json` |

Time-dependent tests use the `Clock` port with a fake, never real sleeps,
except the lease test, which uses a short `LEASE_MS`.

## 12. Quality gates

From `services/webhook-service`, before each commit:

- `npm run lint` (ESLint including the layer rule, Prettier check)
- `npm run typecheck` (`tsc --noEmit`)
- `npm test` (unit)
- `npm run test:e2e` (integration and e2e, needs Docker)

Commit messages contain no AI attribution. A GitHub Actions workflow is out
of scope for stage 1 and is added alongside the ledger CI (ledger Task 14).

## 13. Out of scope (roadmap)

- Authentication for the admin API
- Encrypting signing secrets at rest; secret rotation with overlapping signatures
- Deleting subscriptions (pause covers the need)
- Per-subscriber rate limits and circuit breaking
- Auto-disabling a subscriber that returns 410 Gone
- Replaying events from before a subscription existed
- Shipping logs to Loki (needs a collector such as Grafana Alloy)

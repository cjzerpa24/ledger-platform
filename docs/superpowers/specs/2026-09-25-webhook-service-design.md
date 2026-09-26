# Webhook Service — Stage 1 Design

- **Date:** 2026-09-25
- **Status:** Approved in design review, pending spec review
- **Scope:** `services/webhooks-service`, its wiring in the repo-root `docker-compose.yaml` and `Makefile`, the shared `contracts/events/` schemas, and two configuration changes in `ledger-service` (§8)

## 1. Purpose and success criteria

`webhook-service` delivers ledger events to external subscribers. A client
registers an HTTPS URL and the event types it wants. The service consumes
the events that `ledger-service` publishes to Redis, and POSTs each matching
event to each subscriber, signed, retried with backoff, and recorded in a
delivery log.

Stage 1 is done when:

1. `make up-webhooks-service` starts the service with Postgres and Redis, and
   `make webhook-migrate` prepares its database.
2. A subscription created through the API receives a correctly signed POST
   for every matching ledger event, including events published while the
   service was down.
3. A failing subscriber is retried on the §4.4 schedule and ends `dead`
   after 8 failed attempts. A `dead` delivery can be retried by hand.
4. Duplicate stream messages never produce duplicate deliveries.
5. `npm run lint`, `npm run typecheck`, `npm test` and `npm run test:e2e`
   pass (`make webhook-test`), the last one against the real compose
   Postgres (`webhooks_test` database) and Redis (logical db 15).

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
| W11 | SSRF | Subscriber URLs may not reach private, loopback, link-local or reserved addresses. Checked on registration (literal IPs, `localhost`) and enforced at connect time by a DNS `lookup` guard. `ALLOW_PRIVATE_TARGETS=true` lifts it for local development only. |

## 3. Architecture

Ports and adapters inside one bounded context, `webhooks`. The layers
depend inwards only: `infrastructure` → `application` → `domain`. A Jest
architecture test (`test/unit/architecture.spec.ts`) enforces it: `domain`
imports nothing from Nest, Prisma, ioredis or the other layers, and
`application` imports nothing from `infrastructure`.

```
services/webhooks-service/
  Dockerfile, .dockerignore, package.json, tsconfig*.json, .oxlintrc.json, .prettierrc, jest.config.js, jest.e2e.config.js, prisma.config.ts
  prisma/schema.prisma, prisma/migrations/
  src/
    main.ts                      # bootstraps the roles listed in ROLES
    app.module.ts
    shared/
      config/                    # zod env schema, typed AppConfig
    webhooks/
      domain/
        subscription/            # Subscription, TargetUrl, EventType, SigningSecret, SubscriptionRepository, errors
        delivery/                # Delivery, DeliveryAttempt, DeliveryStatus, RetryPolicy, DeliveryRepository, errors
        event/                   # LedgerEvent
      application/
        ports/                   # Clock, HttpSender, Signer, SecretGenerator
        register-subscription.ts, pause-subscription.ts, resume-subscription.ts,
        get-subscription.ts, list-subscriptions.ts,
        ingest-ledger-event.ts, dispatch-due-deliveries.ts,
        list-deliveries.ts, get-delivery.ts, retry-delivery.ts
      infrastructure/
        http/                    # Subscriptions/Deliveries/Health controllers, DTOs, presenters, ProblemDetailsFilter
        persistence/             # PrismaService, Prisma repositories, mappers
        messaging/               # RedisClient, RedisStreamConsumer, MessengerEnvelopeDecoder, StreamConsumerRunner
        delivery/                # NodeHttpSender, StandardWebhooksSigner, DispatcherLoop
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
| `url` | `TargetUrl` | absolute URL, `https` only; `http` allowed when `ALLOW_INSECURE_URLS=true`; max 2048 chars; no credentials in the URL; unless `ALLOW_PRIVATE_TARGETS=true`, the host may not be `localhost`/`*.localhost` or an IP literal in a blocked range (§6.4) |
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
2. Take over entries left pending by any consumer (a crashed replica, or
   this one under a previous hostname): `XAUTOCLAIM ledger_events
   webhook-service <CONSUMER_NAME> <CLAIM_IDLE_MS> 0-0 COUNT 50`, following
   the returned start id until it is `0-0`. Claimed entries are processed
   like any other (steps 4-6). Ids in the reply's deleted list were trimmed
   from the stream (`stream_max_entries`): they are `XACK`ed with a warning.
3. Drain its own pending entries: `XREADGROUP GROUP webhook-service
   <CONSUMER_NAME> COUNT 50 STREAMS ledger_events 0` until empty. Together
   with step 2 this resumes messages read but not acknowledged before a
   crash.
   Loop: `XREADGROUP … COUNT 50 BLOCK 5000 STREAMS ledger_events >`,
   repeating step 2 at most every 30s so a live replica picks up a dead
   one's entries without a restart.
4. For each entry, `MessengerEnvelopeDecoder` reads the `message` field,
   parses `{body, headers}` and parses `body` as JSON. `LedgerEvent.parse`
   validates it.
5. `IngestLedgerEvent.execute(event)`: load active subscriptions whose
   `eventTypes` contain `event.eventName`, and insert one `Delivery` each in
   a single multi-row `INSERT … ON CONFLICT (subscription_id, event_id) DO
   NOTHING`. One statement is atomic, so no explicit transaction is needed.
6. `XACK` the entry after step 5 commits.

A decode or parse failure is logged at `error` level with the stream entry
id and acknowledged, so a poison message cannot block the stream. A
database error is not acknowledged: the loop logs, waits 1s and re-reads
its pending list (`0`) before reading new entries again, so the entry is
retried until the database is back.
Shutdown (`SIGTERM`) finishes the current batch and stops.

### 6.2 Dispatch (role `dispatcher`)

`DispatcherLoop` calls `DispatchDueDeliveries.execute()` every
`DISPATCH_INTERVAL_MS` (default 1000), and again immediately when the
previous run claimed a full batch.

1. **Claim**, in one statement: `UPDATE deliveries SET next_attempt_at =
   now + LEASE_MS WHERE id IN (SELECT … WHERE status = 'pending' AND
   next_attempt_at <= now AND subscription is active ORDER BY
   next_attempt_at, id LIMIT 20 FOR UPDATE OF d SKIP LOCKED) RETURNING id`.
2. **Send**, outside any transaction and concurrently (up to 20 in flight):
   sign and POST the body with `NodeHttpSender`.
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

### 6.4 Outbound address policy (SSRF)

`NodeHttpSender` sends with `node:http` / `node:https` (not `fetch`), so it
can pass a custom `lookup` to the socket. Unless `ALLOW_PRIVATE_TARGETS` is
`true`:

- a host that is an IP literal is checked before connecting;
- any other host is resolved with `dns.lookup({ all: true })` inside the
  socket's `lookup` hook, and the attempt fails if **any** resolved address
  is blocked. Checking at connect time, not only on registration, defeats
  DNS rebinding.

Blocked ranges: IPv4 `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10`,
`127.0.0.0/8`, `169.254.0.0/16`, `172.16.0.0/12`, `192.168.0.0/16`,
`224.0.0.0/4`, `240.0.0.0/4`; IPv6 `::`, `::1`, `fc00::/7`, `fe80::/10`,
`ff00::/8`, and IPv4-mapped addresses (`::ffff:a.b.c.d`) by their IPv4
value. A blocked attempt is recorded as a normal failure
(`error: "<host> resolves to a blocked address (…)"`) and retried per
policy; the subscription owner sees why in the delivery log. Redirects are
never followed (§4.4), so a public URL cannot bounce into the network.

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

The claim query (§6.2) is raw SQL via `$queryRaw`, because Prisma has no
`SKIP LOCKED`. The idempotent insert (§6.1) is `createMany({ skipDuplicates:
true })`, which Prisma renders as `ON CONFLICT DO NOTHING`. Everything else
uses the Prisma client. Schema changes come only from `prisma migrate dev` (committed
migrations) and are applied with `prisma migrate deploy`.

## 8. Cross-service contract

### 8.1 Stream format

The consumer relies on exactly what Symfony Messenger's Redis transport
writes: `XADD ledger_events * message <json>`, where `<json>` is
`{"body": "<envelope JSON string>", "headers": {...}}`.

That holds only if `ledger-service` configures:

1. **`serializer=0`** on the `ledger_events` transport, as the DSN query
   `redis://redis:6379/ledger_events?serializer=0`. The default,
   `\Redis::SERIALIZER_PHP`, wraps the whole field in PHP serialization,
   which Node cannot read. This project changes the DSN in the ledger
   `.env` and in `docker-compose.yaml`.
2. **A JSON Messenger serializer** for `IntegrationEvent`. The ledger
   `messenger.yaml` already sets `serializer:
   messenger.transport.symfony_serializer` (JSON). `IntegrationEvent`'s
   properties must be exactly the envelope fields (ledger Task 11), so
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
| `CLAIM_IDLE_MS` | `60000` | pending entries idle this long under any consumer are taken over (§6.1) |
| `ROLES` | `api,consumer,dispatcher` | which loops this process runs |
| `ALLOW_INSECURE_URLS` | `false` | allow `http://` targets (`true` in compose dev) |
| `ALLOW_PRIVATE_TARGETS` | `false` | lift the §6.4 address policy (`true` in compose dev and tests only) |
| `DELIVERY_TIMEOUT_MS` | `10000` | per-attempt timeout |
| `DISPATCH_INTERVAL_MS` | `1000` | dispatcher tick |
| `LEASE_MS` | `60000` | claim lease |
| `LOG_LEVEL` | `info` | |

Logs are structured JSON on stdout (`nestjs-pino`), ready for Loki.

## 10. Infrastructure

### 10.1 Packages

Runtime: `@nestjs/common`, `@nestjs/core`, `@nestjs/platform-express`
(12.1), `@prisma/client` and `@prisma/adapter-pg` (7.10), `pg`, `ioredis`
(5.10), `class-validator`, `class-transformer`, `zod` (4), `nestjs-pino`,
`pino`, `pino-http`, `uuid` (11, for v7 ids), `reflect-metadata`, `rxjs`.
Dev: `prisma` (7.10), `@nestjs/cli`, `@nestjs/testing`, `typescript` (6.0;
ts-jest does not support 7 yet), `jest` (30), `ts-jest`, `supertest`,
`ajv`, `ajv-formats`, `oxlint`, `prettier`. The package stays CommonJS;
the Prisma generator uses `moduleFormat = "cjs"`. NestJS 12 ships as ESM
and Prisma's runtime uses dynamic `import()`, so Jest runs with
`node --experimental-vm-modules`.
HTTP delivery uses `node:http` / `node:https` with `AbortSignal.timeout`
and the §6.4 `lookup` guard.
`/health` is a plain controller (`SELECT 1` and `PING`, 1s timeout each),
so `@nestjs/terminus` is not needed.

### 10.2 Container image (`services/webhooks-service/Dockerfile`)

Multi-stage on `node:24-alpine`:

| Stage | Purpose |
|---|---|
| `deps` | `npm ci`, cached on `package*.json` |
| `dev` | from `deps`; source bind-mounted; `npx prisma generate && npm run start:dev` |
| `build` | `prisma generate`, `nest build` |
| `prod-deps` | `npm ci --omit=dev`, `prisma generate` |
| `runtime` | non-root `node` user; `dist/` (including the compiled Prisma client) and production `node_modules`; `EXPOSE 3000`; `HEALTHCHECK` with `node -e "fetch('http://localhost:3000/health')…"`; `CMD ["node", "dist/main.js"]` |

Migrations never run on container start.

### 10.3 Compose (repo-root `docker-compose.yaml`)

`webhooks-service`: build target `dev`, `profiles: ["webhooks-service"]`,
ports `8082:3000`, `DATABASE_URL=postgresql://ledger:ledger@postgres:5432/webhooks`,
`REDIS_URL=redis://redis:6379`, `ALLOW_INSECURE_URLS=true`, `ALLOW_PRIVATE_TARGETS=true`,
`TEST_DATABASE_URL=…/webhooks_test`, `TEST_REDIS_URL=redis://redis:6379/15`,
`CONTRACTS_DIR=/contracts` (the repo `contracts/` mounted read-only),
depends on healthy postgres and redis, source mounted with an anonymous
volume for `node_modules`.

### 10.4 Root Makefile

- `webhook-db`: `CREATE DATABASE` for `webhooks` and `webhooks_test` if
  they are missing (through the postgres container).
- `webhook-migrate`: `npx prisma migrate deploy` against both databases.
- `webhook-test`: lint, typecheck, unit, integration and e2e tests in the
  container.
- `webhook-sh`: a shell in the container.

`make up` and `make up-webhooks-service` pick the service up automatically
once its Dockerfile exists.

## 11. Testing strategy

Test-first for every unit: write the failing test, watch it fail, then
implement.

| Level | Tooling | Covers |
|---|---|---|
| Unit | Jest | `TargetUrl`, `EventType`, `SigningSecret`, `Subscription`, `Delivery`, `RetryPolicy`, `LedgerEvent.parse`, `MessengerEnvelopeDecoder`, `StandardWebhooksSigner` (spec test vector); every use case against in-memory repositories, a fake clock and a fake sender |
| Integration | Jest against the compose Postgres (`webhooks_test`) and Redis (db 15) | Prisma repositories and mappers; idempotent delivery insert; two concurrent claims never return the same delivery; lease expiry makes a delivery claimable again; `RedisStreamConsumer` creates the group, drains pending entries after a simulated crash, acks poison messages |
| E2E | Jest + supertest against the same compose services | every endpoint and every §5.1 error; the full flow: `XADD` a Messenger-framed envelope → consumer → dispatcher → a local HTTP receiver that verifies the signature; a failing receiver gets its next attempt scheduled per `RetryPolicy`; the same `XADD` twice produces one delivery |
| Contract | ajv | envelope fixtures used in the tests validate against `contracts/events/*.schema.json` |

Time-dependent tests use the `Clock` port with a fake, never real sleeps,
except the lease test, which uses a short `LEASE_MS`.

## 12. Quality gates

From `services/webhooks-service`, before each commit:

- `npm run lint` (oxlint with type-aware rules, Prettier check)
- `npm run typecheck` (`tsc --noEmit`)
- `npm test` (unit)
- `npm run test:e2e` (integration and e2e, needs the compose Postgres and Redis)

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

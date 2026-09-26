# webhooks-service

Delivers ledger events to subscribers as signed webhooks.

A client registers a URL and the event types it wants. The service reads the
events `ledger-service` publishes to the `ledger_events` Redis stream and POSTs
each matching event to each subscriber. Every request is signed with the
[Standard Webhooks](https://www.standardwebhooks.com/) scheme, failures are
retried with backoff, and every attempt is kept in a delivery log.

Design: [`docs/superpowers/specs/2026-09-25-webhook-service-design.md`](../../docs/superpowers/specs/2026-09-25-webhook-service-design.md).

## Run it

From the repo root:

```bash
make up-webhooks-service   # postgres, redis, loki, grafana + this service on :8082
make webhook-migrate       # create and migrate the webhooks and webhooks_test databases
make webhook-test          # lint, typecheck, unit, integration and e2e tests
```

## API

| Method | Path | |
|---|---|---|
| `POST` | `/subscriptions` | `{url, eventTypes, description?}` → 201, includes `secret` (shown only here) |
| `GET` | `/subscriptions` | list |
| `GET` | `/subscriptions/:id` | one subscription |
| `POST` | `/subscriptions/:id/pause` · `/resume` | stop or restart deliveries |
| `GET` | `/subscriptions/:id/deliveries?status=&limit=&cursor=` | delivery log, newest first |
| `GET` | `/deliveries/:id` | one delivery with every attempt |
| `POST` | `/deliveries/:id/retry` | send a `dead` delivery again |
| `GET` | `/health` | Postgres and Redis checks |

Errors are `application/problem+json` with `type: urn:webhooks:problem:<name>`.

```bash
curl -s -XPOST localhost:8082/subscriptions -H 'content-type: application/json' \
  -d '{"url":"https://example.com/hooks/ledger","eventTypes":["ledger.transfer_completed"]}'
```

Event types: `ledger.account_opened`, `ledger.transfer_completed`,
`ledger.deposit_recorded`, `ledger.withdrawal_recorded`. Schemas live in
[`contracts/events`](../../contracts/events).

## What a subscriber receives

```
POST <your url>
content-type: application/json
webhook-id: <eventId>                 # stable across retries: deduplicate on it
webhook-timestamp: <unix seconds>
webhook-signature: v1,<base64 HMAC-SHA256(key, "{id}.{timestamp}.{body}")>

{"eventId": "...", "eventName": "ledger.transfer_completed", "eventVersion": 1,
 "occurredAt": "...", "aggregateId": "...", "payload": {...}}
```

`key` is the base64-decoded part of the secret after `whsec_`. Any Standard
Webhooks library verifies it. Verify the signature over the raw body you
received, not a re-serialised copy, because key order is not guaranteed.

A 2xx answer is success. Anything else, a redirect, a timeout (10s) or a
network error is a failure, retried after 10s, 30s, 2m, 10m, 30m, 1h and 3h.
After 8 failed attempts the delivery is `dead` until retried through the API.

## How it works

```
ledger outbox relay ──XADD──▶ ledger_events (Redis stream)
                                   │ XREADGROUP (group webhook-service)
                                   ▼
                       IngestLedgerEvent ──▶ deliveries (one per matching subscription,
                                   │          unique per subscription + event)
                                 XACK
DispatcherLoop (every 1s) ──▶ claim due rows (FOR UPDATE SKIP LOCKED + 60s lease)
                          ──▶ sign + POST ──▶ record attempt, schedule retry or finish
```

- **At-least-once in, deduplicated:** an entry is acknowledged only after its
  deliveries are stored; a replay hits the unique key and is a no-op.
- **Nothing stranded:** entries left pending by a crashed or renamed consumer
  are taken over (`XAUTOCLAIM`) once idle for `CLAIM_IDLE_MS`, on startup and
  every 30s; entries trimmed from the stream meanwhile are acknowledged.
- **Safe to scale:** several dispatchers never claim the same delivery.
- **SSRF guard:** URLs pointing at private, loopback, link-local or reserved
  addresses are rejected on registration and again at connect time (DNS lookup
  hook), so DNS rebinding does not get around it. `ALLOW_PRIVATE_TARGETS=true`
  (compose dev only) lifts it.
- **Roles:** `ROLES=api,consumer,dispatcher` (default) runs everything in one
  process; split them across containers without code changes.

## Layout

```
src/webhooks/
  domain/          entities, value objects, repository ports (no framework imports)
  application/     use cases and ports (Clock, HttpSender, Signer, SecretGenerator)
  infrastructure/  HTTP controllers, Prisma repositories, Redis consumer, HTTP sender
test/
  unit/            domain + use cases with in-memory fakes, layer rules
  integration/     repositories, stream consumer, HTTP sender (compose Postgres/Redis)
  e2e/             HTTP API, stream → signed webhook flow, event contracts
```

## Configuration

| Variable | Default | |
|---|---|---|
| `DATABASE_URL` | required | `postgresql://…/webhooks` |
| `REDIS_URL` | required | `redis://redis:6379` |
| `LEDGER_STREAM` | `ledger_events` | |
| `CONSUMER_GROUP` / `CONSUMER_NAME` | `webhook-service` / hostname | |
| `CLAIM_IDLE_MS` | `60000` | take over entries pending this long under any consumer |
| `ROLES` | `api,consumer,dispatcher` | |
| `ALLOW_INSECURE_URLS` | `false` | allow `http://` targets |
| `ALLOW_PRIVATE_TARGETS` | `false` | allow private addresses (dev only) |
| `DELIVERY_TIMEOUT_MS` · `DISPATCH_INTERVAL_MS` · `LEASE_MS` | `10000` · `1000` · `60000` | |
| `LOG_LEVEL` | `info` | JSON logs on stdout |

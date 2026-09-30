# 0003. Events leave through a transactional outbox

## Status

Accepted, 2026-09-24

## Context

Other services (webhooks-service today, reconciliation later) need to know
when accounts open and money moves. Publishing to Redis directly from the
request would either lose events (publish after commit, then crash) or
announce money that never moved (publish before commit, then roll back).

## Decision

- Domain events are written as rows in `outbox_messages` in the **same
  database transaction** as the postings, so an event exists if and only if
  its money movement committed.
- `ledger:outbox:relay` (the `ledger-outbox-relay` compose service) takes a
  `LockFactory` lock so only one relay runs, then repeatedly selects
  unpublished rows in `(occurred_at, id)` order with `FOR UPDATE SKIP LOCKED`,
  dispatches each as an `IntegrationEvent` to the `ledger_events` Redis
  stream, and marks it published. A failed dispatch increments `attempts`,
  stores `last_error` and stays unpublished.
- Delivery is **at least once**: a crash between dispatch and commit
  republishes the event. Every envelope carries `eventId` (the outbox row id)
  for consumers to deduplicate on. The envelopes are described by
  `contracts/events/*.v1.schema.json`.

## Consequences

- Events are never lost and never describe rolled-back work.
- Consumers must be idempotent; webhooks-service deduplicates on
  `(subscription, eventId)`.
- Events arrive with a delay of up to one relay poll.
- The lock store is `flock`, which only works on one host. A multi-host
  deployment must switch `LOCK_DSN` to a Redis or PostgreSQL store.

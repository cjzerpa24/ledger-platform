# 0002. Cached balances under pessimistic row locks

## Status

Accepted, 2026-09-24

## Context

Postings are the source of truth (ADR 0001), but most reads only need an
account's current balance, and a withdrawal or transfer must check it before
posting. Two concurrent transfers from the same account must not both see
the old balance and overdraw it, and two opposite-direction transfers between
the same pair of accounts must not deadlock.

## Decision

- `accounts.balance_minor` is a cache of the net of the account's postings,
  updated in the same database transaction as the postings.
- Posting a transfer locks both accounts with `SELECT … FOR UPDATE` before
  reading their balances, always in ascending UUID order, whichever account
  is the source. A fixed lock order means two transfers can never each hold
  the lock the other one needs.
- `ledger:verify-balances` is the safety net: it compares each cached balance
  with the sum of its postings and checks that each currency sums to zero,
  exiting 1 on any mismatch.

Rejected alternatives:

- **Sum postings on every read.** Always correct, but every balance check and
  every statement gets slower as history grows, and the overdraft check still
  needs a lock to be race-free.
- **Event sourcing.** It makes postings the only store, like this design, but
  adds snapshots, projections and replay tooling that a stage 1 ledger does
  not need.

## Consequences

- Balance reads are one row lookup.
- Transfers on the same account serialize; throughput per hot account is
  bounded by that lock. Internal transfers between different accounts run
  in parallel.
- Every deposit and withdrawal also locks its currency's single settlement
  account, so all deposits and withdrawals in one currency serialize on one
  row. That is the main throughput limit of this design. Stage 2 options
  include several settlement accounts per currency or batching postings to
  it.
- `tests/Concurrency` proves both properties with real parallel processes:
  five overlapping transfers let exactly the affordable ones through, and
  opposite-direction transfers never deadlock.
- A cache that drifts is detectable (`make verify`) and repairable from the
  postings.

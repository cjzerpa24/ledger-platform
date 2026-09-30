# 0005. Money is integer minor units, amounts are JSON strings

## Status

Accepted, 2026-09-24

## Context

Binary floating point cannot represent most decimal amounts exactly
(`0.1 + 0.2 != 0.3`), and JSON numbers are parsed as doubles by most
clients. A ledger that rounds anywhere eventually fails to balance.

## Decision

- Amounts are stored as `bigint` minor units (cents, or whole yen for JPY)
  next to a three-letter currency code.
- Supported currencies and their decimals are fixed: USD, EUR, GBP and TZS
  with 2, JPY with 0.
- In JSON, amounts are decimal **strings** (`"125.50"`, `"1200"`), never
  numbers. Input must match `^\d+(\.\d+)?$` and have at most the currency's
  decimals; anything else (`10.5` as a number, `"1e3"`, `"-5"`, `"10."`,
  `" 10"`, a 25-digit amount) is a 422, never a 500.

## Consequences

- Arithmetic is exact and balances always sum to zero.
- Clients must send and parse strings; the OpenAPI contract states the
  pattern everywhere an amount appears.
- The largest representable amount is bounded by `bigint`, far above any
  real balance; oversized input is rejected, not truncated.

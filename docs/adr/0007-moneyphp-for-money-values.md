# 0007. MoneyPHP for money values

## Status

Accepted, 2026-09-24

## Context

Integer minor units (ADR 0005) still need a value type in the domain:
something that carries its currency, refuses to add USD to EUR, and formats
and parses decimals per currency. Writing that type by hand is easy to get
subtly wrong.

## Decision

- The domain uses `moneyphp/money` 4 (`Money\Money`, `Money\Currency`).
- Doctrine maps money as two scalar columns (`*_minor` bigint and a
  `currency` string) rather than a custom type or embeddable; entities build
  `Money` in their getters.
- `SupportedCurrencies` is its own MoneyPHP `CurrencyList` of the five
  supported currencies and their decimals, rejecting any other code, and the domain raises `CurrencyMismatch` before any operation
  that mixes currencies, instead of relying on MoneyPHP's generic exception.
- `MoneyParser` and `MoneyFormatter` are static helpers around MoneyPHP's
  decimal parser and formatter.

## Consequences

- Currency safety and exact arithmetic come from a well-tested library.
- The schema stays plain SQL that other tools (reconciliation, reports) can
  read without knowing about MoneyPHP.
- MoneyPHP requires the `bcmath` extension, which the image installs.

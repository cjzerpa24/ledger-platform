# 0001. Double-entry postings are the source of truth

## Status

Accepted, 2026-09-24

## Context

A ledger has to explain every balance it reports, survive audits, and never
lose or invent money. Storing a mutable balance per account and adjusting it
in place makes the balance the only record of what happened: once a bug or a
race corrupts it, there is nothing to rebuild it from.

The domain also needs a word for "a movement of money". "Transaction" is the
obvious choice, but in this codebase it collides with database transactions,
which every write path also talks about.

## Decision

- Every movement of money is a `Transfer` (kind `internal`, `deposit` or
  `withdrawal`). Posting a transfer creates one `JournalEntry` with at least
  two `Posting`s.
- A journal entry must balance: its debits equal its credits, in a single
  currency. `JournalEntry` refuses to be built otherwise
  (`UnbalancedJournalEntry`).
- Postings are append-only. Nothing updates or deletes them; a correction is
  a new, reversing transfer.
- Deposits and withdrawals post against a per-currency `SETTLEMENT` account,
  so money entering or leaving the ledger is double-entry too, and every
  currency's balances sum to zero.
- The domain avoids the word "Transaction" (spec D12).

## Consequences

- Any account's balance can be recomputed from its postings, and
  `ledger:verify-balances` does exactly that (ADR 0002).
- Statements are a read model over postings, not a stored aggregate.
- Storage grows with activity; there is no pruning in stage 1.
- Reversals appear in history instead of silently disappearing, which is what
  auditors and reconciliation want.

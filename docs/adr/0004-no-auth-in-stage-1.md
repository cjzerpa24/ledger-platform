# 0004. No authentication in stage 1

## Status

Accepted, 2026-09-24

## Context

Authentication and authorization are large, security-sensitive features:
identity, credentials, scopes per account, audit trails. Building them well
would dominate stage 1, whose goal is a correct and demonstrable ledger core.

## Decision

- The ledger API has no authentication or authorization in stage 1. Anyone
  who can reach it can open accounts and move money.
- It is meant to run behind an API gateway or on a private network that
  authenticates callers. The READMEs and the OpenAPI contract say so.
- OAuth2 for third-party channel integrations (Telegram, WhatsApp) is planned
  for stage 2.

## Consequences

- Stage 1 must never be exposed to the internet as-is.
- The other services follow the same stance for their admin APIs
  (webhooks-service W9).
- Adding auth later touches the HTTP layer only; the domain and application
  layers take no caller identity today and will need one for per-account
  authorization.

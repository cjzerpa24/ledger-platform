# reconciliation-service

Checks that the bank agrees with the ledger. An operator uploads the bank's
statement for the platform's settlement account (CSV); the service matches
every bank line against the deposits and withdrawals the ledger posted on the
settlement account for that currency and period, stores the result as a run,
and lets the operator resolve what does not line up. It never writes to the
ledger.

Design: [`docs/superpowers/specs/2026-09-26-reconciliation-service-design.md`](../../docs/superpowers/specs/2026-09-26-reconciliation-service-design.md).

## Run it

From the repo root:

```bash
make up-infra && make recon-db                        # postgres + the reconciliation databases
make up-ledger-service                                # the ledger it reads from
make up-reconciliation-service                        # this service on :8083 (Flyway migrates on start)
make recon-test                                       # format check, Checkstyle, unit, architecture and integration tests
```

## Statement format

UTF-8 CSV, header exactly:

```
date,direction,description,reference,currency,amount
2026-03-05,CR,Salary,0199a111-2222-7333-8444-555566667777,USD,100.00
2026-03-08,DR,ATM withdrawal,,USD,20.00
```

- `date` `YYYY-MM-DD`, inside the run's period; `direction` `CR` (money in) or `DR` (money out)
- `description` (≤ 255) and `reference` (≤ 64) are optional; a reference that is a ledger transfer id matches exactly
- `currency` must equal the run's; `amount` is a positive decimal with the currency's decimals
- at most 5 MB and 10,000 rows; any invalid row rejects the whole file with every problem listed

## API

| Method | Path | |
|---|---|---|
| `POST` | `/runs` | multipart `file`, `currency`, `from`, `to` → 201 with the run summary |
| `GET` | `/runs?currency=&status=` | runs, newest first |
| `GET` | `/runs/{id}` | one run |
| `GET` | `/runs/{id}/items?kind=&unresolved=` | the matched items and the exceptions |
| `POST` | `/runs/{id}/items/{itemId}/resolve` | `{"resolution":"IGNORE","note":"…"}` or `{"resolution":"MANUAL_MATCH","counterpartItemId":"…","note":"…"}` |
| `GET` | `/actuator/health` | database and ledger |

```bash
curl -s -F file=@statement.csv -F currency=USD -F from=2026-03-01 -F to=2026-03-31 localhost:8083/runs
```

Errors are `application/problem+json` with `type: urn:reconciliation:problem:<name>`.

## Matching

1. **Reference:** a bank reference equal to a ledger transfer id pairs them — `MATCHED` if amount and direction agree, else `AMOUNT_MISMATCH`.
2. **Fuzzy:** otherwise, same direction and amount within ±`MATCH_WINDOW_DAYS` (default 2) days, and only when the pair is unique on both sides. Otherwise `AMBIGUOUS`, with the candidates listed.
3. **Leftovers:** `MISSING_IN_LEDGER` (bank only) and `MISSING_IN_BANK` (ledger only, inside the period).

Every item except `MATCHED` is an exception until an operator ignores it (with a note) or matches it manually. The run is `RECONCILED` when none are left.

## Layout

```
src/main/java/com/ledgerplatform/reconciliation/
  domain/          money, statement, matching, run (aggregate + repository port) — no framework imports
  application/     use cases and ports (ledger, statement parser, ids)
  infrastructure/  web, csv, ledger (RestClient), persistence (JPA + Flyway), config
```

## Configuration

| Variable | Default | |
|---|---|---|
| `SPRING_DATASOURCE_URL` / `_USERNAME` / `_PASSWORD` | required | the `reconciliation` database |
| `LEDGER_BASE_URL` | `http://ledger-service` | |
| `LEDGER_TIMEOUT_MS` | `5000` | |
| `MATCH_WINDOW_DAYS` | `2` | 0–7 |

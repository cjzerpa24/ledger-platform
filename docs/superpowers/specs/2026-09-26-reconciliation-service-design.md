# Reconciliation Service — Stage 1 Design

- **Date:** 2026-09-26
- **Status:** Approved in design review, pending spec review
- **Scope:** `services/reconciliation-service`, its wiring in the repo-root `docker-compose.yaml` and `Makefile`. No change to `ledger-service` or `webhooks-service`.

## 1. Purpose and success criteria

The platform's money enters and leaves through a bank (or payment provider)
account. Every deposit and withdrawal the ledger records is posted against the
per-currency **settlement account**. `reconciliation-service` checks that the
bank agrees: an operator uploads the bank's statement for that account as a
CSV, the service matches each bank line against the ledger's settlement
postings for the same period, and reports what does not line up. An operator
then resolves each exception through the API. The service never writes to the
ledger.

Stage 1 is done when:

1. `make up-reconciliation-service` starts the service with Postgres, and
   `make recon-db` prepares its databases (Flyway migrates on startup).
2. Uploading a statement for a currency and period produces a stored run in
   which every bank line and every in-period ledger entry appears exactly once,
   classified per §4.4.
3. Exceptions can be resolved (`IGNORE`, `MANUAL_MATCH`); the run becomes
   `RECONCILED` when none are left.
4. Invalid, oversized or duplicate uploads are rejected before anything is
   stored, with problem responses that name every bad row.
5. `./mvnw verify` passes: formatting, Checkstyle, unit, architecture and
   integration tests, the integration tests against the compose Postgres.

## 2. Decisions

| # | Decision | Choice |
|---|---|---|
| R1 | Role | Bank statement ↔ ledger reconciliation for the settlement account of one currency, over one period per run. |
| R2 | Stack | Java 21 (LTS), Spring Boot 4.1.1 (Spring Framework 7), Maven via the wrapper (`mvnw`). The build targets `--release 21`; the local JDK version does not matter because builds and runs happen in Docker. |
| R3 | Ledger data | Pulled on demand: `GET /accounts/{settlementAccountId}/statement?from&to` on ledger-service. No Redis stream, no ledger change. |
| R4 | Bank data | CSV upload, format §5.2. Other formats (MT940, CAMT.053) are out of scope. |
| R5 | Matching | Reference first (bank reference = ledger transfer id), then a unique fuzzy match on amount + direction within a date window. Ambiguity is never guessed. |
| R6 | Outcome | Stored run with items; manual resolution through the API. Nothing is written back to the ledger. |
| R7 | Execution | A run executes synchronously inside `POST /runs`. |
| R8 | Persistence | Spring Data JPA + Flyway on PostgreSQL 16, database `reconciliation` on the shared instance. JPA entities stay in `infrastructure`. |
| R9 | Errors | RFC 9457 via Spring's `ProblemDetail`, `type` = `urn:reconciliation:problem:<kebab-name>`, the same convention as the other services. |
| R10 | Auth | None in stage 1: an internal operator API on the compose network, like the other services. |

## 3. Architecture

Domain-driven, ports and adapters, one bounded context (`reconciliation`).
Dependencies point inwards: `infrastructure` → `application` → `domain`. An
ArchUnit test enforces it: `domain` imports nothing from Spring, Jakarta
Persistence or the other layers; `application` imports nothing from
`infrastructure` and no Spring web or JPA types.

```
services/reconciliation-service/
  Dockerfile, .dockerignore, pom.xml, mvnw, .mvn/, checkstyle.xml
  src/main/java/com/ledgerplatform/reconciliation/
    ReconciliationApplication.java
    domain/
      money/        Money, SupportedCurrency, Direction
      statement/    BankLine, LedgerEntry, Period
      matching/     Matcher, MatchingPolicy (window)
      run/          ReconciliationRun (aggregate root), ReconItem, ItemKind, MatchMethod,
                    RunStatus, Resolution, ResolutionKind, RunRepository (port), domain errors
    application/
      port/         LedgerStatementPort, Clock, IdGenerator
      StartReconciliation, ResolveItem, GetRun, ListRuns, ListItems (+ command/result records)
    infrastructure/
      web/          RunController, request/response records, ProblemDetailsAdvice
      csv/          BankStatementCsvParser, StatementParseException
      ledger/       LedgerHttpClient (RestClient), SettlementAccounts
      persistence/  JPA entities, Spring Data repositories, JpaRunRepository (adapter), mappers
      config/       wiring (@Configuration), properties (@ConfigurationProperties)
  src/main/resources/
    application.yaml, db/migration/V1__init.sql
  src/test/java/...  unit/, architecture/, integration/ (mirrors main)
```

Use cases are plain classes constructed in a `@Configuration` class from their
ports, so unit tests build them with in-memory fakes and no Spring context.

## 4. Domain model

### 4.1 Value objects

Immutable Java `record`s that validate in their canonical constructor.

- **`SupportedCurrency`**: `USD`, `EUR`, `GBP`, `TZS` (2 decimals) and `JPY`
  (0 decimals), exactly the ledger's set.
- **`Money(long minor, SupportedCurrency currency)`**: `Money.parse(String,
  currency)` accepts only `^\d+(\.\d+)?$` with at most the currency's number
  of decimals and a value `> 0` and `≤ Long.MAX_VALUE` minor units. No
  floating point anywhere; `toDecimalString()` formats with the currency's
  scale.
- **`Direction`**: `IN` (money into the bank account) or `OUT`.
- **`Period(LocalDate from, LocalDate to)`**: inclusive, `from ≤ to`, at most
  366 days; `widen(days)` returns a copy extended on both sides.
- **`BankLine(int row, LocalDate date, Direction direction, String
  description, String reference, Money amount)`**: `row` is the 1-based data
  row in the file; `description` and `reference` are nullable.
- **`LedgerEntry(UUID transferId, Instant occurredAt, Direction direction,
  Money amount, String description)`**: built from a settlement statement
  line. A settlement `debit` is a deposit (`IN`); a `credit` is a withdrawal
  (`OUT`). Its date for matching is `occurredAt` in UTC.

### 4.2 Aggregate: `ReconciliationRun`

| Field | Rule |
|---|---|
| `id` | UUID v7 |
| `currency`, `period` | from the request |
| `statementSha256` | hex SHA-256 of the uploaded bytes |
| `status` | `OPEN` or `RECONCILED`; a run with no exceptions starts `RECONCILED` |
| `createdAt` | from `Clock` |
| `items` | `ReconItem`s, in matcher order |

**`ReconItem`**: `id` (UUID v7), `kind`, `matchMethod` (only for
`MATCHED`), `bankLine` (nullable), `ledgerEntry` (nullable), `candidates`
(ledger entries, only for `AMBIGUOUS`), and `resolution` (nullable:
`kind` `IGNORE` or `MANUAL_MATCH`, `note`, `counterpartItemId`, `resolvedAt`).

`MATCHED` items are never exceptions. Every other kind is an exception until it
has a resolution.

Behaviour:

- `ReconciliationRun.start(id, currency, period, sha256, items, now)`.
- `ignore(itemId, note, now)`: the item must be an unresolved exception;
  `note` is required (1–500 chars).
- `manualMatch(bankItemId, ledgerItemId, note, now)`: `bankItemId` must be an
  unresolved `MISSING_IN_LEDGER` or `AMBIGUOUS` item, `ledgerItemId` an
  unresolved `MISSING_IN_BANK` item, with the same direction and currency.
  Amounts may differ only if `note` is given; both items get a `MANUAL_MATCH`
  resolution pointing at each other. `note` is optional (≤ 500 chars) when
  amounts are equal.
- After either operation, if no unresolved exception is left, `status`
  becomes `RECONCILED`.
- Resolving an item that is not an exception or is already resolved raises
  `ItemAlreadyResolved`; an item id not in the run raises `ItemNotFound`;
  a counterpart that breaks the rules above raises `InvalidManualMatch`.

### 4.3 `Matcher` (domain service)

`List<ReconItem> match(List<BankLine> bank, List<LedgerEntry> ledger, Period
period, MatchingPolicy policy)`, a pure function. Inputs are processed in a
fixed order (bank: date, row; ledger: occurredAt, transferId), so the same
inputs always give the same items.

1. **Reference pass.** For each bank line whose `reference` parses as a UUID
   equal (case-insensitive) to an unpaired ledger entry's `transferId`: pair
   them. `MATCHED(REFERENCE)` if amount and direction are equal, otherwise
   `AMOUNT_MISMATCH` (both sides kept). A reference that matches nothing falls
   through to step 2.
2. **Fuzzy pass.** For each remaining bank line, its candidates are unpaired
   ledger entries with the same direction and amount whose date is within
   `policy.windowDays` (default 2) of the bank date. A line and a ledger entry
   are `MATCHED(FUZZY)` only if each is the other's only candidate. A bank line
   with more than one candidate, or whose single candidate is also a candidate
   of another bank line, becomes `AMBIGUOUS` with its candidates listed; those
   ledger entries stay unpaired.
3. **Leftovers.** Unpaired bank lines (including `AMBIGUOUS` ones, already
   emitted) → `MISSING_IN_LEDGER` for the rest. Unpaired ledger entries whose
   date is inside `period` → `MISSING_IN_BANK`; those outside `period` (fetched
   only because of the window) are dropped.

Every bank line appears in exactly one item. Every in-period ledger entry
appears in exactly one item as `ledgerEntry` (it may additionally appear as a
candidate).

### 4.4 Item kinds

| Kind | Bank line | Ledger entry | Exception |
|---|---|---|---|
| `MATCHED` (`REFERENCE` / `FUZZY`) | yes | yes | no |
| `AMOUNT_MISMATCH` | yes | yes | yes |
| `AMBIGUOUS` | yes | no (candidates listed) | yes |
| `MISSING_IN_LEDGER` | yes | no | yes |
| `MISSING_IN_BANK` | no | yes | yes |

## 5. Application flows

### 5.1 Start a run (`StartReconciliation`)

1. Validate `currency` and `period` (`UnsupportedCurrency`, `InvalidPeriod`).
2. Reject a statement whose SHA-256 already has a run for the same currency
   and period (`DuplicateStatement`, carrying the existing run id).
3. Parse the CSV (§5.2). Any error rejects the whole upload
   (`InvalidStatement` with every row error).
4. Fetch ledger entries through `LedgerStatementPort.fetch(currency,
   period.widen(windowDays))`. A failure raises `LedgerUnavailable`.
5. `Matcher.match(...)`, `ReconciliationRun.start(...)`, save, return it.

Nothing is stored unless every step succeeds.

### 5.2 Statement CSV format

- UTF-8; a leading BOM is ignored. RFC 4180 quoting (commas, quotes and line
  breaks inside quoted fields). `\n` or `\r\n` line endings.
- The first line is exactly the header
  `date,direction,description,reference,currency,amount`.
- At least one data row; at most 10,000 data rows; the file at most 5 MB
  (larger → 413 before parsing).
- Blank lines are ignored.

| Column | Rule |
|---|---|
| `date` | `YYYY-MM-DD`, inside the run's period |
| `direction` | `CR` (money in → `IN`) or `DR` (money out → `OUT`) |
| `description` | optional, ≤ 255 chars |
| `reference` | optional, ≤ 64 chars; used for matching when it is a UUID |
| `currency` | equals the run's currency |
| `amount` | positive decimal string, currency scale (§4.1) |

Each error is reported as `{row, field, message}` (`row` 1-based over data
rows, `field` a column name, or `null` for row-level problems such as a wrong
column count). All errors are collected, not just the first.

### 5.3 Resolve (`ResolveItem`)

Loads the run, calls `ignore` or `manualMatch`, saves it, returns the run.
Concurrent resolutions on the same run are serialized with optimistic locking
(a `version` column); the loser gets 409 `concurrent-update`.

### 5.4 Ledger adapter (`LedgerHttpClient`)

- `SettlementAccounts` maps each currency to the settlement account id the
  ledger seeds (`0199a000-0000-7000-8000-000000000<ISO numeric>`: USD `840`,
  EUR `978`, GBP `826`, TZS `834`, JPY `392`).
- `GET {LEDGER_BASE_URL}/accounts/{id}/statement?from=YYYY-MM-DD&to=YYYY-MM-DD`
  with `LEDGER_TIMEOUT_MS` connect and read timeouts.
- The ledger allows at most 366 days per statement; a widened period longer
  than that is fetched in consecutive chunks.
- The response currency must equal the requested one. Each line becomes a
  `LedgerEntry` (`debit` → `IN`, `credit` → `OUT`, amount parsed with
  `Money.parse`).
- Connection errors, timeouts, non-2xx responses and unparseable bodies all
  raise `LedgerUnavailable` with a short reason.

## 6. HTTP API

Port 8080 in the container, 8083 on the host.

| Method | Path | Success |
|---|---|---|
| `POST` | `/runs` | `multipart/form-data`: `file`, `currency`, `from`, `to` → **201** with the run summary (`id`, `currency`, `from`, `to`, `status`, `createdAt`, `counts` per kind, `unresolvedExceptions`) and `Location` |
| `GET` | `/runs?currency=&status=` | 200, newest first |
| `GET` | `/runs/{id}` | 200, run summary |
| `GET` | `/runs/{id}/items?kind=&unresolved=` | 200, items in matcher order with `bankLine`, `ledgerEntry`, `candidates`, `resolution` |
| `POST` | `/runs/{id}/items/{itemId}/resolve` | JSON `{resolution: "IGNORE", note}` or `{resolution: "MANUAL_MATCH", counterpartItemId, note?}` → 200 with the run summary |
| `GET` | `/actuator/health` | database and ledger reachability |

Amounts are decimal strings in responses, never numbers.

### 6.1 Error mapping

| Cause | Status | `type` suffix |
|---|---|---|
| Missing/invalid form or JSON fields, malformed ids | 422 | `validation-failed` (with `errors: [{field, message}]`) |
| `InvalidStatement` | 422 | `invalid-statement` (with `errors: [{row, field, message}]`) |
| `InvalidPeriod` | 422 | `invalid-period` |
| `UnsupportedCurrency` | 422 | `unsupported-currency` |
| `RunNotFound`, `ItemNotFound` | 404 | `run-not-found`, `item-not-found` |
| `ItemAlreadyResolved`, `InvalidManualMatch` | 409 | `item-already-resolved`, `invalid-manual-match` |
| `DuplicateStatement` | 409 | `duplicate-statement` (with `existingRunId`) |
| Optimistic lock failure | 409 | `concurrent-update` |
| Upload over 5 MB | 413 | `statement-too-large` |
| `LedgerUnavailable` | 502 | `ledger-unavailable` |
| Anything else | 500 | `internal-error`, generic `detail` |

## 7. Persistence

Flyway `V1__init.sql`, database `reconciliation`:

- `reconciliation_runs`: `id uuid pk`, `currency text` (3 chars, checked), `period_from date`,
  `period_to date`, `statement_sha256 text` (64 chars, checked), `status text`,
  `created_at timestamptz`, `version bigint`;
  unique `(currency, period_from, period_to, statement_sha256)`;
  index `(created_at desc)`.
- `reconciliation_items`: `id uuid pk`, `run_id uuid fk`, `position int`,
  `kind text`, `match_method text null`, bank line columns
  (`bank_row int`, `bank_date date`, `bank_direction text`,
  `bank_description text`, `bank_reference text`, `bank_amount_minor bigint`,
  all nullable), ledger entry columns (`ledger_transfer_id uuid`,
  `ledger_occurred_at timestamptz`, `ledger_direction text`,
  `ledger_amount_minor bigint`, `ledger_description text`, all nullable),
  `candidates jsonb null`, resolution columns (`resolution_kind text`,
  `resolution_note text`, `counterpart_item_id uuid`, `resolved_at
  timestamptz`, all nullable); unique `(run_id, position)`.

The unique constraint on runs also closes the race between two identical
uploads: the loser's insert fails and is mapped to `DuplicateStatement`.
Hibernate `ddl-auto` is `validate`; the schema comes only from Flyway.

## 8. Configuration

| Variable | Default | Meaning |
|---|---|---|
| `SPRING_DATASOURCE_URL` / `_USERNAME` / `_PASSWORD` | required | `jdbc:postgresql://postgres:5432/reconciliation` |
| `LEDGER_BASE_URL` | `http://ledger-service` | ledger-service base URL |
| `LEDGER_TIMEOUT_MS` | `5000` | connect and read timeout |
| `MATCH_WINDOW_DAYS` | `2` | fuzzy date window, 0–7 |
| `SERVER_PORT` | `8080` | |

Bound with `@ConfigurationProperties` and validated at startup; an invalid
value stops the application. Logs are structured JSON on stdout (Spring Boot's
built-in structured logging), ready for Loki.

## 9. Infrastructure

### 9.1 Dependencies

Spring Boot 4.1.1 starters: `webmvc`, `validation`, `data-jpa`, `flyway`,
`actuator`, `restclient`; `org.postgresql:postgresql`,
`org.flywaydb:flyway-database-postgresql`, `org.apache.commons:commons-csv`
(1.14), `com.fasterxml.uuid:java-uuid-generator` (v7 ids). Test:
`spring-boot-starter-test` (JUnit 5, AssertJ, MockMvc,
`MockRestServiceServer`), `com.tngtech.archunit:archunit-junit5` (1.5).
Build plugins: Spotless (Palantir Java format), Checkstyle, Surefire (unit and
architecture tests) and Failsafe (`*IT` integration tests). Exact starter
artifact names follow the Boot 4 modular starters and are pinned by the
parent POM.

### 9.2 Container image (`services/reconciliation-service/Dockerfile`)

| Stage | Purpose |
|---|---|
| `deps` | `eclipse-temurin:21-jdk-alpine`; copy `mvnw`, `.mvn`, `pom.xml`; `./mvnw dependency:go-offline` (cached) |
| `dev` | from `deps`; source bind-mounted; `./mvnw spring-boot:run` |
| `build` | from `deps`; copy `src`; `./mvnw -DskipTests package` |
| `runtime` | `eclipse-temurin:21-jre-alpine`; non-root user; the Spring Boot jar extracted with `-Djarmode=tools extract` (`app.jar` + `lib/`); `EXPOSE 8080`; `HEALTHCHECK` on `/actuator/health/liveness` (so a ledger outage does not mark the container unhealthy); `ENTRYPOINT ["java", "-jar", "app.jar"]` |

### 9.3 Compose and Makefile

- `reconciliation-service`: build target `dev`, `profiles:
  ["reconciliation-service"]`, ports `8083:8080`, datasource env for the
  `reconciliation` database, `LEDGER_BASE_URL=http://ledger-service`,
  `TEST_DATASOURCE_URL` for `reconciliation_test`, a named volume for the
  Maven repository (`~/.m2`), depends on healthy postgres.
- `make recon-db`: create `reconciliation` and `reconciliation_test` if
  missing. `make recon-test`: `./mvnw verify` in the container.
  `make recon-sh`: a shell in the container. `make up` and
  `make up-reconciliation-service` pick the service up once its Dockerfile
  exists.

## 10. Testing strategy

Test-first for every unit.

| Level | Tooling | Covers |
|---|---|---|
| Unit | JUnit 5 + AssertJ | `Money` parsing (scale per currency, `0`, negatives, `1e3`, `10.`, spaces, overflow), `Period` (limits, `widen`), every `Matcher` rule (reference hit, reference with different amount, reference to nothing falling through to fuzzy, unique fuzzy, ambiguous one-bank-many-ledger and many-bank-one-ledger, window edges at exactly N and N+1 days, out-of-period ledger entries dropped, determinism under shuffled input), `ReconciliationRun` resolve rules and status transition, `BankStatementCsvParser` (header, every column error, quoting, BOM, CRLF, blank lines, row limit), use cases against in-memory fakes |
| Adapter | `MockRestServiceServer` | ledger statement mapping (`debit`/`credit`), currency mismatch, 404, 5xx, garbage body, chunking of periods over 366 days |
| Architecture | ArchUnit | layer rules of §3 |
| Integration (`*IT`) | `@SpringBootTest` + MockMvc, compose Postgres `reconciliation_test`, ledger port replaced by a stub bean | upload → run → items → resolve end to end; every §6.1 error; duplicate upload including the concurrent case; optimistic locking on concurrent resolves; Flyway schema validates against the entities |

A live smoke test (in the plan, not in CI) runs the service against the real
ledger: create deposits and withdrawals, write a CSV that references some of
the transfer ids and omits others, upload it, and check the items.

## 11. Quality gates

From `services/reconciliation-service`, before each commit: `./mvnw verify`
(Spotless check, Checkstyle, unit, architecture and integration tests). Commit
messages contain no AI attribution. A CI workflow is out of scope, as for the
other services.

## 12. Out of scope (roadmap)

- Authentication and per-operator audit (`resolvedBy`)
- Other statement formats (MT940, CAMT.053) and fetching statements automatically
- Consuming the ledger's Redis stream
- Split or partial matches (one bank line to several ledger entries)
- Writing adjustments back to the ledger
- Publishing reconciliation events to webhooks-service

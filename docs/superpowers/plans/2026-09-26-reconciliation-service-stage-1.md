# Reconciliation Service Stage 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver `services/reconciliation-service`, a Java 21 / Spring Boot 4.1.1 service that reconciles an uploaded bank statement CSV against the ledger's settlement-account statement, stores each run with its items, and lets an operator resolve the exceptions.

**Architecture:** Domain-driven, ports and adapters, one bounded context. `domain` holds value objects, the `Matcher` domain service, the `ReconciliationRun` aggregate and the `RunRepository` port, with no framework imports. `application` holds the use cases and ports (`LedgerStatementPort`, `StatementParser`, `IdGenerator`). `infrastructure` holds the Spring MVC controller, the CSV parser, the ledger `RestClient` adapter, JPA persistence and the wiring. ArchUnit enforces the layering.

**Tech Stack:**
- Java 21, Spring Boot 4.1.1 (Spring Framework 7, Hibernate 7, Jackson 3), Maven via `mvnw`
- Spring Web MVC, Bean Validation, Spring Data JPA, Flyway, Actuator, `RestClient`
- PostgreSQL 16 (shared compose instance), Apache Commons CSV 1.14.1, java-uuid-generator 5.2.0
- JUnit 5, AssertJ, MockMvc, `MockRestServiceServer`, ArchUnit 1.5.1
- Spotless (Palantir Java Format) 3.10.3, Checkstyle 14.1.0 via maven-checkstyle-plugin 3.6.0, Failsafe for `*IT`
- Docker: `eclipse-temurin:21-jdk-alpine` / `21-jre-alpine`

**Spec:** `docs/superpowers/specs/2026-09-26-reconciliation-service-design.md`

## Global Constraints

- Java `21` (`<java.version>21</java.version>`), Spring Boot parent `4.1.1`. Everything builds and runs in Docker; the host JDK does not matter.
- Base package `com.ledgerplatform.reconciliation`. Layers `domain`, `application`, `infrastructure`; `domain` imports no Spring, Jakarta Persistence, Hibernate or Jackson, and nothing from `application`/`infrastructure`; `application` imports nothing from `infrastructure`, Spring, Jakarta Persistence or Hibernate.
- Money is never `double`/`float`: `Money(long minor, SupportedCurrency currency)`; amounts are decimal strings in JSON.
- Supported currencies exactly: USD 2, EUR 2, GBP 2, TZS 2, JPY 0.
- IDs are UUID v7.
- Errors are RFC 9457 `application/problem+json` via `ProblemDetail`, `type` = `urn:reconciliation:problem:<kebab-name>`.
- Schema only through Flyway (`src/main/resources/db/migration`); Hibernate `ddl-auto: validate`.
- CSV header exactly `date,direction,description,reference,currency,amount`.
- No secrets in committed files beyond the dev-only database credentials the root compose file already uses. Never add API keys, tokens or signing secrets to docs, tests or config.
- Commit messages contain **no AI attribution**: no `Co-Authored-By` naming a model, no "Generated with" footer, no mention of Claude.
- Gates before each commit, from the repo root: `docker compose exec reconciliation-service ./mvnw -B spotless:apply` then `docker compose exec reconciliation-service ./mvnw -B verify` (Spotless check, Checkstyle, unit + architecture tests via Surefire, `*IT` via Failsafe).

## Review Focus

1. **A statement that is not UTF-8** (for example Windows-1252 accents in a description). Expect 422 `invalid-statement` naming the file, never 500. Task 5 test `rejectsNonUtf8Files` pins it.
2. **A bank reference written in upper case** (`0199A…`). Expect it to match the transfer id (UUIDs compare case-insensitively). Task 3 test `referenceMatchIsCaseInsensitive` pins it.
3. **A ledger posting at 23:30 UTC and a bank line dated the next day.** Expect a fuzzy match within the window, with the ledger date taken in UTC. Task 2 test `ledgerDateIsTheUtcCalendarDate` and Task 3 test `matchesAcrossMidnightWithinWindow` pin it.
4. **Two identical bank lines (same date, amount, direction) and one ledger entry.** Expect both `AMBIGUOUS` and the ledger entry `MISSING_IN_BANK`, never one arbitrary match. Task 3 test `twoBankLinesCompetingForOneEntryAreBothAmbiguous` pins it.
5. **The same file uploaded for a different period.** Expect a new run (only the same currency + period + file is a duplicate). Task 9 test `sameFileForAnotherPeriodIsNotADuplicate` pins it.

---

## File Structure

```
ledger-platform/
  docker-compose.yaml                                   (Task 1: reconciliation-service + recon-m2 volume)
  Makefile                                              (Task 1: recon-db, recon-test, recon-sh)
  services/reconciliation-service/
    Dockerfile, .dockerignore, .gitignore, pom.xml, checkstyle.xml, mvnw, mvnw.cmd, .mvn/wrapper/   (Task 1)
    README.md                                                                                          (Task 10)
    src/main/resources/application.yaml                                                                (Task 1, extended in 9)
    src/main/resources/db/migration/V1__init.sql                                                       (Task 8)
    src/main/java/com/ledgerplatform/reconciliation/
      ReconciliationApplication.java                                                                   (Task 1)
      domain/DomainException.java                                                                      (Task 2)
      domain/money/{SupportedCurrency,UnsupportedCurrencyException,Money,InvalidMoneyException,Direction}.java   (Task 2)
      domain/statement/{Period,InvalidPeriodException,BankLine,LedgerEntry}.java                       (Task 2)
      domain/matching/{ItemKind,MatchMethod,MatchingPolicy,MatchOutcome,Matcher}.java                  (Task 3)
      domain/run/{ReconciliationRun,ReconItem,RunStatus,Resolution,ResolutionKind,RunRepository,
                  RunNotFoundException,ItemNotFoundException,ItemAlreadyResolvedException,
                  InvalidManualMatchException,InvalidResolutionException,
                  DuplicateStatementException,ConcurrentUpdateException}.java                          (Task 4)
      application/port/{StatementParser,RowError,InvalidStatementException}.java                       (Task 5)
      application/port/{LedgerStatementPort,LedgerUnavailableException}.java                           (Task 6)
      application/port/IdGenerator.java                                                                (Task 7)
      application/{StartReconciliation,ResolveItem,GetRun,ListRuns,ListItems}.java                     (Task 7)
      infrastructure/csv/BankStatementCsvParser.java                                                   (Task 5)
      infrastructure/ledger/{SettlementAccounts,StatementResponse,LedgerHttpClient,LedgerHealthIndicator}.java   (Task 6)
      infrastructure/system/UuidV7Generator.java                                                       (Task 7)
      infrastructure/persistence/{RunEntity,ItemEntity,RunJpaRepository,RunMapper,JpaRunRepository}.java  (Task 8)
      infrastructure/web/{RunController,RunResponse,ItemResponse,ListResponse,ResolveRequest,
                          StatementTooLargeException,ProblemDetailsAdvice}.java                        (Task 9)
      infrastructure/config/{ReconciliationProperties,ReconciliationConfiguration}.java                (Task 9)
    src/test/resources/application-test.yaml                                                           (Task 1)
    src/test/java/com/ledgerplatform/reconciliation/
      ApplicationIT.java, architecture/ArchitectureTest.java                                           (Task 1)
      domain/money/MoneyTest.java, domain/statement/{PeriodTest,BankLineTest,LedgerEntryTest}.java     (Task 2)
      domain/matching/MatcherTest.java, support/Fixtures.java                                          (Task 3)
      domain/run/ReconciliationRunTest.java                                                            (Task 4)
      infrastructure/csv/BankStatementCsvParserTest.java                                               (Task 5)
      infrastructure/ledger/LedgerHttpClientTest.java                                                  (Task 6)
      application/{StartReconciliationTest,ResolveItemTest}.java, support/{InMemoryRunRepository,SequentialIds}.java (Task 7)
      infrastructure/persistence/JpaRunRepositoryIT.java                                               (Task 8)
      infrastructure/web/RunApiIT.java, support/{StubLedger,StubLedgerConfiguration}.java              (Task 9)
```

Conventions used by every task:
- Run commands from the repo root. `RECON` below means `docker compose exec reconciliation-service`.
- One unit test class: `RECON ./mvnw -B -q test -Dtest=<ClassName>` (Surefire only; formatting and Checkstyle are skipped because they bind to `verify`).
- One IT class: `RECON ./mvnw -B verify -Dsurefire.skip=true -Dit.test=<ClassName> -Dspotless.check.skip=true -Dcheckstyle.skip=true`.
- Checkstyle requires braces on every `if`/loop and forbids non-static star imports.

---

### Task 1: Scaffold, tooling, container and root wiring

**Files:**
- Create: `services/reconciliation-service/{pom.xml,checkstyle.xml,.gitignore,.dockerignore,Dockerfile}`, Maven wrapper (`mvnw`, `mvnw.cmd`, `.mvn/wrapper/maven-wrapper.properties`)
- Create: `src/main/java/com/ledgerplatform/reconciliation/ReconciliationApplication.java`, `src/main/resources/application.yaml`, `src/test/resources/application-test.yaml`
- Create: `src/test/java/com/ledgerplatform/reconciliation/ApplicationIT.java`, `src/test/java/com/ledgerplatform/reconciliation/architecture/ArchitectureTest.java`
- Delete: `services/reconciliation-service/.gitkeep`
- Modify: `docker-compose.yaml` (repo root), `Makefile` (repo root)

**Interfaces:**
- Produces: the runnable `reconciliation-service` compose service on host port 8083; `make recon-db`, `make recon-test`, `make recon-sh`; `ArchitectureTest` rules used by every later task.

- [ ] **Step 1: Write `pom.xml`**

`services/reconciliation-service/pom.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<project xmlns="http://maven.apache.org/POM/4.0.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:schemaLocation="http://maven.apache.org/POM/4.0.0 https://maven.apache.org/xsd/maven-4.0.0.xsd">
    <modelVersion>4.0.0</modelVersion>

    <parent>
        <groupId>org.springframework.boot</groupId>
        <artifactId>spring-boot-starter-parent</artifactId>
        <version>4.1.1</version>
        <relativePath/>
    </parent>

    <groupId>com.ledgerplatform</groupId>
    <artifactId>reconciliation-service</artifactId>
    <version>0.0.1-SNAPSHOT</version>
    <name>reconciliation-service</name>
    <description>Reconciles bank statements against the ledger's settlement accounts</description>

    <properties>
        <java.version>21</java.version>
        <commons-csv.version>1.14.1</commons-csv.version>
        <java-uuid-generator.version>5.2.0</java-uuid-generator.version>
        <archunit.version>1.5.1</archunit.version>
        <spotless-plugin.version>3.10.3</spotless-plugin.version>
        <checkstyle-plugin.version>3.6.0</checkstyle-plugin.version>
        <checkstyle.version>14.1.0</checkstyle.version>
    </properties>

    <dependencies>
        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-webmvc</artifactId>
        </dependency>
        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-validation</artifactId>
        </dependency>
        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-data-jpa</artifactId>
        </dependency>
        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-flyway</artifactId>
        </dependency>
        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-restclient</artifactId>
        </dependency>
        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-actuator</artifactId>
        </dependency>
        <dependency>
            <groupId>org.flywaydb</groupId>
            <artifactId>flyway-database-postgresql</artifactId>
        </dependency>
        <dependency>
            <groupId>org.postgresql</groupId>
            <artifactId>postgresql</artifactId>
            <scope>runtime</scope>
        </dependency>
        <dependency>
            <groupId>org.apache.commons</groupId>
            <artifactId>commons-csv</artifactId>
            <version>${commons-csv.version}</version>
        </dependency>
        <dependency>
            <groupId>com.fasterxml.uuid</groupId>
            <artifactId>java-uuid-generator</artifactId>
            <version>${java-uuid-generator.version}</version>
        </dependency>

        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-test</artifactId>
            <scope>test</scope>
        </dependency>
        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-webmvc-test</artifactId>
            <scope>test</scope>
        </dependency>
        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-restclient-test</artifactId>
            <scope>test</scope>
        </dependency>
        <dependency>
            <groupId>com.tngtech.archunit</groupId>
            <artifactId>archunit-junit5</artifactId>
            <version>${archunit.version}</version>
            <scope>test</scope>
        </dependency>
    </dependencies>

    <build>
        <finalName>app</finalName>
        <plugins>
            <plugin>
                <groupId>org.springframework.boot</groupId>
                <artifactId>spring-boot-maven-plugin</artifactId>
            </plugin>
            <plugin>
                <groupId>org.apache.maven.plugins</groupId>
                <artifactId>maven-failsafe-plugin</artifactId>
                <executions>
                    <execution>
                        <goals>
                            <goal>integration-test</goal>
                            <goal>verify</goal>
                        </goals>
                    </execution>
                </executions>
            </plugin>
            <plugin>
                <groupId>com.diffplug.spotless</groupId>
                <artifactId>spotless-maven-plugin</artifactId>
                <version>${spotless-plugin.version}</version>
                <configuration>
                    <java>
                        <palantirJavaFormat/>
                        <removeUnusedImports/>
                    </java>
                </configuration>
                <executions>
                    <execution>
                        <goals>
                            <goal>check</goal>
                        </goals>
                    </execution>
                </executions>
            </plugin>
            <plugin>
                <groupId>org.apache.maven.plugins</groupId>
                <artifactId>maven-checkstyle-plugin</artifactId>
                <version>${checkstyle-plugin.version}</version>
                <dependencies>
                    <dependency>
                        <groupId>com.puppycrawl.tools</groupId>
                        <artifactId>checkstyle</artifactId>
                        <version>${checkstyle.version}</version>
                    </dependency>
                </dependencies>
                <configuration>
                    <configLocation>checkstyle.xml</configLocation>
                    <includeTestSourceDirectory>true</includeTestSourceDirectory>
                    <consoleOutput>true</consoleOutput>
                    <failOnViolation>true</failOnViolation>
                    <violationSeverity>warning</violationSeverity>
                </configuration>
                <executions>
                    <execution>
                        <phase>verify</phase>
                        <goals>
                            <goal>check</goal>
                        </goals>
                    </execution>
                </executions>
            </plugin>
        </plugins>
    </build>
</project>
```

`services/reconciliation-service/checkstyle.xml`:

```xml
<?xml version="1.0"?>
<!DOCTYPE module PUBLIC "-//Checkstyle//DTD Checkstyle Configuration 1.3//EN" "https://checkstyle.org/dtds/configuration_1_3.dtd">
<!-- Formatting is Spotless's job; Checkstyle only guards rules a formatter cannot. -->
<module name="Checker">
    <module name="TreeWalker">
        <module name="AvoidStarImport">
            <property name="allowStaticMemberImports" value="true"/>
        </module>
        <module name="UnusedImports"/>
        <module name="RedundantImport"/>
        <module name="NeedBraces"/>
        <module name="EqualsHashCode"/>
        <module name="SimplifyBooleanExpression"/>
        <module name="EmptyCatchBlock"/>
    </module>
</module>
```

`services/reconciliation-service/.gitignore`:

```gitignore
/target/
*.log
.idea/
*.iml
.vscode/
```

`services/reconciliation-service/.dockerignore`:

```
target
.idea
*.iml
.vscode
```

- [ ] **Step 2: Generate the Maven wrapper**

```bash
rm services/reconciliation-service/.gitkeep
docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e MAVEN_CONFIG=/tmp/.m2 \
  -v "$PWD/services/reconciliation-service":/app -w /app \
  maven:3.9-eclipse-temurin-21 mvn -B -q -Dmaven.repo.local=/tmp/.m2 -N wrapper:wrapper -Dmaven=3.9.16
ls -a services/reconciliation-service   # expect mvnw, mvnw.cmd, .mvn/
```

- [ ] **Step 3: Application class and configuration**

`src/main/java/com/ledgerplatform/reconciliation/ReconciliationApplication.java`:

```java
package com.ledgerplatform.reconciliation;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;

@SpringBootApplication
public class ReconciliationApplication {

    public static void main(String[] args) {
        SpringApplication.run(ReconciliationApplication.class, args);
    }
}
```

`src/main/resources/application.yaml` (the datasource URL, username and password come from `SPRING_DATASOURCE_*` environment variables; no credentials in this file):

```yaml
spring:
  application:
    name: reconciliation-service
  jpa:
    open-in-view: false
    hibernate:
      ddl-auto: validate
  servlet:
    multipart:
      max-file-size: 6MB
      max-request-size: 7MB
server:
  port: 8080
logging:
  structured:
    format:
      console: ecs
management:
  endpoints:
    web:
      exposure:
        include: health
  endpoint:
    health:
      show-details: always
      probes:
        enabled: true
```

`src/test/resources/application-test.yaml`:

```yaml
spring:
  datasource:
    url: ${TEST_DATASOURCE_URL:jdbc:postgresql://localhost:5433/reconciliation_test}
```

- [ ] **Step 4: Write the failing tests**

`src/test/java/com/ledgerplatform/reconciliation/ApplicationIT.java`:

```java
package com.ledgerplatform.reconciliation;

import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.boot.webmvc.test.autoconfigure.AutoConfigureMockMvc;
import org.springframework.test.context.ActiveProfiles;
import org.springframework.test.web.servlet.MockMvc;

@SpringBootTest
@AutoConfigureMockMvc
@ActiveProfiles("test")
class ApplicationIT {

    @Autowired
    MockMvc mvc;

    @Test
    void startsAndReportsLiveness() throws Exception {
        mvc.perform(get("/actuator/health/liveness"))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.status").value("UP"));
    }
}
```

`src/test/java/com/ledgerplatform/reconciliation/architecture/ArchitectureTest.java`:

```java
package com.ledgerplatform.reconciliation.architecture;

import static com.tngtech.archunit.lang.syntax.ArchRuleDefinition.noClasses;

import com.tngtech.archunit.core.importer.ImportOption;
import com.tngtech.archunit.junit.AnalyzeClasses;
import com.tngtech.archunit.junit.ArchTest;
import com.tngtech.archunit.lang.ArchRule;

@AnalyzeClasses(packages = "com.ledgerplatform.reconciliation", importOptions = ImportOption.DoNotIncludeTests.class)
class ArchitectureTest {

    @ArchTest
    static final ArchRule domainIsFrameworkFree = noClasses()
            .that()
            .resideInAPackage("..reconciliation.domain..")
            .should()
            .dependOnClassesThat()
            .resideInAnyPackage(
                    "org.springframework..",
                    "jakarta.persistence..",
                    "org.hibernate..",
                    "tools.jackson..",
                    "com.fasterxml.jackson..",
                    "..reconciliation.application..",
                    "..reconciliation.infrastructure..")
            .allowEmptyShould(true);

    @ArchTest
    static final ArchRule applicationHasNoAdapters = noClasses()
            .that()
            .resideInAPackage("..reconciliation.application..")
            .should()
            .dependOnClassesThat()
            .resideInAnyPackage(
                    "org.springframework..",
                    "jakarta.persistence..",
                    "org.hibernate..",
                    "..reconciliation.infrastructure..")
            .allowEmptyShould(true);
}
```

- [ ] **Step 5: Dockerfile**

`services/reconciliation-service/Dockerfile`:

```dockerfile
# syntax=docker/dockerfile:1

FROM eclipse-temurin:21-jdk-alpine AS deps
ARG UID=1000
ARG GID=1000
RUN addgroup -g "${GID}" app && adduser -D -u "${UID}" -G app app && mkdir -p /home/app/.m2 && chown -R app:app /home/app
WORKDIR /app
RUN chown app:app /app
USER app
COPY --chown=app:app mvnw pom.xml ./
COPY --chown=app:app .mvn .mvn
RUN ./mvnw -B -q dependency:go-offline

# Local development: the source is bind-mounted over /app and ~/.m2 lives in a named volume.
FROM deps AS dev
COPY --chown=app:app . .
EXPOSE 8080
CMD ["./mvnw", "-B", "spring-boot:run"]

FROM deps AS build
COPY --chown=app:app checkstyle.xml ./
COPY --chown=app:app src src
RUN ./mvnw -B -q -DskipTests -Dspotless.check.skip=true -Dcheckstyle.skip=true package \
    && java -Djarmode=tools -jar target/app.jar extract --destination target/extracted

FROM eclipse-temurin:21-jre-alpine AS runtime
RUN addgroup -S app && adduser -S -G app app
WORKDIR /app
COPY --from=build --chown=app:app /app/target/extracted/ ./
USER app
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --retries=6 \
  CMD wget -qO- http://localhost:8080/actuator/health/liveness >/dev/null || exit 1
ENTRYPOINT ["java", "-jar", "app.jar"]
```

- [ ] **Step 6: Root compose service and Makefile targets**

In the repo-root `docker-compose.yaml`, replace the comment

```yaml
  # reconciliation-service is added here with `profiles: ["reconciliation-service"]`
  # once its Dockerfile exists.
```

with:

```yaml
  reconciliation-service:
    build:
      context: ./services/reconciliation-service
      target: dev
      args:
        UID: ${UID:-1000}
        GID: ${GID:-1000}
    profiles: ["reconciliation-service"]
    ports:
      - "8083:8080"
    volumes:
      - ./services/reconciliation-service:/app
      - recon-m2:/home/app/.m2
    environment:
      SPRING_DATASOURCE_URL: jdbc:postgresql://postgres:5432/reconciliation
      SPRING_DATASOURCE_USERNAME: ledger
      SPRING_DATASOURCE_PASSWORD: ledger
      TEST_DATASOURCE_URL: jdbc:postgresql://postgres:5432/reconciliation_test
      LEDGER_BASE_URL: http://ledger-service
    depends_on:
      postgres:
        condition: service_healthy
```

and add `recon-m2:` to the top-level `volumes:` list.

In the repo-root `Makefile`, add below the `# webhooks-service helpers` block:

```make
# reconciliation-service helpers
RECON      := $(COMPOSE) exec reconciliation-service
RECON_DBS  := reconciliation reconciliation_test
```

append `recon-db recon-test recon-sh` to the `.PHONY` line, and add at the end:

```make
recon-db: ## Create the reconciliation and reconciliation_test databases if missing
	@for db in $(RECON_DBS); do \
	  $(COMPOSE) exec -T postgres psql -U ledger -d ledger -tAc "SELECT 1 FROM pg_database WHERE datname = '$$db'" | grep -q 1 \
	    || $(COMPOSE) exec -T postgres createdb -U ledger $$db; \
	done

recon-test: ## Format check, Checkstyle, unit, architecture and integration tests for reconciliation-service
	$(RECON) ./mvnw -B verify

recon-sh: ## Shell into the reconciliation-service container
	$(RECON) sh
```

- [ ] **Step 7: Build, start and run the tests**

```bash
make up-infra && make recon-db
docker compose --profile reconciliation-service up -d --build reconciliation-service
docker compose logs --tail 20 reconciliation-service        # expect "Started ReconciliationApplication"
docker compose exec reconciliation-service ./mvnw -B spotless:apply
make recon-test
curl -s localhost:8083/actuator/health/liveness            # {"status":"UP"}
```

Expected: `ApplicationIT` 1 test and `ArchitectureTest` 2 rules pass; `BUILD SUCCESS`.

- [ ] **Step 8: Check the production image builds**

```bash
docker build --target runtime -t reconciliation-service:runtime services/reconciliation-service
```

Expected: the build succeeds (the image is not run until Task 10).

- [ ] **Step 9: Commit**

```bash
git add services/reconciliation-service docker-compose.yaml Makefile
git commit -m "build(reconciliation-service): scaffold Spring Boot 4 service with container and tooling"
git log -1 --format='%B' | grep -icE 'claude|co-authored|generated with'   # expect 0
```

---

### Task 2: Money, period and statement value objects

**Files:**
- Create: `domain/DomainException.java`
- Create: `domain/money/{SupportedCurrency,UnsupportedCurrencyException,Money,InvalidMoneyException,Direction}.java`
- Create: `domain/statement/{Period,InvalidPeriodException,BankLine,LedgerEntry}.java`
- Test: `domain/money/MoneyTest.java`, `domain/statement/{PeriodTest,BankLineTest,LedgerEntryTest}.java`

(All paths under `src/main/java/com/ledgerplatform/reconciliation/` and `src/test/java/com/ledgerplatform/reconciliation/`.)

**Interfaces:**
- Produces:
  - `abstract class DomainException extends RuntimeException { String code(); }`
  - `enum SupportedCurrency { USD, EUR, GBP, TZS, JPY; int scale(); static SupportedCurrency of(String code) }` (throws `UnsupportedCurrencyException`, code `unsupported-currency`)
  - `record Money(long minor, SupportedCurrency currency) { static Money parse(String, SupportedCurrency); String toDecimalString(); }` (throws `InvalidMoneyException`, code `invalid-amount`)
  - `enum Direction { IN, OUT }`
  - `record Period(LocalDate from, LocalDate to) { static final int MAX_DAYS = 366; static Period of(LocalDate, LocalDate); long days(); boolean contains(LocalDate); Period widen(int days); List<Period> split(int maxDays); }` (throws `InvalidPeriodException`, code `invalid-period`)
  - `record BankLine(int row, LocalDate date, Direction direction, String description, String reference, Money amount) { Optional<UUID> referenceAsUuid(); }`
  - `record LedgerEntry(UUID transferId, Instant occurredAt, Direction direction, Money amount, String description) { LocalDate date(); }`

- [ ] **Step 1: Write the failing tests**

`src/test/java/com/ledgerplatform/reconciliation/domain/money/MoneyTest.java`:

```java
package com.ledgerplatform.reconciliation.domain.money;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.ValueSource;

class MoneyTest {

    @Test
    void parsesDecimalStringsIntoMinorUnits() {
        assertThat(Money.parse("10.50", SupportedCurrency.USD)).isEqualTo(new Money(1050, SupportedCurrency.USD));
        assertThat(Money.parse("10.5", SupportedCurrency.USD)).isEqualTo(new Money(1050, SupportedCurrency.USD));
        assertThat(Money.parse("7", SupportedCurrency.EUR)).isEqualTo(new Money(700, SupportedCurrency.EUR));
        assertThat(Money.parse("1500", SupportedCurrency.JPY)).isEqualTo(new Money(1500, SupportedCurrency.JPY));
    }

    @Test
    void formatsWithTheCurrencyScale() {
        assertThat(new Money(1050, SupportedCurrency.USD).toDecimalString()).isEqualTo("10.50");
        assertThat(new Money(5, SupportedCurrency.GBP).toDecimalString()).isEqualTo("0.05");
        assertThat(new Money(1500, SupportedCurrency.JPY).toDecimalString()).isEqualTo("1500");
    }

    @ParameterizedTest
    @ValueSource(strings = {"", " 10", "10 ", "-5", "+5", "10.", ".5", "1e3", "10,50", "1.005", "0", "0.00", "abc",
        "92233720368547758.08", "١٠"})
    void rejectsNonCanonicalOrNonPositiveAmounts(String raw) {
        assertThatThrownBy(() -> Money.parse(raw, SupportedCurrency.USD)).isInstanceOf(InvalidMoneyException.class);
    }

    @Test
    void rejectsDecimalsForZeroScaleCurrencies() {
        assertThatThrownBy(() -> Money.parse("10.00", SupportedCurrency.JPY))
                .isInstanceOf(InvalidMoneyException.class)
                .hasMessageContaining("0 decimals");
    }

    @Test
    void rejectsNull() {
        assertThatThrownBy(() -> Money.parse(null, SupportedCurrency.USD)).isInstanceOf(InvalidMoneyException.class);
    }

    @Test
    void resolvesSupportedCurrenciesByCode() {
        assertThat(SupportedCurrency.of("TZS")).isEqualTo(SupportedCurrency.TZS);
        assertThat(SupportedCurrency.JPY.scale()).isZero();
        assertThatThrownBy(() -> SupportedCurrency.of("usd"))
                .isInstanceOf(UnsupportedCurrencyException.class)
                .extracting(e -> ((UnsupportedCurrencyException) e).code())
                .isEqualTo("unsupported-currency");
        assertThatThrownBy(() -> SupportedCurrency.of("CHF")).isInstanceOf(UnsupportedCurrencyException.class);
        assertThatThrownBy(() -> SupportedCurrency.of(null)).isInstanceOf(UnsupportedCurrencyException.class);
    }
}
```

`src/test/java/com/ledgerplatform/reconciliation/domain/statement/PeriodTest.java`:

```java
package com.ledgerplatform.reconciliation.domain.statement;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import java.time.LocalDate;
import org.junit.jupiter.api.Test;

class PeriodTest {

    private static LocalDate d(String iso) {
        return LocalDate.parse(iso);
    }

    @Test
    void isInclusiveOnBothEnds() {
        Period march = Period.of(d("2026-03-01"), d("2026-03-31"));

        assertThat(march.days()).isEqualTo(31);
        assertThat(march.contains(d("2026-03-01"))).isTrue();
        assertThat(march.contains(d("2026-03-31"))).isTrue();
        assertThat(march.contains(d("2026-02-28"))).isFalse();
        assertThat(march.contains(d("2026-04-01"))).isFalse();
    }

    @Test
    void allowsASingleDay() {
        assertThat(Period.of(d("2026-03-05"), d("2026-03-05")).days()).isEqualTo(1);
    }

    @Test
    void rejectsFromAfterTo() {
        assertThatThrownBy(() -> Period.of(d("2026-03-10"), d("2026-03-01")))
                .isInstanceOf(InvalidPeriodException.class)
                .extracting(e -> ((InvalidPeriodException) e).code())
                .isEqualTo("invalid-period");
    }

    @Test
    void allowsAtMost366Days() {
        assertThat(Period.of(d("2028-01-01"), d("2028-12-31")).days()).isEqualTo(366);
        assertThatThrownBy(() -> Period.of(d("2026-01-01"), d("2027-01-02"))).isInstanceOf(InvalidPeriodException.class);
    }

    @Test
    void requiresBothDates() {
        assertThatThrownBy(() -> Period.of(null, d("2026-01-01"))).isInstanceOf(InvalidPeriodException.class);
        assertThatThrownBy(() -> Period.of(d("2026-01-01"), null)).isInstanceOf(InvalidPeriodException.class);
    }

    @Test
    void widensOnBothSidesBeyondTheMaximum() {
        Period year = Period.of(d("2028-01-01"), d("2028-12-31"));

        Period widened = year.widen(2);

        assertThat(widened).isEqualTo(new Period(d("2027-12-30"), d("2029-01-02")));
        assertThat(widened.days()).isEqualTo(370);
    }

    @Test
    void splitsIntoConsecutiveParts() {
        Period period = new Period(d("2026-01-01"), d("2026-01-10"));

        assertThat(period.split(4))
                .containsExactly(
                        new Period(d("2026-01-01"), d("2026-01-04")),
                        new Period(d("2026-01-05"), d("2026-01-08")),
                        new Period(d("2026-01-09"), d("2026-01-10")));
        assertThat(period.split(10)).containsExactly(period);
        assertThat(period.split(366)).containsExactly(period);
    }
}
```

`src/test/java/com/ledgerplatform/reconciliation/domain/statement/BankLineTest.java`:

```java
package com.ledgerplatform.reconciliation.domain.statement;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import java.time.LocalDate;
import java.util.UUID;
import org.junit.jupiter.api.Test;

class BankLineTest {

    private static BankLine withReference(String reference) {
        return new BankLine(
                1, LocalDate.parse("2026-03-01"), Direction.IN, null, reference, new Money(100, SupportedCurrency.USD));
    }

    @Test
    void exposesAUuidReference() {
        UUID id = UUID.fromString("0199a111-2222-7333-8444-555566667777");

        assertThat(withReference("0199a111-2222-7333-8444-555566667777").referenceAsUuid()).contains(id);
        assertThat(withReference("0199A111-2222-7333-8444-555566667777").referenceAsUuid()).contains(id);
    }

    @Test
    void ignoresReferencesThatAreNotCanonicalUuids() {
        assertThat(withReference(null).referenceAsUuid()).isEmpty();
        assertThat(withReference("INV-2026-001").referenceAsUuid()).isEmpty();
        assertThat(withReference("1-1-1-1-1").referenceAsUuid()).isEmpty();
    }

    @Test
    void requiresAPositiveRowAndItsValues() {
        Money money = new Money(1, SupportedCurrency.USD);
        LocalDate date = LocalDate.parse("2026-03-01");

        assertThatThrownBy(() -> new BankLine(0, date, Direction.IN, null, null, money))
                .isInstanceOf(IllegalArgumentException.class);
        assertThatThrownBy(() -> new BankLine(1, null, Direction.IN, null, null, money))
                .isInstanceOf(NullPointerException.class);
        assertThatThrownBy(() -> new BankLine(1, date, null, null, null, money))
                .isInstanceOf(NullPointerException.class);
        assertThatThrownBy(() -> new BankLine(1, date, Direction.IN, null, null, null))
                .isInstanceOf(NullPointerException.class);
    }
}
```

`src/test/java/com/ledgerplatform/reconciliation/domain/statement/LedgerEntryTest.java`:

```java
package com.ledgerplatform.reconciliation.domain.statement;

import static org.assertj.core.api.Assertions.assertThat;

import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;
import org.junit.jupiter.api.Test;

class LedgerEntryTest {

    @Test
    void ledgerDateIsTheUtcCalendarDate() {
        LedgerEntry late = new LedgerEntry(
                UUID.randomUUID(),
                Instant.parse("2026-03-01T23:30:00Z"),
                Direction.OUT,
                new Money(500, SupportedCurrency.USD),
                "ATM");

        assertThat(late.date()).isEqualTo(LocalDate.parse("2026-03-01"));
    }
}
```

- [ ] **Step 2: Run the tests to watch them fail**

Run: `docker compose exec reconciliation-service ./mvnw -B -q test -Dtest='MoneyTest,PeriodTest,BankLineTest,LedgerEntryTest'`
Expected: compilation FAILS (`Money`, `Period`, … do not exist).

- [ ] **Step 3: Implement**

`src/main/java/com/ledgerplatform/reconciliation/domain/DomainException.java`:

```java
package com.ledgerplatform.reconciliation.domain;

/** A business rule violation. Carries a stable code; the web layer maps it to an HTTP status. */
public abstract class DomainException extends RuntimeException {

    protected DomainException(String message) {
        super(message);
    }

    public abstract String code();
}
```

`domain/money/SupportedCurrency.java`:

```java
package com.ledgerplatform.reconciliation.domain.money;

/** The currencies the ledger supports, with their number of decimals. */
public enum SupportedCurrency {
    USD(2),
    EUR(2),
    GBP(2),
    TZS(2),
    JPY(0);

    private final int scale;

    SupportedCurrency(int scale) {
        this.scale = scale;
    }

    public int scale() {
        return scale;
    }

    public static SupportedCurrency of(String code) {
        for (SupportedCurrency currency : values()) {
            if (currency.name().equals(code)) {
                return currency;
            }
        }
        throw new UnsupportedCurrencyException(code);
    }
}
```

`domain/money/UnsupportedCurrencyException.java`:

```java
package com.ledgerplatform.reconciliation.domain.money;

import com.ledgerplatform.reconciliation.domain.DomainException;

public final class UnsupportedCurrencyException extends DomainException {

    public UnsupportedCurrencyException(String code) {
        super("Unsupported currency \"%s\". Expected one of USD, EUR, GBP, TZS, JPY.".formatted(code));
    }

    @Override
    public String code() {
        return "unsupported-currency";
    }
}
```

`domain/money/InvalidMoneyException.java`:

```java
package com.ledgerplatform.reconciliation.domain.money;

import com.ledgerplatform.reconciliation.domain.DomainException;

public final class InvalidMoneyException extends DomainException {

    public InvalidMoneyException(String message) {
        super(message);
    }

    @Override
    public String code() {
        return "invalid-amount";
    }
}
```

`domain/money/Money.java`:

```java
package com.ledgerplatform.reconciliation.domain.money;

import java.math.BigDecimal;
import java.util.Objects;
import java.util.regex.Pattern;

/** A positive amount in minor units. Never a floating-point number. */
public record Money(long minor, SupportedCurrency currency) {

    private static final Pattern DECIMAL = Pattern.compile("^[0-9]+(\\.[0-9]+)?$");

    public Money {
        Objects.requireNonNull(currency, "currency");
        if (minor <= 0) {
            throw new InvalidMoneyException("An amount must be greater than zero.");
        }
    }

    /** Accepts only canonical positive decimals such as "10.50", with at most the currency's decimals. */
    public static Money parse(String raw, SupportedCurrency currency) {
        if (raw == null || !DECIMAL.matcher(raw).matches()) {
            throw new InvalidMoneyException("Use a positive decimal such as 10.50.");
        }
        BigDecimal value = new BigDecimal(raw);
        if (value.scale() > currency.scale()) {
            throw new InvalidMoneyException(
                    "%s amounts have at most %d decimals.".formatted(currency, currency.scale()));
        }
        try {
            return new Money(value.movePointRight(currency.scale()).longValueExact(), currency);
        } catch (ArithmeticException e) {
            throw new InvalidMoneyException("The amount is too large.");
        }
    }

    public String toDecimalString() {
        return BigDecimal.valueOf(minor, currency.scale()).toPlainString();
    }
}
```

`domain/money/Direction.java`:

```java
package com.ledgerplatform.reconciliation.domain.money;

/** Seen from the bank account: money coming in or going out. */
public enum Direction {
    IN,
    OUT
}
```

`domain/statement/InvalidPeriodException.java`:

```java
package com.ledgerplatform.reconciliation.domain.statement;

import com.ledgerplatform.reconciliation.domain.DomainException;

public final class InvalidPeriodException extends DomainException {

    public InvalidPeriodException(String message) {
        super(message);
    }

    @Override
    public String code() {
        return "invalid-period";
    }
}
```

`domain/statement/Period.java`:

```java
package com.ledgerplatform.reconciliation.domain.statement;

import java.time.LocalDate;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.List;
import java.util.Objects;

/** An inclusive range of calendar dates. */
public record Period(LocalDate from, LocalDate to) {

    /** The longest period a run may cover, counting both ends. */
    public static final int MAX_DAYS = 366;

    public Period {
        Objects.requireNonNull(from, "from");
        Objects.requireNonNull(to, "to");
        if (from.isAfter(to)) {
            throw new InvalidPeriodException("\"from\" (%s) must not be after \"to\" (%s).".formatted(from, to));
        }
    }

    /** A run's period: both dates required, at most {@link #MAX_DAYS} days. */
    public static Period of(LocalDate from, LocalDate to) {
        if (from == null || to == null) {
            throw new InvalidPeriodException("Both \"from\" and \"to\" are required.");
        }
        Period period = new Period(from, to);
        if (period.days() > MAX_DAYS) {
            throw new InvalidPeriodException("A period may cover at most %d days.".formatted(MAX_DAYS));
        }
        return period;
    }

    public long days() {
        return ChronoUnit.DAYS.between(from, to) + 1;
    }

    public boolean contains(LocalDate date) {
        return !date.isBefore(from) && !date.isAfter(to);
    }

    public Period widen(int days) {
        return new Period(from.minusDays(days), to.plusDays(days));
    }

    /** Consecutive periods of at most {@code maxDays} days that together cover this one. */
    public List<Period> split(int maxDays) {
        if (maxDays < 1) {
            throw new IllegalArgumentException("maxDays must be positive");
        }
        List<Period> parts = new ArrayList<>();
        LocalDate start = from;
        while (!start.isAfter(to)) {
            LocalDate end = start.plusDays(maxDays - 1L);
            if (end.isAfter(to)) {
                end = to;
            }
            parts.add(new Period(start, end));
            start = end.plusDays(1);
        }
        return List.copyOf(parts);
    }
}
```

`domain/statement/BankLine.java`:

```java
package com.ledgerplatform.reconciliation.domain.statement;

import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import java.time.LocalDate;
import java.util.Objects;
import java.util.Optional;
import java.util.UUID;
import java.util.regex.Pattern;

/** One row of the bank statement. {@code row} is 1-based over data rows; description and reference may be null. */
public record BankLine(
        int row, LocalDate date, Direction direction, String description, String reference, Money amount) {

    private static final Pattern CANONICAL_UUID =
            Pattern.compile("^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$");

    public BankLine {
        if (row < 1) {
            throw new IllegalArgumentException("row is 1-based");
        }
        Objects.requireNonNull(date, "date");
        Objects.requireNonNull(direction, "direction");
        Objects.requireNonNull(amount, "amount");
    }

    /** The reference as a ledger transfer id, when it is a canonical UUID (any letter case). */
    public Optional<UUID> referenceAsUuid() {
        if (reference == null || !CANONICAL_UUID.matcher(reference).matches()) {
            return Optional.empty();
        }
        return Optional.of(UUID.fromString(reference));
    }
}
```

`domain/statement/LedgerEntry.java`:

```java
package com.ledgerplatform.reconciliation.domain.statement;

import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneOffset;
import java.util.Objects;
import java.util.UUID;

/** A deposit or withdrawal as the ledger recorded it on the settlement account. Description may be null. */
public record LedgerEntry(UUID transferId, Instant occurredAt, Direction direction, Money amount, String description) {

    public LedgerEntry {
        Objects.requireNonNull(transferId, "transferId");
        Objects.requireNonNull(occurredAt, "occurredAt");
        Objects.requireNonNull(direction, "direction");
        Objects.requireNonNull(amount, "amount");
    }

    /** The date used for matching: the UTC calendar date of the posting. */
    public LocalDate date() {
        return LocalDate.ofInstant(occurredAt, ZoneOffset.UTC);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec reconciliation-service ./mvnw -B -q test -Dtest='MoneyTest,PeriodTest,BankLineTest,LedgerEntryTest,ArchitectureTest'`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

```bash
docker compose exec reconciliation-service ./mvnw -B spotless:apply
make recon-test
git add services/reconciliation-service
git commit -m "feat(reconciliation-service): add money, period and statement value objects"
```

---

### Task 3: Matcher domain service

**Files:**
- Create: `domain/matching/{ItemKind,MatchMethod,MatchingPolicy,MatchOutcome,Matcher}.java`
- Create (test support, reused by later tasks): `src/test/java/com/ledgerplatform/reconciliation/support/Fixtures.java`
- Test: `domain/matching/MatcherTest.java`

**Interfaces:**
- Consumes: Task 2 types.
- Produces:
  - `enum ItemKind { MATCHED, AMOUNT_MISMATCH, AMBIGUOUS, MISSING_IN_LEDGER, MISSING_IN_BANK; boolean isException(); }`
  - `enum MatchMethod { REFERENCE, FUZZY }`
  - `record MatchingPolicy(int windowDays)` (0–7), `MatchingPolicy.DEFAULT` = 2 days
  - `record MatchOutcome(ItemKind kind, MatchMethod method, BankLine bankLine, LedgerEntry ledgerEntry, List<LedgerEntry> candidates)` with factories `matched`, `amountMismatch`, `ambiguous`, `missingInLedger`, `missingInBank`
  - `final class Matcher { List<MatchOutcome> match(List<BankLine>, List<LedgerEntry>, Period, MatchingPolicy); }` — output order: one outcome per bank line in (date, row) order, then `MISSING_IN_BANK` in (occurredAt, transferId) order.
  - Test fixtures: `Fixtures.usd(String)`, `Fixtures.bank(int row, String date, Direction, String amount, String reference)`, `Fixtures.ledger(String uuid, String instant, Direction, String amount)`, `Fixtures.march()` (2026-03-01..2026-03-31)

- [ ] **Step 1: Write the fixtures and the failing test**

`src/test/java/com/ledgerplatform/reconciliation/support/Fixtures.java`:

```java
package com.ledgerplatform.reconciliation.support;

import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;

public final class Fixtures {

    private Fixtures() {}

    public static Money usd(String amount) {
        return Money.parse(amount, SupportedCurrency.USD);
    }

    public static BankLine bank(int row, String date, Direction direction, String amount, String reference) {
        return new BankLine(row, LocalDate.parse(date), direction, "line " + row, reference, usd(amount));
    }

    public static LedgerEntry ledger(String transferId, String occurredAt, Direction direction, String amount) {
        return new LedgerEntry(
                UUID.fromString(transferId), Instant.parse(occurredAt), direction, usd(amount), "ledger " + transferId);
    }

    public static Period march() {
        return Period.of(LocalDate.parse("2026-03-01"), LocalDate.parse("2026-03-31"));
    }
}
```

`src/test/java/com/ledgerplatform/reconciliation/domain/matching/MatcherTest.java`:

```java
package com.ledgerplatform.reconciliation.domain.matching;

import static com.ledgerplatform.reconciliation.domain.money.Direction.IN;
import static com.ledgerplatform.reconciliation.domain.money.Direction.OUT;
import static com.ledgerplatform.reconciliation.support.Fixtures.bank;
import static com.ledgerplatform.reconciliation.support.Fixtures.ledger;
import static com.ledgerplatform.reconciliation.support.Fixtures.march;
import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Random;
import org.junit.jupiter.api.Test;

class MatcherTest {

    private static final String A = "0199a000-0000-7000-8000-00000000000a";
    private static final String B = "0199a000-0000-7000-8000-00000000000b";
    private static final String C = "0199a000-0000-7000-8000-00000000000c";

    private final Matcher matcher = new Matcher();

    private List<MatchOutcome> match(List<BankLine> bank, List<LedgerEntry> ledger) {
        return matcher.match(bank, ledger, march(), MatchingPolicy.DEFAULT);
    }

    @Test
    void matchesByReference() {
        BankLine line = bank(1, "2026-03-05", IN, "100.00", A);
        LedgerEntry entry = ledger(A, "2026-03-20T10:00:00Z", IN, "100.00");

        assertThat(match(List.of(line), List.of(entry)))
                .containsExactly(MatchOutcome.matched(MatchMethod.REFERENCE, line, entry));
    }

    @Test
    void referenceMatchIsCaseInsensitive() {
        BankLine line = bank(1, "2026-03-05", IN, "100.00", A.toUpperCase());
        LedgerEntry entry = ledger(A, "2026-03-05T10:00:00Z", IN, "100.00");

        assertThat(match(List.of(line), List.of(entry)))
                .containsExactly(MatchOutcome.matched(MatchMethod.REFERENCE, line, entry));
    }

    @Test
    void referenceWithDifferentAmountOrDirectionIsAMismatch() {
        BankLine lessMoney = bank(1, "2026-03-05", IN, "99.00", A);
        BankLine wrongWay = bank(2, "2026-03-05", OUT, "50.00", B);
        LedgerEntry a = ledger(A, "2026-03-05T10:00:00Z", IN, "100.00");
        LedgerEntry b = ledger(B, "2026-03-05T11:00:00Z", IN, "50.00");

        assertThat(match(List.of(lessMoney, wrongWay), List.of(a, b)))
                .containsExactly(MatchOutcome.amountMismatch(lessMoney, a), MatchOutcome.amountMismatch(wrongWay, b));
    }

    @Test
    void referenceToAnUnknownTransferFallsThroughToFuzzy() {
        BankLine line = bank(1, "2026-03-05", IN, "100.00", C);
        LedgerEntry entry = ledger(A, "2026-03-06T10:00:00Z", IN, "100.00");

        assertThat(match(List.of(line), List.of(entry)))
                .containsExactly(MatchOutcome.matched(MatchMethod.FUZZY, line, entry));
    }

    @Test
    void matchesAcrossMidnightWithinWindow() {
        BankLine line = bank(1, "2026-03-02", OUT, "5.00", null);
        LedgerEntry entry = ledger(A, "2026-03-01T23:30:00Z", OUT, "5.00");

        assertThat(match(List.of(line), List.of(entry)))
                .containsExactly(MatchOutcome.matched(MatchMethod.FUZZY, line, entry));
    }

    @Test
    void windowIsInclusiveAtExactlyNDays() {
        LedgerEntry entry = ledger(A, "2026-03-10T12:00:00Z", IN, "10.00");
        BankLine twoDaysLater = bank(1, "2026-03-12", IN, "10.00", null);
        BankLine threeDaysLater = bank(1, "2026-03-13", IN, "10.00", null);

        assertThat(match(List.of(twoDaysLater), List.of(entry)).get(0).kind()).isEqualTo(ItemKind.MATCHED);
        assertThat(match(List.of(threeDaysLater), List.of(entry)))
                .containsExactly(MatchOutcome.missingInLedger(threeDaysLater), MatchOutcome.missingInBank(entry));
    }

    @Test
    void fuzzyRequiresSameDirectionAndAmount() {
        BankLine line = bank(1, "2026-03-05", IN, "10.00", null);
        LedgerEntry otherAmount = ledger(A, "2026-03-05T10:00:00Z", IN, "10.01");
        LedgerEntry otherDirection = ledger(B, "2026-03-05T11:00:00Z", OUT, "10.00");

        assertThat(match(List.of(line), List.of(otherAmount, otherDirection)))
                .containsExactly(
                        MatchOutcome.missingInLedger(line),
                        MatchOutcome.missingInBank(otherAmount),
                        MatchOutcome.missingInBank(otherDirection));
    }

    @Test
    void oneBankLineWithTwoCandidatesIsAmbiguous() {
        BankLine line = bank(1, "2026-03-05", IN, "10.00", null);
        LedgerEntry a = ledger(A, "2026-03-04T10:00:00Z", IN, "10.00");
        LedgerEntry b = ledger(B, "2026-03-06T10:00:00Z", IN, "10.00");

        assertThat(match(List.of(line), List.of(a, b)))
                .containsExactly(
                        MatchOutcome.ambiguous(line, List.of(a, b)),
                        MatchOutcome.missingInBank(a),
                        MatchOutcome.missingInBank(b));
    }

    @Test
    void twoBankLinesCompetingForOneEntryAreBothAmbiguous() {
        BankLine first = bank(1, "2026-03-05", IN, "10.00", null);
        BankLine second = bank(2, "2026-03-05", IN, "10.00", null);
        LedgerEntry a = ledger(A, "2026-03-05T10:00:00Z", IN, "10.00");

        assertThat(match(List.of(first, second), List.of(a)))
                .containsExactly(
                        MatchOutcome.ambiguous(first, List.of(a)),
                        MatchOutcome.ambiguous(second, List.of(a)),
                        MatchOutcome.missingInBank(a));
    }

    @Test
    void referencePairsBeforeFuzzySoTheRestCanMatchUniquely() {
        BankLine byReference = bank(1, "2026-03-05", IN, "10.00", A);
        BankLine withoutReference = bank(2, "2026-03-05", IN, "10.00", null);
        LedgerEntry a = ledger(A, "2026-03-05T10:00:00Z", IN, "10.00");
        LedgerEntry b = ledger(B, "2026-03-05T11:00:00Z", IN, "10.00");

        assertThat(match(List.of(byReference, withoutReference), List.of(a, b)))
                .containsExactly(
                        MatchOutcome.matched(MatchMethod.REFERENCE, byReference, a),
                        MatchOutcome.matched(MatchMethod.FUZZY, withoutReference, b));
    }

    @Test
    void ledgerEntriesOutsideThePeriodAreNeverReportedMissing() {
        LedgerEntry before = ledger(A, "2026-02-28T10:00:00Z", IN, "10.00");
        LedgerEntry after = ledger(B, "2026-04-01T10:00:00Z", IN, "20.00");
        BankLine matchesBefore = bank(1, "2026-03-01", IN, "10.00", null);

        assertThat(match(List.of(matchesBefore), List.of(before, after)))
                .containsExactly(MatchOutcome.matched(MatchMethod.FUZZY, matchesBefore, before));
    }

    @Test
    void everyBankLineAndInPeriodEntryAppearsExactlyOnce() {
        List<BankLine> bank = List.of(
                bank(1, "2026-03-01", IN, "10.00", A),
                bank(2, "2026-03-02", IN, "20.00", null),
                bank(3, "2026-03-03", OUT, "30.00", null));
        List<LedgerEntry> ledger = List.of(
                ledger(A, "2026-03-01T10:00:00Z", IN, "10.00"),
                ledger(B, "2026-03-15T10:00:00Z", OUT, "7.00"));

        List<MatchOutcome> outcomes = match(bank, ledger);

        assertThat(outcomes).hasSize(4);
        assertThat(outcomes.stream().filter(o -> o.bankLine() != null).map(MatchOutcome::bankLine))
                .containsExactlyElementsOf(bank);
        assertThat(outcomes.stream().filter(o -> o.ledgerEntry() != null).map(MatchOutcome::ledgerEntry))
                .containsExactlyInAnyOrderElementsOf(ledger);
    }

    @Test
    void isDeterministicWhateverTheInputOrder() {
        List<BankLine> bank = new ArrayList<>(List.of(
                bank(1, "2026-03-05", IN, "10.00", null),
                bank(2, "2026-03-05", IN, "10.00", null),
                bank(3, "2026-03-07", OUT, "4.00", null),
                bank(4, "2026-03-09", IN, "1.00", B)));
        List<LedgerEntry> ledger = new ArrayList<>(List.of(
                ledger(A, "2026-03-05T10:00:00Z", IN, "10.00"),
                ledger(B, "2026-03-09T10:00:00Z", IN, "1.00"),
                ledger(C, "2026-03-07T10:00:00Z", OUT, "4.00")));
        List<MatchOutcome> expected = match(bank, ledger);

        Random random = new Random(42);
        for (int i = 0; i < 20; i++) {
            Collections.shuffle(bank, random);
            Collections.shuffle(ledger, random);
            assertThat(match(bank, ledger)).isEqualTo(expected);
        }
    }

    @Test
    void policyWindowIsBounded() {
        assertThat(MatchingPolicy.DEFAULT.windowDays()).isEqualTo(2);
        assertThatThrownBy(() -> new MatchingPolicy(-1)).isInstanceOf(IllegalArgumentException.class);
        assertThatThrownBy(() -> new MatchingPolicy(8)).isInstanceOf(IllegalArgumentException.class);
    }

    @Test
    void onlyMatchedIsNotAnException() {
        assertThat(ItemKind.MATCHED.isException()).isFalse();
        assertThat(List.of(
                        ItemKind.AMOUNT_MISMATCH,
                        ItemKind.AMBIGUOUS,
                        ItemKind.MISSING_IN_LEDGER,
                        ItemKind.MISSING_IN_BANK))
                .allMatch(ItemKind::isException);
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run: `docker compose exec reconciliation-service ./mvnw -B -q test -Dtest=MatcherTest`
Expected: compilation FAILS (`Matcher` does not exist).

- [ ] **Step 3: Implement**

`domain/matching/ItemKind.java`:

```java
package com.ledgerplatform.reconciliation.domain.matching;

public enum ItemKind {
    MATCHED(false),
    AMOUNT_MISMATCH(true),
    AMBIGUOUS(true),
    MISSING_IN_LEDGER(true),
    MISSING_IN_BANK(true);

    private final boolean exception;

    ItemKind(boolean exception) {
        this.exception = exception;
    }

    /** Exceptions need an operator's resolution; matched items do not. */
    public boolean isException() {
        return exception;
    }
}
```

`domain/matching/MatchMethod.java`:

```java
package com.ledgerplatform.reconciliation.domain.matching;

public enum MatchMethod {
    REFERENCE,
    FUZZY
}
```

`domain/matching/MatchingPolicy.java`:

```java
package com.ledgerplatform.reconciliation.domain.matching;

/** How far apart (in days) a bank line and a ledger entry may be for a fuzzy match. */
public record MatchingPolicy(int windowDays) {

    public static final MatchingPolicy DEFAULT = new MatchingPolicy(2);

    public MatchingPolicy {
        if (windowDays < 0 || windowDays > 7) {
            throw new IllegalArgumentException("windowDays must be between 0 and 7");
        }
    }
}
```

`domain/matching/MatchOutcome.java`:

```java
package com.ledgerplatform.reconciliation.domain.matching;

import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import java.util.List;
import java.util.Objects;

/** What the matcher decided for one bank line or one unmatched ledger entry. */
public record MatchOutcome(
        ItemKind kind, MatchMethod method, BankLine bankLine, LedgerEntry ledgerEntry, List<LedgerEntry> candidates) {

    public MatchOutcome {
        Objects.requireNonNull(kind, "kind");
        candidates = List.copyOf(candidates);
    }

    public static MatchOutcome matched(MatchMethod method, BankLine bankLine, LedgerEntry ledgerEntry) {
        return new MatchOutcome(ItemKind.MATCHED, method, bankLine, ledgerEntry, List.of());
    }

    public static MatchOutcome amountMismatch(BankLine bankLine, LedgerEntry ledgerEntry) {
        return new MatchOutcome(ItemKind.AMOUNT_MISMATCH, null, bankLine, ledgerEntry, List.of());
    }

    public static MatchOutcome ambiguous(BankLine bankLine, List<LedgerEntry> candidates) {
        return new MatchOutcome(ItemKind.AMBIGUOUS, null, bankLine, null, candidates);
    }

    public static MatchOutcome missingInLedger(BankLine bankLine) {
        return new MatchOutcome(ItemKind.MISSING_IN_LEDGER, null, bankLine, null, List.of());
    }

    public static MatchOutcome missingInBank(LedgerEntry ledgerEntry) {
        return new MatchOutcome(ItemKind.MISSING_IN_BANK, null, null, ledgerEntry, List.of());
    }
}
```

`domain/matching/Matcher.java`:

```java
package com.ledgerplatform.reconciliation.domain.matching;

import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.Set;
import java.util.UUID;

/**
 * Pairs bank lines with ledger entries: by reference first, then by a fuzzy match that must be unique on both
 * sides. Ambiguity is reported, never guessed. Pure and deterministic.
 */
public final class Matcher {

    private static final Comparator<BankLine> BANK_ORDER =
            Comparator.comparing(BankLine::date).thenComparingInt(BankLine::row);
    private static final Comparator<LedgerEntry> LEDGER_ORDER =
            Comparator.comparing(LedgerEntry::occurredAt).thenComparing(LedgerEntry::transferId);

    /**
     * Returns one outcome per bank line in (date, row) order, then one {@code MISSING_IN_BANK} outcome per unpaired
     * ledger entry dated inside {@code period}, in (occurredAt, transferId) order.
     */
    public List<MatchOutcome> match(
            List<BankLine> bankLines, List<LedgerEntry> ledgerEntries, Period period, MatchingPolicy policy) {
        List<BankLine> bank = bankLines.stream().sorted(BANK_ORDER).toList();
        List<LedgerEntry> ledger = ledgerEntries.stream().sorted(LEDGER_ORDER).toList();
        Map<BankLine, MatchOutcome> outcomes = new HashMap<>();
        Set<UUID> paired = new HashSet<>();

        matchByReference(bank, ledger, outcomes, paired);
        matchFuzzy(bank, ledger, policy, outcomes, paired);

        List<MatchOutcome> result = new ArrayList<>();
        for (BankLine line : bank) {
            result.add(outcomes.getOrDefault(line, MatchOutcome.missingInLedger(line)));
        }
        for (LedgerEntry entry : ledger) {
            if (!paired.contains(entry.transferId()) && period.contains(entry.date())) {
                result.add(MatchOutcome.missingInBank(entry));
            }
        }
        return List.copyOf(result);
    }

    private static void matchByReference(
            List<BankLine> bank, List<LedgerEntry> ledger, Map<BankLine, MatchOutcome> outcomes, Set<UUID> paired) {
        Map<UUID, LedgerEntry> byTransferId = new HashMap<>();
        for (LedgerEntry entry : ledger) {
            byTransferId.putIfAbsent(entry.transferId(), entry);
        }
        for (BankLine line : bank) {
            Optional<LedgerEntry> hit = line.referenceAsUuid()
                    .map(byTransferId::get)
                    .filter(entry -> !paired.contains(entry.transferId()));
            if (hit.isEmpty()) {
                continue;
            }
            LedgerEntry entry = hit.get();
            paired.add(entry.transferId());
            boolean same = entry.amount().equals(line.amount()) && entry.direction() == line.direction();
            outcomes.put(
                    line,
                    same
                            ? MatchOutcome.matched(MatchMethod.REFERENCE, line, entry)
                            : MatchOutcome.amountMismatch(line, entry));
        }
    }

    private static void matchFuzzy(
            List<BankLine> bank,
            List<LedgerEntry> ledger,
            MatchingPolicy policy,
            Map<BankLine, MatchOutcome> outcomes,
            Set<UUID> paired) {
        Map<BankLine, List<LedgerEntry>> candidates = new LinkedHashMap<>();
        Map<UUID, Integer> claims = new HashMap<>();
        for (BankLine line : bank) {
            if (outcomes.containsKey(line)) {
                continue;
            }
            List<LedgerEntry> fits = ledger.stream()
                    .filter(entry -> !paired.contains(entry.transferId()))
                    .filter(entry -> fits(line, entry, policy))
                    .toList();
            candidates.put(line, fits);
            for (LedgerEntry entry : fits) {
                claims.merge(entry.transferId(), 1, Integer::sum);
            }
        }
        candidates.forEach((line, fits) -> {
            if (fits.size() == 1 && claims.get(fits.get(0).transferId()) == 1) {
                paired.add(fits.get(0).transferId());
                outcomes.put(line, MatchOutcome.matched(MatchMethod.FUZZY, line, fits.get(0)));
            } else if (!fits.isEmpty()) {
                outcomes.put(line, MatchOutcome.ambiguous(line, fits));
            }
        });
    }

    private static boolean fits(BankLine line, LedgerEntry entry, MatchingPolicy policy) {
        return entry.direction() == line.direction()
                && entry.amount().equals(line.amount())
                && Math.abs(ChronoUnit.DAYS.between(line.date(), entry.date())) <= policy.windowDays();
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec reconciliation-service ./mvnw -B -q test -Dtest='MatcherTest,ArchitectureTest'`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

```bash
docker compose exec reconciliation-service ./mvnw -B spotless:apply
make recon-test
git add services/reconciliation-service
git commit -m "feat(reconciliation-service): match bank lines to ledger entries by reference, then uniquely by amount and date"
```

---

### Task 4: `ReconciliationRun` aggregate

**Files:**
- Create: `domain/run/{ReconciliationRun,ReconItem,RunStatus,Resolution,ResolutionKind,RunRepository}.java`
- Create: `domain/run/{RunNotFoundException,ItemNotFoundException,ItemAlreadyResolvedException,InvalidManualMatchException,InvalidResolutionException,DuplicateStatementException,ConcurrentUpdateException}.java`
- Test: `domain/run/ReconciliationRunTest.java`

**Interfaces:**
- Consumes: Task 2 and Task 3 types.
- Produces:
  - `enum RunStatus { OPEN, RECONCILED }`, `enum ResolutionKind { IGNORE, MANUAL_MATCH }`
  - `record Resolution(ResolutionKind kind, String note, UUID counterpartItemId, Instant resolvedAt)`
  - `final class ReconItem` — constructor `(UUID id, int position, ItemKind kind, MatchMethod matchMethod, BankLine bankLine, LedgerEntry ledgerEntry, List<LedgerEntry> candidates, Resolution resolution)`; accessors `id() position() kind() matchMethod() bankLine() ledgerEntry() candidates() resolution()`; `boolean isUnresolvedException()`
  - `final class ReconciliationRun`:
    - `static ReconciliationRun start(UUID id, SupportedCurrency currency, Period period, String statementSha256, List<MatchOutcome> outcomes, Supplier<UUID> itemIds, Instant now)`
    - `static ReconciliationRun restore(UUID id, SupportedCurrency currency, Period period, String statementSha256, RunStatus status, Instant createdAt, long version, List<ReconItem> items)`
    - `void ignore(UUID itemId, String note, Instant now)`, `void manualMatch(UUID itemId, UUID counterpartItemId, String note, Instant now)` (either item may be the ledger side)
    - accessors `id() currency() period() statementSha256() status() createdAt() version() items()`, `Map<ItemKind, Long> counts()` (every kind present), `long unresolvedExceptions()`
  - `interface RunRepository { void save(ReconciliationRun); Optional<ReconciliationRun> findById(UUID); Optional<UUID> findIdByStatement(SupportedCurrency, Period, String sha256); List<ReconciliationRun> list(SupportedCurrency currencyOrNull, RunStatus statusOrNull); }`
  - Errors (all `DomainException`): `RunNotFoundException(UUID)` `run-not-found`; `ItemNotFoundException(UUID)` `item-not-found`; `ItemAlreadyResolvedException(UUID)` `item-already-resolved`; `InvalidManualMatchException(String)` `invalid-manual-match`; `InvalidResolutionException(String)` `validation-failed`; `DuplicateStatementException(UUID existingRunId)` `duplicate-statement` with `existingRunId()`; `ConcurrentUpdateException(UUID runId)` `concurrent-update`

- [ ] **Step 1: Write the failing test**

`src/test/java/com/ledgerplatform/reconciliation/domain/run/ReconciliationRunTest.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

import static com.ledgerplatform.reconciliation.domain.money.Direction.IN;
import static com.ledgerplatform.reconciliation.domain.money.Direction.OUT;
import static com.ledgerplatform.reconciliation.support.Fixtures.bank;
import static com.ledgerplatform.reconciliation.support.Fixtures.ledger;
import static com.ledgerplatform.reconciliation.support.Fixtures.march;
import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.matching.MatchMethod;
import com.ledgerplatform.reconciliation.domain.matching.MatchOutcome;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import java.time.Instant;
import java.util.List;
import java.util.UUID;
import java.util.concurrent.atomic.AtomicLong;
import java.util.function.Supplier;
import org.junit.jupiter.api.Test;

class ReconciliationRunTest {

    private static final Instant T0 = Instant.parse("2026-04-01T09:00:00Z");
    private static final Instant T1 = Instant.parse("2026-04-01T10:00:00Z");
    private static final String A = "0199a000-0000-7000-8000-00000000000a";
    private static final String B = "0199a000-0000-7000-8000-00000000000b";
    private static final String SHA = "a".repeat(64);

    private static Supplier<UUID> ids() {
        AtomicLong next = new AtomicLong(1);
        return () -> new UUID(0x0199a00000007000L, 0x8000000000000000L + next.getAndIncrement());
    }

    private static ReconciliationRun start(MatchOutcome... outcomes) {
        return ReconciliationRun.start(
                UUID.fromString("0199aaaa-0000-7000-8000-000000000001"),
                SupportedCurrency.USD,
                march(),
                SHA,
                List.of(outcomes),
                ids(),
                T0);
    }

    private static UUID itemId(ReconciliationRun run, ItemKind kind) {
        return run.items().stream()
                .filter(item -> item.kind() == kind)
                .findFirst()
                .orElseThrow()
                .id();
    }

    private final BankLine inTen = bank(1, "2026-03-05", IN, "10.00", null);
    private final LedgerEntry ledgerInTen = ledger(A, "2026-03-20T10:00:00Z", IN, "10.00");
    private final LedgerEntry ledgerInNine = ledger(B, "2026-03-20T10:00:00Z", IN, "9.00");

    @Test
    void aRunWithoutExceptionsStartsReconciled() {
        ReconciliationRun run = start(MatchOutcome.matched(MatchMethod.FUZZY, inTen, ledgerInTen));

        assertThat(run.status()).isEqualTo(RunStatus.RECONCILED);
        assertThat(run.unresolvedExceptions()).isZero();
        assertThat(run.createdAt()).isEqualTo(T0);
        assertThat(run.version()).isZero();
        assertThat(run.items()).singleElement().satisfies(item -> {
            assertThat(item.position()).isZero();
            assertThat(item.matchMethod()).isEqualTo(MatchMethod.FUZZY);
        });
    }

    @Test
    void countsEveryKindAndStartsOpenWithExceptions() {
        ReconciliationRun run = start(MatchOutcome.missingInLedger(inTen), MatchOutcome.missingInBank(ledgerInTen));

        assertThat(run.status()).isEqualTo(RunStatus.OPEN);
        assertThat(run.unresolvedExceptions()).isEqualTo(2);
        assertThat(run.counts())
                .containsEntry(ItemKind.MISSING_IN_LEDGER, 1L)
                .containsEntry(ItemKind.MISSING_IN_BANK, 1L)
                .containsEntry(ItemKind.MATCHED, 0L)
                .hasSize(ItemKind.values().length);
        assertThat(run.items()).extracting(ReconItem::position).containsExactly(0, 1);
    }

    @Test
    void ignoringTheLastExceptionReconcilesTheRun() {
        ReconciliationRun run = start(MatchOutcome.missingInLedger(inTen));
        UUID item = itemId(run, ItemKind.MISSING_IN_LEDGER);

        run.ignore(item, "  bank fee, booked manually  ", T1);

        assertThat(run.status()).isEqualTo(RunStatus.RECONCILED);
        assertThat(run.items().get(0).resolution())
                .isEqualTo(new Resolution(ResolutionKind.IGNORE, "bank fee, booked manually", null, T1));
    }

    @Test
    void ignoringRequiresANoteOfAtMost500Characters() {
        ReconciliationRun run = start(MatchOutcome.missingInLedger(inTen));
        UUID item = itemId(run, ItemKind.MISSING_IN_LEDGER);

        assertThatThrownBy(() -> run.ignore(item, null, T1)).isInstanceOf(InvalidResolutionException.class);
        assertThatThrownBy(() -> run.ignore(item, "   ", T1)).isInstanceOf(InvalidResolutionException.class);
        assertThatThrownBy(() -> run.ignore(item, "x".repeat(501), T1))
                .isInstanceOf(InvalidResolutionException.class)
                .extracting(e -> ((InvalidResolutionException) e).code())
                .isEqualTo("validation-failed");
        assertThat(run.status()).isEqualTo(RunStatus.OPEN);
    }

    @Test
    void manualMatchPairsBothSidesInEitherOrder() {
        ReconciliationRun run = start(MatchOutcome.missingInLedger(inTen), MatchOutcome.missingInBank(ledgerInTen));
        UUID bankSide = itemId(run, ItemKind.MISSING_IN_LEDGER);
        UUID ledgerSide = itemId(run, ItemKind.MISSING_IN_BANK);

        run.manualMatch(ledgerSide, bankSide, null, T1);

        assertThat(run.status()).isEqualTo(RunStatus.RECONCILED);
        assertThat(run.items())
                .extracting(ReconItem::resolution)
                .containsExactly(
                        new Resolution(ResolutionKind.MANUAL_MATCH, null, ledgerSide, T1),
                        new Resolution(ResolutionKind.MANUAL_MATCH, null, bankSide, T1));
    }

    @Test
    void manualMatchOfAnAmbiguousLineWithOneOfItsCandidates() {
        ReconciliationRun run = start(
                MatchOutcome.ambiguous(inTen, List.of(ledgerInTen)), MatchOutcome.missingInBank(ledgerInTen));

        run.manualMatch(itemId(run, ItemKind.AMBIGUOUS), itemId(run, ItemKind.MISSING_IN_BANK), "confirmed", T1);

        assertThat(run.status()).isEqualTo(RunStatus.RECONCILED);
    }

    @Test
    void manualMatchWithDifferentAmountsNeedsANote() {
        ReconciliationRun run = start(MatchOutcome.missingInLedger(inTen), MatchOutcome.missingInBank(ledgerInNine));
        UUID bankSide = itemId(run, ItemKind.MISSING_IN_LEDGER);
        UUID ledgerSide = itemId(run, ItemKind.MISSING_IN_BANK);

        assertThatThrownBy(() -> run.manualMatch(bankSide, ledgerSide, " ", T1))
                .isInstanceOf(InvalidManualMatchException.class)
                .hasMessageContaining("note");

        run.manualMatch(bankSide, ledgerSide, "1.00 bank fee deducted", T1);
        assertThat(run.status()).isEqualTo(RunStatus.RECONCILED);
    }

    @Test
    void manualMatchRejectsWrongKindsDirectionsAndSelfMatches() {
        BankLine outTen = bank(2, "2026-03-06", OUT, "10.00", null);
        ReconciliationRun run = start(
                MatchOutcome.missingInLedger(inTen),
                MatchOutcome.missingInLedger(outTen),
                MatchOutcome.missingInBank(ledgerInTen));
        UUID bankIn = run.items().get(0).id();
        UUID bankOut = run.items().get(1).id();
        UUID ledgerIn = run.items().get(2).id();

        assertThatThrownBy(() -> run.manualMatch(bankIn, bankOut, "x", T1))
                .isInstanceOf(InvalidManualMatchException.class);
        assertThatThrownBy(() -> run.manualMatch(bankOut, ledgerIn, "x", T1))
                .isInstanceOf(InvalidManualMatchException.class)
                .hasMessageContaining("direction");
        assertThatThrownBy(() -> run.manualMatch(ledgerIn, ledgerIn, "x", T1))
                .isInstanceOf(InvalidManualMatchException.class);
        assertThatThrownBy(() -> run.manualMatch(bankIn, null, "x", T1))
                .isInstanceOf(InvalidManualMatchException.class);
        assertThat(run.unresolvedExceptions()).isEqualTo(3);
    }

    @Test
    void resolvedAndMatchedItemsCannotBeResolvedAgain() {
        ReconciliationRun run = start(
                MatchOutcome.matched(MatchMethod.REFERENCE, inTen, ledgerInTen),
                MatchOutcome.missingInBank(ledgerInNine));
        UUID matched = itemId(run, ItemKind.MATCHED);
        UUID missing = itemId(run, ItemKind.MISSING_IN_BANK);
        run.ignore(missing, "reversed next day", T1);

        assertThatThrownBy(() -> run.ignore(missing, "again", T1))
                .isInstanceOf(ItemAlreadyResolvedException.class)
                .extracting(e -> ((ItemAlreadyResolvedException) e).code())
                .isEqualTo("item-already-resolved");
        assertThatThrownBy(() -> run.ignore(matched, "why", T1)).isInstanceOf(ItemAlreadyResolvedException.class);
    }

    @Test
    void unknownItemsAreNotFound() {
        ReconciliationRun run = start(MatchOutcome.missingInLedger(inTen));
        UUID unknown = UUID.fromString("0199ffff-0000-7000-8000-000000000000");

        assertThatThrownBy(() -> run.ignore(unknown, "x", T1))
                .isInstanceOf(ItemNotFoundException.class)
                .extracting(e -> ((ItemNotFoundException) e).code())
                .isEqualTo("item-not-found");
    }

    @Test
    void restoreKeepsStateAndVersion() {
        ReconciliationRun started = start(MatchOutcome.missingInLedger(inTen));
        ReconItem item = started.items().get(0);
        ReconItem resolved = new ReconItem(
                item.id(),
                0,
                ItemKind.MISSING_IN_LEDGER,
                null,
                inTen,
                null,
                List.of(),
                new Resolution(ResolutionKind.IGNORE, "note", null, T1));

        ReconciliationRun restored = ReconciliationRun.restore(
                started.id(), SupportedCurrency.USD, march(), SHA, RunStatus.RECONCILED, T0, 3, List.of(resolved));

        assertThat(restored.version()).isEqualTo(3);
        assertThat(restored.status()).isEqualTo(RunStatus.RECONCILED);
        assertThat(restored.items().get(0).isUnresolvedException()).isFalse();
    }

    @Test
    void itemsCannotBeModifiedFromOutside() {
        ReconciliationRun run = start(MatchOutcome.missingInLedger(inTen));

        assertThatThrownBy(() -> run.items().clear()).isInstanceOf(UnsupportedOperationException.class);
    }

    @Test
    void errorsCarryTheirCodes() {
        UUID id = UUID.fromString("0199ffff-0000-7000-8000-000000000000");

        assertThat(new RunNotFoundException(id).code()).isEqualTo("run-not-found");
        assertThat(new DuplicateStatementException(id).code()).isEqualTo("duplicate-statement");
        assertThat(new DuplicateStatementException(id).existingRunId()).isEqualTo(id);
        assertThat(new ConcurrentUpdateException(id).code()).isEqualTo("concurrent-update");
        assertThat(new InvalidManualMatchException("x").code()).isEqualTo("invalid-manual-match");
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run: `docker compose exec reconciliation-service ./mvnw -B -q test -Dtest=ReconciliationRunTest`
Expected: compilation FAILS (`ReconciliationRun` does not exist).

- [ ] **Step 3: Implement**

`domain/run/RunStatus.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

public enum RunStatus {
    OPEN,
    RECONCILED
}
```

`domain/run/ResolutionKind.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

public enum ResolutionKind {
    IGNORE,
    MANUAL_MATCH
}
```

`domain/run/Resolution.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

import java.time.Instant;
import java.util.Objects;
import java.util.UUID;

/** How an operator settled an exception. {@code note} and {@code counterpartItemId} may be null. */
public record Resolution(ResolutionKind kind, String note, UUID counterpartItemId, Instant resolvedAt) {

    public Resolution {
        Objects.requireNonNull(kind, "kind");
        Objects.requireNonNull(resolvedAt, "resolvedAt");
    }
}
```

`domain/run/ReconItem.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.matching.MatchMethod;
import com.ledgerplatform.reconciliation.domain.matching.MatchOutcome;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import java.util.List;
import java.util.Objects;
import java.util.UUID;

/** One line of a run's result. Only its resolution changes, and only through {@link ReconciliationRun}. */
public final class ReconItem {

    private final UUID id;
    private final int position;
    private final ItemKind kind;
    private final MatchMethod matchMethod;
    private final BankLine bankLine;
    private final LedgerEntry ledgerEntry;
    private final List<LedgerEntry> candidates;
    private Resolution resolution;

    public ReconItem(
            UUID id,
            int position,
            ItemKind kind,
            MatchMethod matchMethod,
            BankLine bankLine,
            LedgerEntry ledgerEntry,
            List<LedgerEntry> candidates,
            Resolution resolution) {
        this.id = Objects.requireNonNull(id, "id");
        this.position = position;
        this.kind = Objects.requireNonNull(kind, "kind");
        this.matchMethod = matchMethod;
        this.bankLine = bankLine;
        this.ledgerEntry = ledgerEntry;
        this.candidates = List.copyOf(candidates);
        this.resolution = resolution;
    }

    static ReconItem from(UUID id, int position, MatchOutcome outcome) {
        return new ReconItem(
                id,
                position,
                outcome.kind(),
                outcome.method(),
                outcome.bankLine(),
                outcome.ledgerEntry(),
                outcome.candidates(),
                null);
    }

    public UUID id() {
        return id;
    }

    public int position() {
        return position;
    }

    public ItemKind kind() {
        return kind;
    }

    public MatchMethod matchMethod() {
        return matchMethod;
    }

    public BankLine bankLine() {
        return bankLine;
    }

    public LedgerEntry ledgerEntry() {
        return ledgerEntry;
    }

    public List<LedgerEntry> candidates() {
        return candidates;
    }

    public Resolution resolution() {
        return resolution;
    }

    public boolean isUnresolvedException() {
        return kind.isException() && resolution == null;
    }

    void resolve(Resolution value) {
        this.resolution = value;
    }
}
```

`domain/run/ReconciliationRun.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.matching.MatchOutcome;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.time.Instant;
import java.util.ArrayList;
import java.util.Collections;
import java.util.EnumMap;
import java.util.List;
import java.util.Map;
import java.util.Objects;
import java.util.UUID;
import java.util.function.Supplier;

/** Aggregate root: one reconciliation of a bank statement against the ledger, and its resolutions. */
public final class ReconciliationRun {

    private static final int MAX_NOTE_LENGTH = 500;

    private final UUID id;
    private final SupportedCurrency currency;
    private final Period period;
    private final String statementSha256;
    private final Instant createdAt;
    private final long version;
    private final List<ReconItem> items;
    private RunStatus status;

    private ReconciliationRun(
            UUID id,
            SupportedCurrency currency,
            Period period,
            String statementSha256,
            RunStatus status,
            Instant createdAt,
            long version,
            List<ReconItem> items) {
        this.id = Objects.requireNonNull(id, "id");
        this.currency = Objects.requireNonNull(currency, "currency");
        this.period = Objects.requireNonNull(period, "period");
        this.statementSha256 = Objects.requireNonNull(statementSha256, "statementSha256");
        this.status = Objects.requireNonNull(status, "status");
        this.createdAt = Objects.requireNonNull(createdAt, "createdAt");
        this.version = version;
        this.items = List.copyOf(items);
    }

    public static ReconciliationRun start(
            UUID id,
            SupportedCurrency currency,
            Period period,
            String statementSha256,
            List<MatchOutcome> outcomes,
            Supplier<UUID> itemIds,
            Instant now) {
        List<ReconItem> items = new ArrayList<>();
        for (int position = 0; position < outcomes.size(); position++) {
            items.add(ReconItem.from(itemIds.get(), position, outcomes.get(position)));
        }
        ReconciliationRun run =
                new ReconciliationRun(id, currency, period, statementSha256, RunStatus.OPEN, now, 0, items);
        run.refreshStatus();
        return run;
    }

    public static ReconciliationRun restore(
            UUID id,
            SupportedCurrency currency,
            Period period,
            String statementSha256,
            RunStatus status,
            Instant createdAt,
            long version,
            List<ReconItem> items) {
        return new ReconciliationRun(id, currency, period, statementSha256, status, createdAt, version, items);
    }

    /** Accepts an exception as is. A note explaining why is required. */
    public void ignore(UUID itemId, String note, Instant now) {
        ReconItem item = unresolvedException(itemId);
        String text = optionalNote(note);
        if (text == null) {
            throw new InvalidResolutionException("A note is required to ignore an item.");
        }
        item.resolve(new Resolution(ResolutionKind.IGNORE, text, null, now));
        refreshStatus();
    }

    /**
     * Pairs a bank-side exception ({@code MISSING_IN_LEDGER} or {@code AMBIGUOUS}) with a {@code MISSING_IN_BANK}
     * item, in either argument order. Different amounts are allowed only with a note.
     */
    public void manualMatch(UUID itemId, UUID counterpartItemId, String note, Instant now) {
        if (counterpartItemId == null) {
            throw new InvalidManualMatchException("A manual match needs counterpartItemId.");
        }
        ReconItem first = unresolvedException(itemId);
        ReconItem second = unresolvedException(counterpartItemId);
        ReconItem ledgerSide = first.kind() == ItemKind.MISSING_IN_BANK ? first : second;
        ReconItem bankSide = ledgerSide == first ? second : first;
        boolean bankKindOk = bankSide.kind() == ItemKind.MISSING_IN_LEDGER || bankSide.kind() == ItemKind.AMBIGUOUS;
        if (first == second || ledgerSide.kind() != ItemKind.MISSING_IN_BANK || !bankKindOk) {
            throw new InvalidManualMatchException(
                    "A manual match pairs one MISSING_IN_LEDGER or AMBIGUOUS item with one MISSING_IN_BANK item.");
        }
        if (bankSide.bankLine().direction() != ledgerSide.ledgerEntry().direction()) {
            throw new InvalidManualMatchException("Both items must move money in the same direction.");
        }
        String text = optionalNote(note);
        if (!bankSide.bankLine().amount().equals(ledgerSide.ledgerEntry().amount()) && text == null) {
            throw new InvalidManualMatchException("The amounts differ; explain the match in a note.");
        }
        bankSide.resolve(new Resolution(ResolutionKind.MANUAL_MATCH, text, ledgerSide.id(), now));
        ledgerSide.resolve(new Resolution(ResolutionKind.MANUAL_MATCH, text, bankSide.id(), now));
        refreshStatus();
    }

    public Map<ItemKind, Long> counts() {
        Map<ItemKind, Long> counts = new EnumMap<>(ItemKind.class);
        for (ItemKind kind : ItemKind.values()) {
            counts.put(kind, 0L);
        }
        for (ReconItem item : items) {
            counts.merge(item.kind(), 1L, Long::sum);
        }
        return Collections.unmodifiableMap(counts);
    }

    public long unresolvedExceptions() {
        return items.stream().filter(ReconItem::isUnresolvedException).count();
    }

    public UUID id() {
        return id;
    }

    public SupportedCurrency currency() {
        return currency;
    }

    public Period period() {
        return period;
    }

    public String statementSha256() {
        return statementSha256;
    }

    public RunStatus status() {
        return status;
    }

    public Instant createdAt() {
        return createdAt;
    }

    /** Optimistic-locking version as loaded; 0 for a run that was never saved. */
    public long version() {
        return version;
    }

    public List<ReconItem> items() {
        return items;
    }

    private ReconItem unresolvedException(UUID itemId) {
        ReconItem item = items.stream()
                .filter(candidate -> candidate.id().equals(itemId))
                .findFirst()
                .orElseThrow(() -> new ItemNotFoundException(itemId));
        if (!item.isUnresolvedException()) {
            throw new ItemAlreadyResolvedException(itemId);
        }
        return item;
    }

    private static String optionalNote(String note) {
        if (note == null || note.isBlank()) {
            return null;
        }
        String text = note.strip();
        if (text.length() > MAX_NOTE_LENGTH) {
            throw new InvalidResolutionException("A note may be at most %d characters.".formatted(MAX_NOTE_LENGTH));
        }
        return text;
    }

    private void refreshStatus() {
        status = unresolvedExceptions() == 0 ? RunStatus.RECONCILED : RunStatus.OPEN;
    }
}
```

`domain/run/RunRepository.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface RunRepository {

    /**
     * Inserts a new run, or stores the status and resolutions of an existing one.
     *
     * @throws DuplicateStatementException when the same statement already has a run for the currency and period
     * @throws ConcurrentUpdateException when the run changed since it was loaded
     */
    void save(ReconciliationRun run);

    Optional<ReconciliationRun> findById(UUID id);

    Optional<UUID> findIdByStatement(SupportedCurrency currency, Period period, String statementSha256);

    /** Newest first, at most 50. A null filter matches everything. */
    List<ReconciliationRun> list(SupportedCurrency currency, RunStatus status);
}
```

The seven error classes follow one shape. `domain/run/RunNotFoundException.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;
import java.util.UUID;

public final class RunNotFoundException extends DomainException {

    public RunNotFoundException(UUID id) {
        super("Reconciliation run %s does not exist.".formatted(id));
    }

    @Override
    public String code() {
        return "run-not-found";
    }
}
```

`domain/run/ItemNotFoundException.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;
import java.util.UUID;

public final class ItemNotFoundException extends DomainException {

    public ItemNotFoundException(UUID id) {
        super("Item %s is not part of this run.".formatted(id));
    }

    @Override
    public String code() {
        return "item-not-found";
    }
}
```

`domain/run/ItemAlreadyResolvedException.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;
import java.util.UUID;

public final class ItemAlreadyResolvedException extends DomainException {

    public ItemAlreadyResolvedException(UUID id) {
        super("Item %s is not an open exception.".formatted(id));
    }

    @Override
    public String code() {
        return "item-already-resolved";
    }
}
```

`domain/run/InvalidManualMatchException.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;

public final class InvalidManualMatchException extends DomainException {

    public InvalidManualMatchException(String message) {
        super(message);
    }

    @Override
    public String code() {
        return "invalid-manual-match";
    }
}
```

`domain/run/InvalidResolutionException.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;

public final class InvalidResolutionException extends DomainException {

    public InvalidResolutionException(String message) {
        super(message);
    }

    @Override
    public String code() {
        return "validation-failed";
    }
}
```

`domain/run/DuplicateStatementException.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;
import java.util.UUID;

public final class DuplicateStatementException extends DomainException {

    private final UUID existingRunId;

    public DuplicateStatementException(UUID existingRunId) {
        super("This statement was already reconciled for the same currency and period.");
        this.existingRunId = existingRunId;
    }

    public UUID existingRunId() {
        return existingRunId;
    }

    @Override
    public String code() {
        return "duplicate-statement";
    }
}
```

`domain/run/ConcurrentUpdateException.java`:

```java
package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;
import java.util.UUID;

public final class ConcurrentUpdateException extends DomainException {

    public ConcurrentUpdateException(UUID runId) {
        super("Run %s was changed by someone else; reload it and try again.".formatted(runId));
    }

    @Override
    public String code() {
        return "concurrent-update";
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec reconciliation-service ./mvnw -B -q test -Dtest='ReconciliationRunTest,ArchitectureTest'`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

```bash
docker compose exec reconciliation-service ./mvnw -B spotless:apply
make recon-test
git add services/reconciliation-service
git commit -m "feat(reconciliation-service): add the reconciliation run aggregate with manual resolution"
```

---

### Task 5: Bank statement CSV parser

**Files:**
- Create: `application/port/{StatementParser,RowError,InvalidStatementException}.java`
- Create: `infrastructure/csv/BankStatementCsvParser.java`
- Test: `infrastructure/csv/BankStatementCsvParserTest.java`

**Interfaces:**
- Consumes: Task 2 types.
- Produces:
  - `interface StatementParser { List<BankLine> parse(byte[] content, SupportedCurrency currency, Period period); }` — throws `InvalidStatementException` with every error
  - `record RowError(Integer row, String field, String message)` (`row`/`field` null for file-level problems)
  - `class InvalidStatementException extends RuntimeException { List<RowError> errors(); }`
  - `final class BankStatementCsvParser implements StatementParser` with `static final List<String> HEADER` and `static final int MAX_ROWS = 10_000`

- [ ] **Step 1: Write the failing test**

`src/test/java/com/ledgerplatform/reconciliation/infrastructure/csv/BankStatementCsvParserTest.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.csv;

import static com.ledgerplatform.reconciliation.support.Fixtures.march;
import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ledgerplatform.reconciliation.application.port.InvalidStatementException;
import com.ledgerplatform.reconciliation.application.port.RowError;
import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import java.nio.charset.Charset;
import java.nio.charset.StandardCharsets;
import java.time.LocalDate;
import java.util.List;
import org.junit.jupiter.api.Test;

class BankStatementCsvParserTest {

    private static final String HEADER = "date,direction,description,reference,currency,amount\n";

    private final BankStatementCsvParser parser = new BankStatementCsvParser();

    private List<BankLine> parse(String csv) {
        return parser.parse(csv.getBytes(StandardCharsets.UTF_8), SupportedCurrency.USD, march());
    }

    private List<RowError> errorsOf(String csv) {
        try {
            parse(csv);
        } catch (InvalidStatementException e) {
            return e.errors();
        }
        throw new AssertionError("expected InvalidStatementException");
    }

    @Test
    void parsesRowsInFileOrder() {
        List<BankLine> lines = parse(HEADER
                + "2026-03-05,CR,Salary,0199a111-2222-7333-8444-555566667777,USD,100.00\n"
                + "2026-03-06,DR,,,USD,5\n");

        assertThat(lines)
                .containsExactly(
                        new BankLine(
                                1,
                                LocalDate.parse("2026-03-05"),
                                Direction.IN,
                                "Salary",
                                "0199a111-2222-7333-8444-555566667777",
                                new Money(10000, SupportedCurrency.USD)),
                        new BankLine(
                                2,
                                LocalDate.parse("2026-03-06"),
                                Direction.OUT,
                                null,
                                null,
                                new Money(500, SupportedCurrency.USD)));
    }

    @Test
    void handlesQuotingBomCrlfBlankLinesAndSurroundingSpaces() {
        String csv = "﻿" + HEADER.replace("\n", "\r\n")
                + "\r\n"
                + " 2026-03-05 , CR ,\"Rent, March \"\"flat 2\"\"\nsecond line\", REF-1 , USD , 10.00 \r\n";

        List<BankLine> lines = parse(csv);

        assertThat(lines).singleElement().satisfies(line -> {
            assertThat(line.row()).isEqualTo(1);
            assertThat(line.description()).isEqualTo("Rent, March \"flat 2\"\nsecond line");
            assertThat(line.reference()).isEqualTo("REF-1");
            assertThat(line.amount()).isEqualTo(new Money(1000, SupportedCurrency.USD));
        });
    }

    @Test
    void reportsEveryRowErrorWithItsRowAndField() {
        List<RowError> errors = errorsOf(HEADER
                + "2026-02-28,CR,,,USD,1.00\n"
                + "2026-03-31,XX,,,EUR,1.001\n"
                + "03/05/2026,DR,,,USD,-1\n"
                + "2026-03-05,CR,,,USD\n"
                + "2026-03-05,CR," + "d".repeat(256) + "," + "r".repeat(65) + ",USD,1.00\n");

        assertThat(errors)
                .extracting(RowError::row, RowError::field)
                .containsExactly(
                        org.assertj.core.groups.Tuple.tuple(1, "date"),
                        org.assertj.core.groups.Tuple.tuple(2, "direction"),
                        org.assertj.core.groups.Tuple.tuple(2, "currency"),
                        org.assertj.core.groups.Tuple.tuple(2, "amount"),
                        org.assertj.core.groups.Tuple.tuple(3, "date"),
                        org.assertj.core.groups.Tuple.tuple(3, "amount"),
                        org.assertj.core.groups.Tuple.tuple(4, null),
                        org.assertj.core.groups.Tuple.tuple(5, "description"),
                        org.assertj.core.groups.Tuple.tuple(5, "reference"));
        assertThat(errors.get(0).message()).contains("outside the period");
        assertThat(errors.get(6).message()).isEqualTo("Expected 6 columns, found 5.");
    }

    @Test
    void rejectsAWrongOrMissingHeader() {
        assertThat(errorsOf("date,amount,currency,direction,reference,description\n2026-03-05,1.00,USD,CR,,\n"))
                .singleElement()
                .satisfies(error -> {
                    assertThat(error.row()).isNull();
                    assertThat(error.message()).contains("date,direction,description,reference,currency,amount");
                });
        assertThat(errorsOf("")).singleElement().satisfies(error -> assertThat(error.message())
                .contains("empty"));
    }

    @Test
    void requiresAtLeastOneDataRow() {
        assertThat(errorsOf(HEADER + "\n\n"))
                .singleElement()
                .satisfies(error -> assertThat(error.message()).contains("no data rows"));
    }

    @Test
    void rejectsMoreThanTheMaximumNumberOfRows() {
        StringBuilder csv = new StringBuilder(HEADER);
        for (int i = 0; i <= BankStatementCsvParser.MAX_ROWS; i++) {
            csv.append("2026-03-05,CR,,,USD,1.00\n");
        }

        assertThat(errorsOf(csv.toString()))
                .singleElement()
                .satisfies(error -> assertThat(error.message()).contains("10000"));
    }

    @Test
    void rejectsNonUtf8Files() {
        byte[] latin1 = (HEADER + "2026-03-05,CR,Café,,USD,1.00\n").getBytes(Charset.forName("windows-1252"));

        assertThatThrownBy(() -> parser.parse(latin1, SupportedCurrency.USD, march()))
                .isInstanceOf(InvalidStatementException.class)
                .satisfies(e -> assertThat(((InvalidStatementException) e).errors())
                        .singleElement()
                        .satisfies(error -> assertThat(error.message()).contains("UTF-8")));
    }

    @Test
    void rejectsUnbalancedQuotes() {
        assertThat(errorsOf(HEADER + "2026-03-05,CR,\"never closed,,USD,1.00\n"))
                .singleElement()
                .satisfies(error -> assertThat(error.message()).contains("not valid CSV"));
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run: `docker compose exec reconciliation-service ./mvnw -B -q test -Dtest=BankStatementCsvParserTest`
Expected: compilation FAILS (`BankStatementCsvParser` does not exist).

- [ ] **Step 3: Implement**

`application/port/RowError.java`:

```java
package com.ledgerplatform.reconciliation.application.port;

/** A problem in the uploaded statement. {@code row} (1-based data row) and {@code field} are null for file-level problems. */
public record RowError(Integer row, String field, String message) {}
```

`application/port/InvalidStatementException.java`:

```java
package com.ledgerplatform.reconciliation.application.port;

import java.util.List;

public class InvalidStatementException extends RuntimeException {

    private final transient List<RowError> errors;

    public InvalidStatementException(List<RowError> errors) {
        super("The statement is invalid (%d problem(s)).".formatted(errors.size()));
        this.errors = List.copyOf(errors);
    }

    public List<RowError> errors() {
        return errors;
    }
}
```

`application/port/StatementParser.java`:

```java
package com.ledgerplatform.reconciliation.application.port;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.util.List;

public interface StatementParser {

    /**
     * Parses the whole statement or nothing.
     *
     * @throws InvalidStatementException listing every problem found
     */
    List<BankLine> parse(byte[] content, SupportedCurrency currency, Period period);
}
```

`infrastructure/csv/BankStatementCsvParser.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.csv;

import com.ledgerplatform.reconciliation.application.port.InvalidStatementException;
import com.ledgerplatform.reconciliation.application.port.RowError;
import com.ledgerplatform.reconciliation.application.port.StatementParser;
import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.InvalidMoneyException;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.io.IOException;
import java.io.StringReader;
import java.io.UncheckedIOException;
import java.nio.ByteBuffer;
import java.nio.charset.CharacterCodingException;
import java.nio.charset.CodingErrorAction;
import java.nio.charset.StandardCharsets;
import java.time.LocalDate;
import java.time.format.DateTimeParseException;
import java.util.ArrayList;
import java.util.Iterator;
import java.util.List;
import java.util.Optional;
import org.apache.commons.csv.CSVFormat;
import org.apache.commons.csv.CSVParser;
import org.apache.commons.csv.CSVRecord;

/** Parses the statement format of spec §5.2: UTF-8, RFC 4180, a fixed header, all-or-nothing. */
public final class BankStatementCsvParser implements StatementParser {

    static final List<String> HEADER = List.of("date", "direction", "description", "reference", "currency", "amount");
    static final int MAX_ROWS = 10_000;
    private static final int MAX_DESCRIPTION = 255;
    private static final int MAX_REFERENCE = 64;
    private static final CSVFormat FORMAT =
            CSVFormat.RFC4180.builder().setIgnoreEmptyLines(true).get();

    @Override
    public List<BankLine> parse(byte[] content, SupportedCurrency currency, Period period) {
        String text = decode(content);
        List<RowError> errors = new ArrayList<>();
        List<BankLine> lines = new ArrayList<>();
        try (CSVParser csv = FORMAT.parse(new StringReader(text))) {
            Iterator<CSVRecord> records = csv.iterator();
            if (!records.hasNext()) {
                throw invalid("The file is empty; the first line must be: " + String.join(",", HEADER));
            }
            List<String> header =
                    records.next().toList().stream().map(String::strip).toList();
            if (!HEADER.equals(header)) {
                throw invalid("The first line must be exactly: " + String.join(",", HEADER));
            }
            while (records.hasNext()) {
                CSVRecord record = records.next();
                int row = (int) record.getRecordNumber() - 1;
                if (row > MAX_ROWS) {
                    errors.add(new RowError(null, null, "A statement may have at most %d data rows.".formatted(MAX_ROWS)));
                    break;
                }
                parseRow(record, row, currency, period, errors).ifPresent(lines::add);
            }
        } catch (IOException | UncheckedIOException | IllegalStateException e) {
            errors.add(new RowError(null, null, "The file is not valid CSV: " + e.getMessage()));
        }
        if (errors.isEmpty() && lines.isEmpty()) {
            errors.add(new RowError(null, null, "The statement has no data rows."));
        }
        if (!errors.isEmpty()) {
            throw new InvalidStatementException(errors);
        }
        return List.copyOf(lines);
    }

    private static Optional<BankLine> parseRow(
            CSVRecord record, int row, SupportedCurrency currency, Period period, List<RowError> errors) {
        if (record.size() != HEADER.size()) {
            errors.add(new RowError(
                    row, null, "Expected %d columns, found %d.".formatted(HEADER.size(), record.size())));
            return Optional.empty();
        }
        int before = errors.size();
        LocalDate date = parseDate(record.get(0).strip(), row, period, errors);
        Direction direction = parseDirection(record.get(1).strip(), row, errors);
        String description = optionalText(record.get(2).strip(), "description", MAX_DESCRIPTION, row, errors);
        String reference = optionalText(record.get(3).strip(), "reference", MAX_REFERENCE, row, errors);
        String code = record.get(4).strip();
        if (!currency.name().equals(code)) {
            errors.add(new RowError(row, "currency", "Expected %s, found \"%s\".".formatted(currency, code)));
        }
        Money amount = parseAmount(record.get(5).strip(), currency, row, errors);
        if (errors.size() > before) {
            return Optional.empty();
        }
        return Optional.of(new BankLine(row, date, direction, description, reference, amount));
    }

    private static LocalDate parseDate(String value, int row, Period period, List<RowError> errors) {
        LocalDate date;
        try {
            date = LocalDate.parse(value);
        } catch (DateTimeParseException e) {
            errors.add(new RowError(row, "date", "Use the YYYY-MM-DD format."));
            return null;
        }
        if (!period.contains(date)) {
            errors.add(new RowError(
                    row, "date", "%s is outside the period %s to %s.".formatted(date, period.from(), period.to())));
            return null;
        }
        return date;
    }

    private static Direction parseDirection(String value, int row, List<RowError> errors) {
        return switch (value) {
            case "CR" -> Direction.IN;
            case "DR" -> Direction.OUT;
            default -> {
                errors.add(new RowError(row, "direction", "Use CR (money in) or DR (money out)."));
                yield null;
            }
        };
    }

    private static String optionalText(String value, String field, int max, int row, List<RowError> errors) {
        if (value.isEmpty()) {
            return null;
        }
        if (value.length() > max) {
            errors.add(new RowError(row, field, "At most %d characters.".formatted(max)));
            return null;
        }
        return value;
    }

    private static Money parseAmount(String value, SupportedCurrency currency, int row, List<RowError> errors) {
        try {
            return Money.parse(value, currency);
        } catch (InvalidMoneyException e) {
            errors.add(new RowError(row, "amount", e.getMessage()));
            return null;
        }
    }

    private static String decode(byte[] content) {
        int offset = content.length >= 3
                        && (content[0] & 0xFF) == 0xEF
                        && (content[1] & 0xFF) == 0xBB
                        && (content[2] & 0xFF) == 0xBF
                ? 3
                : 0;
        try {
            return StandardCharsets.UTF_8
                    .newDecoder()
                    .onMalformedInput(CodingErrorAction.REPORT)
                    .onUnmappableCharacter(CodingErrorAction.REPORT)
                    .decode(ByteBuffer.wrap(content, offset, content.length - offset))
                    .toString();
        } catch (CharacterCodingException e) {
            throw invalid("The file is not valid UTF-8.");
        }
    }

    private static InvalidStatementException invalid(String message) {
        return new InvalidStatementException(List.of(new RowError(null, null, message)));
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec reconciliation-service ./mvnw -B -q test -Dtest='BankStatementCsvParserTest,ArchitectureTest'`
Expected: PASS. If `rejectsUnbalancedQuotes` reports a different exception type from Commons CSV, add that type to the `catch` (it must end as a `RowError`, never escape).

- [ ] **Step 5: Gates and commit**

```bash
docker compose exec reconciliation-service ./mvnw -B spotless:apply
make recon-test
git add services/reconciliation-service
git commit -m "feat(reconciliation-service): parse bank statement CSVs, reporting every row error"
```

---

### Task 6: Ledger statement adapter

**Files:**
- Create: `application/port/{LedgerStatementPort,LedgerUnavailableException}.java`
- Create: `infrastructure/ledger/{SettlementAccounts,StatementResponse,LedgerHttpClient,LedgerHealthIndicator}.java`
- Test: `infrastructure/ledger/LedgerHttpClientTest.java`

**Interfaces:**
- Consumes: Task 2 types.
- Produces:
  - `interface LedgerStatementPort { List<LedgerEntry> fetch(SupportedCurrency currency, Period period); }` — throws `LedgerUnavailableException`
  - `class LedgerUnavailableException extends RuntimeException`
  - `final class LedgerHttpClient implements LedgerStatementPort` — constructor `(RestClient client)` where the client has the ledger base URL; `static final int MAX_DAYS_PER_REQUEST = 366`
  - `final class SettlementAccounts { static UUID forCurrency(SupportedCurrency) }`
  - `final class LedgerHealthIndicator implements HealthIndicator` — constructor `(RestClient client)`, calls `GET /health`

- [ ] **Step 1: Write the failing test**

`src/test/java/com/ledgerplatform/reconciliation/infrastructure/ledger/LedgerHttpClientTest.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.ledger;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.springframework.test.web.client.match.MockRestRequestMatchers.method;
import static org.springframework.test.web.client.match.MockRestRequestMatchers.requestTo;
import static org.springframework.test.web.client.response.MockRestResponseCreators.withResourceNotFound;
import static org.springframework.test.web.client.response.MockRestResponseCreators.withServerError;
import static org.springframework.test.web.client.response.MockRestResponseCreators.withSuccess;

import com.ledgerplatform.reconciliation.application.port.LedgerUnavailableException;
import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.io.IOException;
import java.time.Instant;
import java.time.LocalDate;
import java.util.List;
import java.util.UUID;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.boot.health.contributor.Status;
import org.springframework.http.HttpMethod;
import org.springframework.http.MediaType;
import org.springframework.test.web.client.MockRestServiceServer;
import org.springframework.web.client.RestClient;

class LedgerHttpClientTest {

    private static final String USD_ACCOUNT = "0199a000-0000-7000-8000-000000000840";
    private static final String BASE = "http://ledger.test";

    private MockRestServiceServer server;
    private LedgerHttpClient client;
    private RestClient restClient;

    @BeforeEach
    void setUp() {
        RestClient.Builder builder = RestClient.builder().baseUrl(BASE);
        server = MockRestServiceServer.bindTo(builder).build();
        restClient = builder.build();
        client = new LedgerHttpClient(restClient);
    }

    private static String statement(String currency, String lines) {
        return """
                {"accountId":"%s","currency":"%s","from":"2026-03-01","to":"2026-03-31",
                 "openingBalance":"0.00","closingBalance":"-95.00","lines":[%s]}"""
                .formatted(USD_ACCOUNT, currency, lines);
    }

    private static final String DEPOSIT = """
            {"postingId":"01a0de17-1e23-79a3-b479-1cb1d6981749","occurredAt":"2026-03-05T14:20:52.000+00:00",
             "description":"Salary","transferId":"01a0de17-1e23-785b-b479-1cb1d53637cd","direction":"debit",
             "amount":"100.00","balanceAfter":"-100.00"}""";
    private static final String WITHDRAWAL = """
            {"postingId":"01a0de17-1eb1-7d46-b808-3996db1eec83","occurredAt":"2026-03-06T08:00:00.000+00:00",
             "description":null,"transferId":"01a0de17-1eb1-7c2e-b808-3996d947888f","direction":"credit",
             "amount":"5.00","balanceAfter":"-95.00"}""";

    private static Period march() {
        return new Period(LocalDate.parse("2026-03-01"), LocalDate.parse("2026-03-31"));
    }

    @Test
    void mapsSettlementPostingsToLedgerEntries() {
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andExpect(method(HttpMethod.GET))
                .andRespond(withSuccess(statement("USD", DEPOSIT + "," + WITHDRAWAL), MediaType.APPLICATION_JSON));

        List<LedgerEntry> entries = client.fetch(SupportedCurrency.USD, march());

        assertThat(entries)
                .containsExactly(
                        new LedgerEntry(
                                UUID.fromString("01a0de17-1e23-785b-b479-1cb1d53637cd"),
                                Instant.parse("2026-03-05T14:20:52Z"),
                                Direction.IN,
                                new Money(10000, SupportedCurrency.USD),
                                "Salary"),
                        new LedgerEntry(
                                UUID.fromString("01a0de17-1eb1-7c2e-b808-3996d947888f"),
                                Instant.parse("2026-03-06T08:00:00Z"),
                                Direction.OUT,
                                new Money(500, SupportedCurrency.USD),
                                null));
        server.verify();
    }

    @Test
    void fetchesLongPeriodsInChunksTheLedgerAccepts() {
        Period widened = new Period(LocalDate.parse("2027-12-30"), LocalDate.parse("2029-01-02"));
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2027-12-30&to=2028-12-29"))
                .andRespond(withSuccess(statement("USD", DEPOSIT), MediaType.APPLICATION_JSON));
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2028-12-30&to=2029-01-02"))
                .andRespond(withSuccess(statement("USD", WITHDRAWAL), MediaType.APPLICATION_JSON));

        assertThat(client.fetch(SupportedCurrency.USD, widened)).hasSize(2);
        server.verify();
    }

    @Test
    void usesTheSettlementAccountOfEachCurrency() {
        assertThat(SettlementAccounts.forCurrency(SupportedCurrency.EUR))
                .isEqualTo(UUID.fromString("0199a000-0000-7000-8000-000000000978"));
        assertThat(SettlementAccounts.forCurrency(SupportedCurrency.JPY))
                .isEqualTo(UUID.fromString("0199a000-0000-7000-8000-000000000392"));
        for (SupportedCurrency currency : SupportedCurrency.values()) {
            assertThat(SettlementAccounts.forCurrency(currency)).isNotNull();
        }
    }

    @Test
    void notFoundIsUnavailable() {
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(withResourceNotFound());

        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class);
    }

    @Test
    void serverErrorIsUnavailable() {
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(withServerError());

        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class);
    }

    @Test
    void connectionFailureIsUnavailable() {
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(request -> {
                    throw new IOException("connect timed out");
                });

        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class)
                .hasMessageContaining("connect timed out");
    }

    @Test
    void garbageOrWrongCurrencyIsUnavailable() {
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(withSuccess("<html>oops</html>", MediaType.APPLICATION_JSON));
        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class);

        server.reset();
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(withSuccess(statement("EUR", DEPOSIT), MediaType.APPLICATION_JSON));
        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class)
                .hasMessageContaining("EUR");

        server.reset();
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(withSuccess(
                        statement("USD", DEPOSIT.replace("\"debit\"", "\"sideways\"")), MediaType.APPLICATION_JSON));
        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class);
    }

    @Test
    void healthReflectsLedgerReachability() {
        server.expect(requestTo(BASE + "/health")).andRespond(withSuccess("{\"status\":\"ok\"}", MediaType.APPLICATION_JSON));
        assertThat(new LedgerHealthIndicator(restClient).health().getStatus()).isEqualTo(Status.UP);

        server.reset();
        server.expect(requestTo(BASE + "/health")).andRespond(withServerError());
        assertThat(new LedgerHealthIndicator(restClient).health().getStatus()).isEqualTo(Status.DOWN);
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run: `docker compose exec reconciliation-service ./mvnw -B -q test -Dtest=LedgerHttpClientTest`
Expected: compilation FAILS (`LedgerHttpClient` does not exist).

- [ ] **Step 3: Implement**

`application/port/LedgerUnavailableException.java`:

```java
package com.ledgerplatform.reconciliation.application.port;

/** The ledger could not provide a usable statement: unreachable, an error status, or an unreadable body. */
public class LedgerUnavailableException extends RuntimeException {

    public LedgerUnavailableException(String message) {
        super(message);
    }
}
```

`application/port/LedgerStatementPort.java`:

```java
package com.ledgerplatform.reconciliation.application.port;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.util.List;

public interface LedgerStatementPort {

    /**
     * Every deposit and withdrawal the ledger posted on the currency's settlement account within {@code period}.
     *
     * @throws LedgerUnavailableException when the ledger cannot answer
     */
    List<LedgerEntry> fetch(SupportedCurrency currency, Period period);
}
```

`infrastructure/ledger/SettlementAccounts.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.ledger;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import java.util.Map;
import java.util.UUID;

/** The settlement account ids ledger-service seeds: the suffix is the ISO 4217 numeric code. */
final class SettlementAccounts {

    private static final Map<SupportedCurrency, UUID> IDS = Map.of(
            SupportedCurrency.USD, UUID.fromString("0199a000-0000-7000-8000-000000000840"),
            SupportedCurrency.EUR, UUID.fromString("0199a000-0000-7000-8000-000000000978"),
            SupportedCurrency.GBP, UUID.fromString("0199a000-0000-7000-8000-000000000826"),
            SupportedCurrency.TZS, UUID.fromString("0199a000-0000-7000-8000-000000000834"),
            SupportedCurrency.JPY, UUID.fromString("0199a000-0000-7000-8000-000000000392"));

    private SettlementAccounts() {}

    static UUID forCurrency(SupportedCurrency currency) {
        return IDS.get(currency);
    }
}
```

`infrastructure/ledger/StatementResponse.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.ledger;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import java.util.List;

/** The part of ledger-service's statement response this service reads. */
@JsonIgnoreProperties(ignoreUnknown = true)
record StatementResponse(String currency, List<Line> lines) {

    @JsonIgnoreProperties(ignoreUnknown = true)
    record Line(String transferId, String occurredAt, String direction, String amount, String description) {}
}
```

`infrastructure/ledger/LedgerHttpClient.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.ledger;

import com.ledgerplatform.reconciliation.application.port.LedgerStatementPort;
import com.ledgerplatform.reconciliation.application.port.LedgerUnavailableException;
import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.time.OffsetDateTime;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import org.springframework.http.MediaType;
import org.springframework.web.client.RestClient;
import org.springframework.web.client.RestClientException;

/**
 * Reads the settlement account statement from ledger-service. A settlement debit is a deposit (money into the bank),
 * a credit is a withdrawal.
 */
public final class LedgerHttpClient implements LedgerStatementPort {

    /** ledger-service rejects a statement whose "to" is more than 366 days after "from". */
    static final int MAX_DAYS_PER_REQUEST = 366;

    private final RestClient client;

    public LedgerHttpClient(RestClient client) {
        this.client = client;
    }

    @Override
    public List<LedgerEntry> fetch(SupportedCurrency currency, Period period) {
        UUID account = SettlementAccounts.forCurrency(currency);
        List<LedgerEntry> entries = new ArrayList<>();
        for (Period part : period.split(MAX_DAYS_PER_REQUEST)) {
            entries.addAll(toEntries(get(account, part), currency));
        }
        return List.copyOf(entries);
    }

    private StatementResponse get(UUID account, Period part) {
        StatementResponse body;
        try {
            body = client.get()
                    .uri("/accounts/{id}/statement?from={from}&to={to}", account, part.from(), part.to())
                    .accept(MediaType.APPLICATION_JSON)
                    .retrieve()
                    .body(StatementResponse.class);
        } catch (RestClientException e) {
            throw new LedgerUnavailableException("The ledger statement request failed: " + e.getMessage());
        }
        if (body == null) {
            throw new LedgerUnavailableException("The ledger returned an empty statement.");
        }
        return body;
    }

    private static List<LedgerEntry> toEntries(StatementResponse statement, SupportedCurrency currency) {
        if (!currency.name().equals(statement.currency()) || statement.lines() == null) {
            throw new LedgerUnavailableException(
                    "The ledger returned a %s statement instead of %s.".formatted(statement.currency(), currency));
        }
        try {
            return statement.lines().stream().map(line -> toEntry(line, currency)).toList();
        } catch (RuntimeException e) {
            throw new LedgerUnavailableException("The ledger returned an unreadable statement line: " + e.getMessage());
        }
    }

    private static LedgerEntry toEntry(StatementResponse.Line line, SupportedCurrency currency) {
        Direction direction =
                switch (line.direction()) {
                    case "debit" -> Direction.IN;
                    case "credit" -> Direction.OUT;
                    default -> throw new IllegalArgumentException("unknown direction " + line.direction());
                };
        return new LedgerEntry(
                UUID.fromString(line.transferId()),
                OffsetDateTime.parse(line.occurredAt()).toInstant(),
                direction,
                Money.parse(line.amount(), currency),
                line.description());
    }
}
```

`infrastructure/ledger/LedgerHealthIndicator.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.ledger;

import org.springframework.boot.health.contributor.Health;
import org.springframework.boot.health.contributor.HealthIndicator;
import org.springframework.web.client.RestClient;
import org.springframework.web.client.RestClientException;

/** Reports whether ledger-service answers; a run needs it. */
public final class LedgerHealthIndicator implements HealthIndicator {

    private final RestClient client;

    public LedgerHealthIndicator(RestClient client) {
        this.client = client;
    }

    @Override
    public Health health() {
        try {
            client.get().uri("/health").retrieve().toBodilessEntity();
            return Health.up().build();
        } catch (RestClientException e) {
            return Health.down().withDetail("error", e.getMessage()).build();
        }
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec reconciliation-service ./mvnw -B -q test -Dtest='LedgerHttpClientTest,ArchitectureTest'`
Expected: PASS. If `garbageOrWrongCurrencyIsUnavailable` fails because the HTML body raises an exception that is not a `RestClientException` (for example a Jackson 3 `JacksonException` escaping the converter), widen the `catch` in `get` to `RestClientException | IllegalStateException | tools.jackson.core.JacksonException` — the contract is that nothing but `LedgerUnavailableException` leaves `fetch`.

- [ ] **Step 5: Gates and commit**

```bash
docker compose exec reconciliation-service ./mvnw -B spotless:apply
make recon-test
git add services/reconciliation-service
git commit -m "feat(reconciliation-service): read the settlement account statement from ledger-service"
```

---

### Task 7: Application use cases

**Files:**
- Create: `application/port/IdGenerator.java`, `application/{StartReconciliation,ResolveItem,GetRun,ListRuns,ListItems}.java`
- Create: `infrastructure/system/UuidV7Generator.java`
- Create (test support): `support/InMemoryRunRepository.java`, `support/SequentialIds.java`
- Test: `application/StartReconciliationTest.java`, `application/ResolveItemTest.java`

**Interfaces:**
- Consumes: everything from Tasks 2–6.
- Produces:
  - `interface IdGenerator { UUID next(); }`; `UuidV7Generator implements IdGenerator`
  - `StartReconciliation(RunRepository, StatementParser, LedgerStatementPort, IdGenerator, Clock, MatchingPolicy)`; `ReconciliationRun execute(StartReconciliation.Command)`; `record Command(String currency, LocalDate from, LocalDate to, byte[] statement)`; `static String sha256Hex(byte[])`
  - `ResolveItem(RunRepository, Clock)`; `ReconciliationRun execute(ResolveItem.Command)`; `record Command(UUID runId, UUID itemId, ResolutionKind resolution, UUID counterpartItemId, String note)`
  - `GetRun(RunRepository)`: `ReconciliationRun execute(UUID runId)` (throws `RunNotFoundException`)
  - `ListRuns(RunRepository)`: `List<ReconciliationRun> execute(String currencyOrNull, RunStatus statusOrNull)`
  - `ListItems(RunRepository)`: `List<ReconItem> execute(UUID runId, ItemKind kindOrNull, Boolean unresolvedOrNull)`
  - Test support: `InMemoryRunRepository implements RunRepository` (simulates versions and the duplicate constraint); `SequentialIds implements IdGenerator`

- [ ] **Step 1: Write the test support and failing tests**

`src/test/java/com/ledgerplatform/reconciliation/support/SequentialIds.java`:

```java
package com.ledgerplatform.reconciliation.support;

import com.ledgerplatform.reconciliation.application.port.IdGenerator;
import java.util.UUID;
import java.util.concurrent.atomic.AtomicLong;

public final class SequentialIds implements IdGenerator {

    private final AtomicLong next = new AtomicLong(1);

    @Override
    public UUID next() {
        return new UUID(0x0199a00000007000L, 0x8000000000000000L + next.getAndIncrement());
    }
}
```

`src/test/java/com/ledgerplatform/reconciliation/support/InMemoryRunRepository.java`:

```java
package com.ledgerplatform.reconciliation.support;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.run.ConcurrentUpdateException;
import com.ledgerplatform.reconciliation.domain.run.DuplicateStatementException;
import com.ledgerplatform.reconciliation.domain.run.ReconItem;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.util.Comparator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.UUID;

/** Stores copies, bumps versions on update and enforces the duplicate-statement constraint, like the JPA adapter. */
public final class InMemoryRunRepository implements RunRepository {

    private final Map<UUID, ReconciliationRun> rows = new LinkedHashMap<>();

    @Override
    public void save(ReconciliationRun run) {
        ReconciliationRun existing = rows.get(run.id());
        if (existing == null) {
            findIdByStatement(run.currency(), run.period(), run.statementSha256()).ifPresent(id -> {
                throw new DuplicateStatementException(id);
            });
            rows.put(run.id(), copy(run, 0));
            return;
        }
        if (existing.version() != run.version()) {
            throw new ConcurrentUpdateException(run.id());
        }
        rows.put(run.id(), copy(run, run.version() + 1));
    }

    @Override
    public Optional<ReconciliationRun> findById(UUID id) {
        return Optional.ofNullable(rows.get(id)).map(run -> copy(run, run.version()));
    }

    @Override
    public Optional<UUID> findIdByStatement(SupportedCurrency currency, Period period, String statementSha256) {
        return rows.values().stream()
                .filter(run -> run.currency() == currency
                        && run.period().equals(period)
                        && run.statementSha256().equals(statementSha256))
                .map(ReconciliationRun::id)
                .findFirst();
    }

    @Override
    public List<ReconciliationRun> list(SupportedCurrency currency, RunStatus status) {
        return rows.values().stream()
                .filter(run -> currency == null || run.currency() == currency)
                .filter(run -> status == null || run.status() == status)
                .sorted(Comparator.comparing(ReconciliationRun::createdAt).reversed())
                .limit(50)
                .map(run -> copy(run, run.version()))
                .toList();
    }

    public int size() {
        return rows.size();
    }

    private static ReconciliationRun copy(ReconciliationRun run, long version) {
        List<ReconItem> items = run.items().stream()
                .map(item -> new ReconItem(
                        item.id(),
                        item.position(),
                        item.kind(),
                        item.matchMethod(),
                        item.bankLine(),
                        item.ledgerEntry(),
                        item.candidates(),
                        item.resolution()))
                .toList();
        return ReconciliationRun.restore(
                run.id(),
                run.currency(),
                run.period(),
                run.statementSha256(),
                run.status(),
                run.createdAt(),
                version,
                items);
    }
}
```

`src/test/java/com/ledgerplatform/reconciliation/application/StartReconciliationTest.java`:

```java
package com.ledgerplatform.reconciliation.application;

import static com.ledgerplatform.reconciliation.domain.money.Direction.IN;
import static com.ledgerplatform.reconciliation.support.Fixtures.ledger;
import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ledgerplatform.reconciliation.application.port.InvalidStatementException;
import com.ledgerplatform.reconciliation.application.port.LedgerStatementPort;
import com.ledgerplatform.reconciliation.application.port.LedgerUnavailableException;
import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.matching.MatchingPolicy;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.money.UnsupportedCurrencyException;
import com.ledgerplatform.reconciliation.domain.run.DuplicateStatementException;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import com.ledgerplatform.reconciliation.domain.statement.InvalidPeriodException;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import com.ledgerplatform.reconciliation.infrastructure.csv.BankStatementCsvParser;
import com.ledgerplatform.reconciliation.support.InMemoryRunRepository;
import com.ledgerplatform.reconciliation.support.SequentialIds;
import java.nio.charset.StandardCharsets;
import java.time.Clock;
import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneOffset;
import java.util.ArrayList;
import java.util.List;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

class StartReconciliationTest {

    private static final String A = "0199a000-0000-7000-8000-00000000000a";
    private static final Instant NOW = Instant.parse("2026-04-02T08:00:00Z");
    private static final LocalDate FROM = LocalDate.parse("2026-03-01");
    private static final LocalDate TO = LocalDate.parse("2026-03-31");

    private final List<Period> ledgerRequests = new ArrayList<>();
    private List<LedgerEntry> ledgerEntries = List.of();
    private RuntimeException ledgerFailure;
    private InMemoryRunRepository runs;
    private StartReconciliation start;

    @BeforeEach
    void setUp() {
        LedgerStatementPort ledger = (currency, period) -> {
            ledgerRequests.add(period);
            if (ledgerFailure != null) {
                throw ledgerFailure;
            }
            return ledgerEntries;
        };
        runs = new InMemoryRunRepository();
        start = new StartReconciliation(
                runs,
                new BankStatementCsvParser(),
                ledger,
                new SequentialIds(),
                Clock.fixed(NOW, ZoneOffset.UTC),
                MatchingPolicy.DEFAULT);
    }

    private static byte[] csv(String rows) {
        return ("date,direction,description,reference,currency,amount\n" + rows).getBytes(StandardCharsets.UTF_8);
    }

    @Test
    void reconcilesAndStoresTheRun() {
        ledgerEntries = List.of(ledger(A, "2026-03-05T10:00:00Z", IN, "100.00"));

        ReconciliationRun run = start.execute(new StartReconciliation.Command(
                "USD", FROM, TO, csv("2026-03-05,CR,Salary," + A + ",USD,100.00\n2026-03-09,CR,,,USD,7.00\n")));

        assertThat(run.createdAt()).isEqualTo(NOW);
        assertThat(run.status()).isEqualTo(RunStatus.OPEN);
        assertThat(run.counts()).containsEntry(ItemKind.MATCHED, 1L).containsEntry(ItemKind.MISSING_IN_LEDGER, 1L);
        assertThat(runs.findById(run.id())).isPresent();
        assertThat(ledgerRequests)
                .containsExactly(new Period(LocalDate.parse("2026-02-27"), LocalDate.parse("2026-04-02")));
    }

    @Test
    void storesTheHexSha256OfTheUploadedBytes() {
        byte[] content = csv("2026-03-05,CR,,,USD,1.00\n");

        ReconciliationRun run = start.execute(new StartReconciliation.Command("USD", FROM, TO, content));

        assertThat(run.statementSha256()).hasSize(64).isEqualTo(StartReconciliation.sha256Hex(content));
        assertThat(StartReconciliation.sha256Hex("abc".getBytes(StandardCharsets.UTF_8)))
                .isEqualTo("ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad");
    }

    @Test
    void rejectsTheSameStatementForTheSameCurrencyAndPeriod() {
        byte[] content = csv("2026-03-05,CR,,,USD,1.00\n");
        ReconciliationRun first = start.execute(new StartReconciliation.Command("USD", FROM, TO, content));
        ledgerRequests.clear();

        assertThatThrownBy(() -> start.execute(new StartReconciliation.Command("USD", FROM, TO, content)))
                .isInstanceOf(DuplicateStatementException.class)
                .satisfies(e -> assertThat(((DuplicateStatementException) e).existingRunId()).isEqualTo(first.id()));
        assertThat(ledgerRequests).isEmpty();
    }

    @Test
    void validatesCurrencyAndPeriodBeforeAnythingElse() {
        assertThatThrownBy(() -> start.execute(new StartReconciliation.Command("CHF", FROM, TO, csv(""))))
                .isInstanceOf(UnsupportedCurrencyException.class);
        assertThatThrownBy(() -> start.execute(new StartReconciliation.Command("USD", TO, FROM, csv(""))))
                .isInstanceOf(InvalidPeriodException.class);
        assertThat(ledgerRequests).isEmpty();
    }

    @Test
    void storesNothingWhenTheStatementIsInvalid() {
        assertThatThrownBy(() -> start.execute(
                        new StartReconciliation.Command("USD", FROM, TO, csv("2026-03-05,CR,,,USD,abc\n"))))
                .isInstanceOf(InvalidStatementException.class);
        assertThat(runs.size()).isZero();
        assertThat(ledgerRequests).isEmpty();
    }

    @Test
    void storesNothingWhenTheLedgerIsUnavailable() {
        ledgerFailure = new LedgerUnavailableException("down");

        assertThatThrownBy(() -> start.execute(
                        new StartReconciliation.Command("USD", FROM, TO, csv("2026-03-05,CR,,,USD,1.00\n"))))
                .isInstanceOf(LedgerUnavailableException.class);
        assertThat(runs.size()).isZero();
    }

    @Test
    void usesTheRequestedCurrency() {
        ReconciliationRun run = start.execute(
                new StartReconciliation.Command("JPY", FROM, TO, "date,direction,description,reference,currency,amount\n2026-03-05,CR,,,JPY,1500\n"
                        .getBytes(StandardCharsets.UTF_8)));

        assertThat(run.currency()).isEqualTo(SupportedCurrency.JPY);
    }
}
```

`src/test/java/com/ledgerplatform/reconciliation/application/ResolveItemTest.java`:

```java
package com.ledgerplatform.reconciliation.application;

import static com.ledgerplatform.reconciliation.domain.money.Direction.IN;
import static com.ledgerplatform.reconciliation.support.Fixtures.bank;
import static com.ledgerplatform.reconciliation.support.Fixtures.ledger;
import static com.ledgerplatform.reconciliation.support.Fixtures.march;
import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.matching.MatchOutcome;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.run.ConcurrentUpdateException;
import com.ledgerplatform.reconciliation.domain.run.ReconItem;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.ResolutionKind;
import com.ledgerplatform.reconciliation.domain.run.RunNotFoundException;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import com.ledgerplatform.reconciliation.support.InMemoryRunRepository;
import com.ledgerplatform.reconciliation.support.SequentialIds;
import java.time.Clock;
import java.time.Instant;
import java.time.ZoneOffset;
import java.util.List;
import java.util.UUID;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

class ResolveItemTest {

    private static final Instant NOW = Instant.parse("2026-04-02T08:00:00Z");
    private static final String A = "0199a000-0000-7000-8000-00000000000a";

    private InMemoryRunRepository runs;
    private ResolveItem resolve;
    private ReconciliationRun run;

    @BeforeEach
    void setUp() {
        runs = new InMemoryRunRepository();
        resolve = new ResolveItem(runs, Clock.fixed(NOW, ZoneOffset.UTC));
        SequentialIds ids = new SequentialIds();
        run = ReconciliationRun.start(
                ids.next(),
                SupportedCurrency.USD,
                march(),
                "b".repeat(64),
                List.of(
                        MatchOutcome.missingInLedger(bank(1, "2026-03-05", IN, "10.00", null)),
                        MatchOutcome.missingInBank(ledger(A, "2026-03-20T10:00:00Z", IN, "10.00"))),
                ids::next,
                NOW);
        runs.save(run);
    }

    private UUID item(ItemKind kind) {
        return run.items().stream()
                .filter(i -> i.kind() == kind)
                .map(ReconItem::id)
                .findFirst()
                .orElseThrow();
    }

    @Test
    void ignoresAnItemAndSavesTheRun() {
        ReconciliationRun result = resolve.execute(new ResolveItem.Command(
                run.id(), item(ItemKind.MISSING_IN_LEDGER), ResolutionKind.IGNORE, null, "fee"));

        assertThat(result.unresolvedExceptions()).isEqualTo(1);
        assertThat(runs.findById(run.id()).orElseThrow().unresolvedExceptions()).isEqualTo(1);
    }

    @Test
    void manuallyMatchesAndReconciles() {
        ReconciliationRun result = resolve.execute(new ResolveItem.Command(
                run.id(), item(ItemKind.MISSING_IN_LEDGER), ResolutionKind.MANUAL_MATCH, item(ItemKind.MISSING_IN_BANK), null));

        assertThat(result.status()).isEqualTo(RunStatus.RECONCILED);
        assertThat(new GetRun(runs).execute(run.id()).status()).isEqualTo(RunStatus.RECONCILED);
    }

    @Test
    void unknownRunIsNotFound() {
        UUID unknown = UUID.fromString("0199ffff-0000-7000-8000-000000000000");

        assertThatThrownBy(() -> resolve.execute(
                        new ResolveItem.Command(unknown, unknown, ResolutionKind.IGNORE, null, "x")))
                .isInstanceOf(RunNotFoundException.class);
        assertThatThrownBy(() -> new GetRun(runs).execute(unknown)).isInstanceOf(RunNotFoundException.class);
    }

    @Test
    void aStaleCopyCannotOverwriteANewerOne() {
        ReconciliationRun stale = runs.findById(run.id()).orElseThrow();
        resolve.execute(new ResolveItem.Command(
                run.id(), item(ItemKind.MISSING_IN_LEDGER), ResolutionKind.IGNORE, null, "first"));

        stale.ignore(item(ItemKind.MISSING_IN_BANK), "second", NOW);

        assertThatThrownBy(() -> runs.save(stale)).isInstanceOf(ConcurrentUpdateException.class);
    }

    @Test
    void listsItemsAndRunsWithFilters() {
        ListItems listItems = new ListItems(runs);

        assertThat(listItems.execute(run.id(), null, null)).hasSize(2);
        assertThat(listItems.execute(run.id(), ItemKind.MISSING_IN_BANK, null)).hasSize(1);
        resolve.execute(new ResolveItem.Command(
                run.id(), item(ItemKind.MISSING_IN_LEDGER), ResolutionKind.IGNORE, null, "fee"));
        assertThat(listItems.execute(run.id(), null, true))
                .extracting(ReconItem::kind)
                .containsExactly(ItemKind.MISSING_IN_BANK);
        assertThat(listItems.execute(run.id(), null, false))
                .extracting(ReconItem::kind)
                .containsExactly(ItemKind.MISSING_IN_LEDGER);

        ListRuns listRuns = new ListRuns(runs);
        assertThat(listRuns.execute(null, null)).hasSize(1);
        assertThat(listRuns.execute("USD", RunStatus.OPEN)).hasSize(1);
        assertThat(listRuns.execute("EUR", null)).isEmpty();
        assertThat(listRuns.execute(null, RunStatus.RECONCILED)).isEmpty();
    }
}
```

- [ ] **Step 2: Run the tests to watch them fail**

Run: `docker compose exec reconciliation-service ./mvnw -B -q test -Dtest='StartReconciliationTest,ResolveItemTest'`
Expected: compilation FAILS (`StartReconciliation` does not exist).

- [ ] **Step 3: Implement**

`application/port/IdGenerator.java`:

```java
package com.ledgerplatform.reconciliation.application.port;

import java.util.UUID;

public interface IdGenerator {

    /** A new time-ordered id (UUID v7). */
    UUID next();
}
```

`application/StartReconciliation.java`:

```java
package com.ledgerplatform.reconciliation.application;

import com.ledgerplatform.reconciliation.application.port.IdGenerator;
import com.ledgerplatform.reconciliation.application.port.LedgerStatementPort;
import com.ledgerplatform.reconciliation.application.port.StatementParser;
import com.ledgerplatform.reconciliation.domain.matching.Matcher;
import com.ledgerplatform.reconciliation.domain.matching.MatchingPolicy;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.run.DuplicateStatementException;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Clock;
import java.time.LocalDate;
import java.util.HexFormat;
import java.util.List;

/** Parses a statement, pulls the ledger side, matches and stores the run. Nothing is stored unless every step succeeds. */
public final class StartReconciliation {

    public record Command(String currency, LocalDate from, LocalDate to, byte[] statement) {}

    private final RunRepository runs;
    private final StatementParser parser;
    private final LedgerStatementPort ledger;
    private final IdGenerator ids;
    private final Clock clock;
    private final MatchingPolicy policy;
    private final Matcher matcher = new Matcher();

    public StartReconciliation(
            RunRepository runs,
            StatementParser parser,
            LedgerStatementPort ledger,
            IdGenerator ids,
            Clock clock,
            MatchingPolicy policy) {
        this.runs = runs;
        this.parser = parser;
        this.ledger = ledger;
        this.ids = ids;
        this.clock = clock;
        this.policy = policy;
    }

    public ReconciliationRun execute(Command command) {
        SupportedCurrency currency = SupportedCurrency.of(command.currency());
        Period period = Period.of(command.from(), command.to());
        String sha256 = sha256Hex(command.statement());
        runs.findIdByStatement(currency, period, sha256).ifPresent(existing -> {
            throw new DuplicateStatementException(existing);
        });

        List<BankLine> lines = parser.parse(command.statement(), currency, period);
        List<LedgerEntry> entries = ledger.fetch(currency, period.widen(policy.windowDays()));

        ReconciliationRun run = ReconciliationRun.start(
                ids.next(),
                currency,
                period,
                sha256,
                matcher.match(lines, entries, period, policy),
                ids::next,
                clock.instant());
        runs.save(run);
        return run;
    }

    static String sha256Hex(byte[] content) {
        try {
            return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(content));
        } catch (NoSuchAlgorithmException e) {
            throw new IllegalStateException("SHA-256 is always available", e);
        }
    }
}
```

`application/ResolveItem.java`:

```java
package com.ledgerplatform.reconciliation.application;

import com.ledgerplatform.reconciliation.domain.run.InvalidResolutionException;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.ResolutionKind;
import com.ledgerplatform.reconciliation.domain.run.RunNotFoundException;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import java.time.Clock;
import java.util.UUID;

public final class ResolveItem {

    public record Command(UUID runId, UUID itemId, ResolutionKind resolution, UUID counterpartItemId, String note) {}

    private final RunRepository runs;
    private final Clock clock;

    public ResolveItem(RunRepository runs, Clock clock) {
        this.runs = runs;
        this.clock = clock;
    }

    public ReconciliationRun execute(Command command) {
        ReconciliationRun run =
                runs.findById(command.runId()).orElseThrow(() -> new RunNotFoundException(command.runId()));
        if (command.resolution() == null) {
            throw new InvalidResolutionException("A resolution is required.");
        }
        switch (command.resolution()) {
            case IGNORE -> run.ignore(command.itemId(), command.note(), clock.instant());
            case MANUAL_MATCH ->
                run.manualMatch(command.itemId(), command.counterpartItemId(), command.note(), clock.instant());
        }
        runs.save(run);
        return run;
    }
}
```

`application/GetRun.java`:

```java
package com.ledgerplatform.reconciliation.application;

import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunNotFoundException;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import java.util.UUID;

public final class GetRun {

    private final RunRepository runs;

    public GetRun(RunRepository runs) {
        this.runs = runs;
    }

    public ReconciliationRun execute(UUID runId) {
        return runs.findById(runId).orElseThrow(() -> new RunNotFoundException(runId));
    }
}
```

`application/ListRuns.java`:

```java
package com.ledgerplatform.reconciliation.application;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import java.util.List;

public final class ListRuns {

    private final RunRepository runs;

    public ListRuns(RunRepository runs) {
        this.runs = runs;
    }

    /** Newest first, at most 50. Null filters match everything. */
    public List<ReconciliationRun> execute(String currency, RunStatus status) {
        return runs.list(currency == null ? null : SupportedCurrency.of(currency), status);
    }
}
```

`application/ListItems.java`:

```java
package com.ledgerplatform.reconciliation.application;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.run.ReconItem;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import java.util.List;
import java.util.UUID;

public final class ListItems {

    private final GetRun getRun;

    public ListItems(RunRepository runs) {
        this.getRun = new GetRun(runs);
    }

    /** Items in matcher order. {@code unresolved = true} keeps open exceptions only; {@code false} keeps the rest. */
    public List<ReconItem> execute(UUID runId, ItemKind kind, Boolean unresolved) {
        return getRun.execute(runId).items().stream()
                .filter(item -> kind == null || item.kind() == kind)
                .filter(item -> unresolved == null || item.isUnresolvedException() == unresolved)
                .toList();
    }
}
```

`infrastructure/system/UuidV7Generator.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.system;

import com.fasterxml.uuid.Generators;
import com.fasterxml.uuid.impl.TimeBasedEpochGenerator;
import com.ledgerplatform.reconciliation.application.port.IdGenerator;
import java.util.UUID;

public final class UuidV7Generator implements IdGenerator {

    private final TimeBasedEpochGenerator generator = Generators.timeBasedEpochGenerator();

    @Override
    public UUID next() {
        return generator.generate();
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec reconciliation-service ./mvnw -B -q test -Dtest='StartReconciliationTest,ResolveItemTest,ArchitectureTest'`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

```bash
docker compose exec reconciliation-service ./mvnw -B spotless:apply
make recon-test
git add services/reconciliation-service
git commit -m "feat(reconciliation-service): add start, resolve and query use cases"
```

---

### Task 8: JPA persistence and Flyway schema

**Files:**
- Create: `src/main/resources/db/migration/V1__init.sql`
- Create: `infrastructure/persistence/{RunEntity,ItemEntity,RunJpaRepository,RunMapper,JpaRunRepository}.java`
- Test: `infrastructure/persistence/JpaRunRepositoryIT.java`

**Interfaces:**
- Consumes: Task 4 `RunRepository`, `ReconciliationRun.restore`, `ReconItem` constructor.
- Produces: `public final class JpaRunRepository implements RunRepository` with constructor `(RunJpaRepository runs, PlatformTransactionManager transactionManager)`; `interface RunJpaRepository extends JpaRepository<RunEntity, UUID>`.

- [ ] **Step 1: Write the failing test**

`src/test/java/com/ledgerplatform/reconciliation/infrastructure/persistence/JpaRunRepositoryIT.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.persistence;

import static com.ledgerplatform.reconciliation.domain.money.Direction.IN;
import static com.ledgerplatform.reconciliation.domain.money.Direction.OUT;
import static com.ledgerplatform.reconciliation.support.Fixtures.bank;
import static com.ledgerplatform.reconciliation.support.Fixtures.ledger;
import static com.ledgerplatform.reconciliation.support.Fixtures.march;
import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.matching.MatchMethod;
import com.ledgerplatform.reconciliation.domain.matching.MatchOutcome;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.run.ConcurrentUpdateException;
import com.ledgerplatform.reconciliation.domain.run.DuplicateStatementException;
import com.ledgerplatform.reconciliation.domain.run.ReconItem;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.support.SequentialIds;
import java.time.Instant;
import java.util.List;
import java.util.UUID;
import java.util.concurrent.Callable;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.test.context.ActiveProfiles;

@SpringBootTest
@ActiveProfiles("test")
class JpaRunRepositoryIT {

    private static final Instant T0 = Instant.parse("2026-04-01T09:00:00Z");
    private static final String A = "0199a000-0000-7000-8000-00000000000a";
    private static final String B = "0199a000-0000-7000-8000-00000000000b";

    @Autowired
    RunRepository runs;

    @Autowired
    JdbcTemplate jdbc;

    private final SequentialIds ids = new SequentialIds();

    @BeforeEach
    void clean() {
        jdbc.execute("TRUNCATE reconciliation_items, reconciliation_runs");
    }

    private ReconciliationRun newRun(String sha, Instant createdAt, MatchOutcome... outcomes) {
        return ReconciliationRun.start(
                ids.next(), SupportedCurrency.USD, march(), sha, List.of(outcomes), ids::next, createdAt);
    }

    @Test
    void roundTripsEveryItemShape() {
        LedgerEntry a = ledger(A, "2026-03-05T10:00:00.123Z", IN, "10.00");
        LedgerEntry b = ledger(B, "2026-03-06T10:00:00Z", OUT, "4.50");
        ReconciliationRun run = newRun(
                "c".repeat(64),
                T0,
                MatchOutcome.matched(MatchMethod.REFERENCE, bank(1, "2026-03-05", IN, "10.00", A), a),
                MatchOutcome.ambiguous(bank(2, "2026-03-06", OUT, "4.50", null), List.of(b)),
                MatchOutcome.missingInBank(b));

        runs.save(run);
        ReconciliationRun loaded = runs.findById(run.id()).orElseThrow();

        assertThat(loaded.currency()).isEqualTo(SupportedCurrency.USD);
        assertThat(loaded.period()).isEqualTo(march());
        assertThat(loaded.statementSha256()).isEqualTo(run.statementSha256());
        assertThat(loaded.createdAt()).isEqualTo(T0);
        assertThat(loaded.status()).isEqualTo(RunStatus.OPEN);
        assertThat(loaded.version()).isZero();
        assertThat(loaded.items())
                .usingRecursiveFieldByFieldElementComparator()
                .containsExactlyElementsOf(run.items());
    }

    @Test
    void storesResolutionsAndBumpsTheVersion() {
        ReconciliationRun run = newRun(
                "d".repeat(64),
                T0,
                MatchOutcome.missingInLedger(bank(1, "2026-03-05", IN, "10.00", null)),
                MatchOutcome.missingInBank(ledger(A, "2026-03-20T10:00:00Z", IN, "10.00")));
        runs.save(run);

        ReconciliationRun loaded = runs.findById(run.id()).orElseThrow();
        loaded.manualMatch(loaded.items().get(0).id(), loaded.items().get(1).id(), "same", T0.plusSeconds(60));
        runs.save(loaded);

        ReconciliationRun reloaded = runs.findById(run.id()).orElseThrow();
        assertThat(reloaded.status()).isEqualTo(RunStatus.RECONCILED);
        assertThat(reloaded.version()).isGreaterThan(loaded.version());
        assertThat(reloaded.items()).extracting(ReconItem::resolution).allSatisfy(resolution -> assertThat(
                        resolution.note())
                .isEqualTo("same"));
    }

    @Test
    void rejectsSavingAStaleCopy() {
        ReconciliationRun run = newRun(
                "e".repeat(64),
                T0,
                MatchOutcome.missingInLedger(bank(1, "2026-03-05", IN, "10.00", null)),
                MatchOutcome.missingInLedger(bank(2, "2026-03-06", IN, "11.00", null)));
        runs.save(run);
        ReconciliationRun first = runs.findById(run.id()).orElseThrow();
        ReconciliationRun second = runs.findById(run.id()).orElseThrow();

        first.ignore(first.items().get(0).id(), "one", T0);
        runs.save(first);
        second.ignore(second.items().get(1).id(), "two", T0);

        assertThatThrownBy(() -> runs.save(second)).isInstanceOf(ConcurrentUpdateException.class);
    }

    @Test
    void concurrentResolutionsOfTheSameVersionLetOnlyOneWin() throws Exception {
        ReconciliationRun run = newRun(
                "f".repeat(64),
                T0,
                MatchOutcome.missingInLedger(bank(1, "2026-03-05", IN, "10.00", null)),
                MatchOutcome.missingInLedger(bank(2, "2026-03-06", IN, "11.00", null)));
        runs.save(run);
        ReconciliationRun first = runs.findById(run.id()).orElseThrow();
        ReconciliationRun second = runs.findById(run.id()).orElseThrow();
        first.ignore(first.items().get(0).id(), "one", T0);
        second.ignore(second.items().get(1).id(), "two", T0);

        ExecutorService pool = Executors.newFixedThreadPool(2);
        try {
            List<Future<Boolean>> results = pool.invokeAll(List.<Callable<Boolean>>of(
                    () -> trySave(first), () -> trySave(second)));
            assertThat(results.stream().filter(this::succeeded).count()).isEqualTo(1);
        } finally {
            pool.shutdown();
        }
        assertThat(runs.findById(run.id()).orElseThrow().unresolvedExceptions()).isEqualTo(1);
    }

    private boolean trySave(ReconciliationRun run) {
        try {
            runs.save(run);
            return true;
        } catch (ConcurrentUpdateException e) {
            return false;
        }
    }

    private boolean succeeded(Future<Boolean> future) {
        try {
            return future.get();
        } catch (Exception e) {
            throw new AssertionError(e);
        }
    }

    @Test
    void theUniqueConstraintTurnsARacingDuplicateIntoDuplicateStatement() {
        ReconciliationRun original = newRun("9".repeat(64), T0, MatchOutcome.missingInLedger(bank(1, "2026-03-05", IN, "1.00", null)));
        ReconciliationRun racer = newRun("9".repeat(64), T0, MatchOutcome.missingInLedger(bank(1, "2026-03-05", IN, "1.00", null)));
        runs.save(original);

        assertThatThrownBy(() -> runs.save(racer))
                .isInstanceOf(DuplicateStatementException.class)
                .satisfies(e -> assertThat(((DuplicateStatementException) e).existingRunId()).isEqualTo(original.id()));
        assertThat(runs.findIdByStatement(SupportedCurrency.USD, march(), "9".repeat(64))).contains(original.id());
        assertThat(runs.findIdByStatement(SupportedCurrency.EUR, march(), "9".repeat(64))).isEmpty();
    }

    @Test
    void listsNewestFirstWithFilters() {
        ReconciliationRun older = newRun("1".repeat(64), T0, MatchOutcome.missingInLedger(bank(1, "2026-03-05", IN, "1.00", null)));
        ReconciliationRun newer = newRun("2".repeat(64), T0.plusSeconds(10), MatchOutcome.matched(
                MatchMethod.FUZZY, bank(1, "2026-03-05", IN, "1.00", null), ledger(A, "2026-03-05T10:00:00Z", IN, "1.00")));
        runs.save(older);
        runs.save(newer);

        assertThat(runs.list(null, null)).extracting(ReconciliationRun::id).containsExactly(newer.id(), older.id());
        assertThat(runs.list(SupportedCurrency.USD, RunStatus.OPEN)).extracting(ReconciliationRun::id).containsExactly(older.id());
        assertThat(runs.list(null, RunStatus.RECONCILED)).extracting(ReconciliationRun::id).containsExactly(newer.id());
        assertThat(runs.list(SupportedCurrency.EUR, null)).isEmpty();
        assertThat(runs.findById(UUID.fromString("0199ffff-0000-7000-8000-000000000000"))).isEmpty();
    }

    @Test
    void itemKindsSurviveAsText() {
        runs.save(newRun("3".repeat(64), T0, MatchOutcome.missingInLedger(bank(1, "2026-03-05", IN, "1.00", null))));

        assertThat(jdbc.queryForObject("SELECT kind FROM reconciliation_items", String.class))
                .isEqualTo(ItemKind.MISSING_IN_LEDGER.name());
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run: `docker compose exec reconciliation-service ./mvnw -B verify -Dsurefire.skip=true -Dit.test=JpaRunRepositoryIT -Dspotless.check.skip=true -Dcheckstyle.skip=true`
Expected: FAIL — no `RunRepository` bean (`NoSuchBeanDefinitionException`).

- [ ] **Step 3: Implement**

`src/main/resources/db/migration/V1__init.sql`:

```sql
CREATE TABLE reconciliation_runs (
    id               UUID        PRIMARY KEY,
    currency         TEXT        NOT NULL CHECK (char_length(currency) = 3),
    period_from      DATE        NOT NULL,
    period_to        DATE        NOT NULL,
    statement_sha256 TEXT        NOT NULL CHECK (char_length(statement_sha256) = 64),
    status           TEXT        NOT NULL,
    created_at       TIMESTAMPTZ NOT NULL,
    version          BIGINT      NOT NULL,
    CONSTRAINT uq_runs_statement UNIQUE (currency, period_from, period_to, statement_sha256)
);

CREATE INDEX idx_runs_created_at ON reconciliation_runs (created_at DESC);

CREATE TABLE reconciliation_items (
    id                  UUID        PRIMARY KEY,
    run_id              UUID        NOT NULL REFERENCES reconciliation_runs (id) ON DELETE CASCADE,
    position            INT         NOT NULL,
    kind                TEXT        NOT NULL,
    match_method        TEXT,
    bank_row            INT,
    bank_date           DATE,
    bank_direction      TEXT,
    bank_description    TEXT,
    bank_reference      TEXT,
    bank_amount_minor   BIGINT,
    ledger_transfer_id  UUID,
    ledger_occurred_at  TIMESTAMPTZ,
    ledger_direction    TEXT,
    ledger_amount_minor BIGINT,
    ledger_description  TEXT,
    candidates          JSONB,
    resolution_kind     TEXT,
    resolution_note     TEXT,
    counterpart_item_id UUID,
    resolved_at         TIMESTAMPTZ,
    CONSTRAINT uq_items_position UNIQUE (run_id, position)
);
```

`infrastructure/persistence/RunEntity.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.persistence;

import jakarta.persistence.CascadeType;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.OneToMany;
import jakarta.persistence.OrderBy;
import jakarta.persistence.Table;
import jakarta.persistence.Version;
import java.time.Instant;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;

@Entity
@Table(name = "reconciliation_runs")
public class RunEntity {

    @Id
    UUID id;

    @Column(nullable = false)
    String currency;

    @Column(name = "period_from", nullable = false)
    LocalDate periodFrom;

    @Column(name = "period_to", nullable = false)
    LocalDate periodTo;

    @Column(name = "statement_sha256", nullable = false)
    String statementSha256;

    @Column(nullable = false)
    String status;

    @Column(name = "created_at", nullable = false)
    Instant createdAt;

    /** Null until the first insert, so Spring Data persists instead of merging. */
    @Version
    Long version;

    @OneToMany(mappedBy = "run", cascade = CascadeType.ALL, orphanRemoval = true)
    @OrderBy("position")
    List<ItemEntity> items = new ArrayList<>();

    protected RunEntity() {}
}
```

`infrastructure/persistence/ItemEntity.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.persistence;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.FetchType;
import jakarta.persistence.Id;
import jakarta.persistence.JoinColumn;
import jakarta.persistence.ManyToOne;
import jakarta.persistence.Table;
import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;
import org.hibernate.annotations.JdbcTypeCode;
import org.hibernate.type.SqlTypes;

@Entity
@Table(name = "reconciliation_items")
public class ItemEntity {

    @Id
    UUID id;

    @ManyToOne(fetch = FetchType.LAZY, optional = false)
    @JoinColumn(name = "run_id")
    RunEntity run;

    @Column(nullable = false)
    int position;

    @Column(nullable = false)
    String kind;

    @Column(name = "match_method")
    String matchMethod;

    @Column(name = "bank_row")
    Integer bankRow;

    @Column(name = "bank_date")
    LocalDate bankDate;

    @Column(name = "bank_direction")
    String bankDirection;

    @Column(name = "bank_description")
    String bankDescription;

    @Column(name = "bank_reference")
    String bankReference;

    @Column(name = "bank_amount_minor")
    Long bankAmountMinor;

    @Column(name = "ledger_transfer_id")
    UUID ledgerTransferId;

    @Column(name = "ledger_occurred_at")
    Instant ledgerOccurredAt;

    @Column(name = "ledger_direction")
    String ledgerDirection;

    @Column(name = "ledger_amount_minor")
    Long ledgerAmountMinor;

    @Column(name = "ledger_description")
    String ledgerDescription;

    /** JSON array of candidate ledger entries (AMBIGUOUS items only). */
    @JdbcTypeCode(SqlTypes.JSON)
    String candidates;

    @Column(name = "resolution_kind")
    String resolutionKind;

    @Column(name = "resolution_note")
    String resolutionNote;

    @Column(name = "counterpart_item_id")
    UUID counterpartItemId;

    @Column(name = "resolved_at")
    Instant resolvedAt;

    protected ItemEntity() {}
}
```

`infrastructure/persistence/RunJpaRepository.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.persistence;

import jakarta.persistence.LockModeType;
import java.time.LocalDate;
import java.util.List;
import java.util.Optional;
import java.util.UUID;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Lock;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

public interface RunJpaRepository extends JpaRepository<RunEntity, UUID> {

    /** Loads a run so that saving it always bumps and checks the version, even if only an item changed. */
    @Lock(LockModeType.OPTIMISTIC_FORCE_INCREMENT)
    @Query("select r from RunEntity r where r.id = :id")
    Optional<RunEntity> findForUpdate(@Param("id") UUID id);

    Optional<RunEntity> findByCurrencyAndPeriodFromAndPeriodToAndStatementSha256(
            String currency, LocalDate periodFrom, LocalDate periodTo, String statementSha256);

    List<RunEntity> findTop50ByOrderByCreatedAtDesc();

    List<RunEntity> findTop50ByCurrencyOrderByCreatedAtDesc(String currency);

    List<RunEntity> findTop50ByStatusOrderByCreatedAtDesc(String status);

    List<RunEntity> findTop50ByCurrencyAndStatusOrderByCreatedAtDesc(String currency, String status);
}
```

`infrastructure/persistence/RunMapper.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.persistence;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.matching.MatchMethod;
import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.run.ReconItem;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.Resolution;
import com.ledgerplatform.reconciliation.domain.run.ResolutionKind;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.time.Instant;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.function.Function;
import java.util.stream.Collectors;
import tools.jackson.core.type.TypeReference;
import tools.jackson.databind.json.JsonMapper;

/** Translates between the aggregate and its rows. Items are immutable apart from their resolution. */
final class RunMapper {

    private static final JsonMapper JSON = JsonMapper.builder().build();

    record CandidateJson(
            UUID transferId, Instant occurredAt, Direction direction, long amountMinor, String description) {}

    ReconciliationRun toDomain(RunEntity entity) {
        SupportedCurrency currency = SupportedCurrency.valueOf(entity.currency);
        List<ReconItem> items = entity.items.stream().map(item -> toDomain(item, currency)).toList();
        return ReconciliationRun.restore(
                entity.id,
                currency,
                new Period(entity.periodFrom, entity.periodTo),
                entity.statementSha256,
                RunStatus.valueOf(entity.status),
                entity.createdAt,
                entity.version,
                items);
    }

    RunEntity newEntity(ReconciliationRun run) {
        RunEntity entity = new RunEntity();
        entity.id = run.id();
        entity.currency = run.currency().name();
        entity.periodFrom = run.period().from();
        entity.periodTo = run.period().to();
        entity.statementSha256 = run.statementSha256();
        entity.status = run.status().name();
        entity.createdAt = run.createdAt();
        entity.version = null;
        for (ReconItem item : run.items()) {
            entity.items.add(newItem(entity, item));
        }
        return entity;
    }

    /** Copies what can change after creation: the run status and each item's resolution. */
    void applyChanges(ReconciliationRun run, RunEntity entity) {
        entity.status = run.status().name();
        Map<UUID, ReconItem> byId = run.items().stream().collect(Collectors.toMap(ReconItem::id, Function.identity()));
        for (ItemEntity row : entity.items) {
            Resolution resolution = byId.get(row.id).resolution();
            row.resolutionKind = resolution == null ? null : resolution.kind().name();
            row.resolutionNote = resolution == null ? null : resolution.note();
            row.counterpartItemId = resolution == null ? null : resolution.counterpartItemId();
            row.resolvedAt = resolution == null ? null : resolution.resolvedAt();
        }
    }

    private static ItemEntity newItem(RunEntity run, ReconItem item) {
        ItemEntity row = new ItemEntity();
        row.id = item.id();
        row.run = run;
        row.position = item.position();
        row.kind = item.kind().name();
        row.matchMethod = item.matchMethod() == null ? null : item.matchMethod().name();
        BankLine bank = item.bankLine();
        if (bank != null) {
            row.bankRow = bank.row();
            row.bankDate = bank.date();
            row.bankDirection = bank.direction().name();
            row.bankDescription = bank.description();
            row.bankReference = bank.reference();
            row.bankAmountMinor = bank.amount().minor();
        }
        LedgerEntry ledger = item.ledgerEntry();
        if (ledger != null) {
            row.ledgerTransferId = ledger.transferId();
            row.ledgerOccurredAt = ledger.occurredAt();
            row.ledgerDirection = ledger.direction().name();
            row.ledgerAmountMinor = ledger.amount().minor();
            row.ledgerDescription = ledger.description();
        }
        if (!item.candidates().isEmpty()) {
            row.candidates = JSON.writeValueAsString(item.candidates().stream()
                    .map(c -> new CandidateJson(
                            c.transferId(), c.occurredAt(), c.direction(), c.amount().minor(), c.description()))
                    .toList());
        }
        Resolution resolution = item.resolution();
        if (resolution != null) {
            row.resolutionKind = resolution.kind().name();
            row.resolutionNote = resolution.note();
            row.counterpartItemId = resolution.counterpartItemId();
            row.resolvedAt = resolution.resolvedAt();
        }
        return row;
    }

    private static ReconItem toDomain(ItemEntity row, SupportedCurrency currency) {
        BankLine bank = row.bankRow == null
                ? null
                : new BankLine(
                        row.bankRow,
                        row.bankDate,
                        Direction.valueOf(row.bankDirection),
                        row.bankDescription,
                        row.bankReference,
                        new Money(row.bankAmountMinor, currency));
        LedgerEntry ledger = row.ledgerTransferId == null
                ? null
                : new LedgerEntry(
                        row.ledgerTransferId,
                        row.ledgerOccurredAt,
                        Direction.valueOf(row.ledgerDirection),
                        new Money(row.ledgerAmountMinor, currency),
                        row.ledgerDescription);
        List<LedgerEntry> candidates = row.candidates == null
                ? List.of()
                : JSON.readValue(row.candidates, new TypeReference<List<CandidateJson>>() {}).stream()
                        .map(c -> new LedgerEntry(
                                c.transferId(),
                                c.occurredAt(),
                                c.direction(),
                                new Money(c.amountMinor(), currency),
                                c.description()))
                        .toList();
        Resolution resolution = row.resolutionKind == null
                ? null
                : new Resolution(
                        ResolutionKind.valueOf(row.resolutionKind),
                        row.resolutionNote,
                        row.counterpartItemId,
                        row.resolvedAt);
        return new ReconItem(
                row.id,
                row.position,
                ItemKind.valueOf(row.kind),
                row.matchMethod == null ? null : MatchMethod.valueOf(row.matchMethod),
                bank,
                ledger,
                candidates,
                resolution);
    }
}
```

`infrastructure/persistence/JpaRunRepository.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.persistence;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.run.ConcurrentUpdateException;
import com.ledgerplatform.reconciliation.domain.run.DuplicateStatementException;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.util.List;
import java.util.Optional;
import java.util.UUID;
import org.springframework.dao.DataIntegrityViolationException;
import org.springframework.dao.OptimisticLockingFailureException;
import org.springframework.transaction.PlatformTransactionManager;
import org.springframework.transaction.support.TransactionTemplate;

/**
 * Stores runs with Spring Data JPA. Each call is its own transaction, and the exceptions a commit can raise are
 * translated into the domain's {@link ConcurrentUpdateException} and {@link DuplicateStatementException}.
 */
public final class JpaRunRepository implements RunRepository {

    private final RunJpaRepository runs;
    private final TransactionTemplate writes;
    private final TransactionTemplate reads;
    private final RunMapper mapper = new RunMapper();

    public JpaRunRepository(RunJpaRepository runs, PlatformTransactionManager transactionManager) {
        this.runs = runs;
        this.writes = new TransactionTemplate(transactionManager);
        this.reads = new TransactionTemplate(transactionManager);
        this.reads.setReadOnly(true);
    }

    @Override
    public void save(ReconciliationRun run) {
        try {
            writes.executeWithoutResult(status -> {
                Optional<RunEntity> existing = runs.findForUpdate(run.id());
                if (existing.isEmpty()) {
                    runs.saveAndFlush(mapper.newEntity(run));
                    return;
                }
                RunEntity entity = existing.get();
                if (entity.version != run.version()) {
                    throw new ConcurrentUpdateException(run.id());
                }
                mapper.applyChanges(run, entity);
                runs.saveAndFlush(entity);
            });
        } catch (OptimisticLockingFailureException e) {
            throw new ConcurrentUpdateException(run.id());
        } catch (DataIntegrityViolationException e) {
            UUID existing = findIdByStatement(run.currency(), run.period(), run.statementSha256())
                    .orElseThrow(() -> e);
            throw new DuplicateStatementException(existing);
        }
    }

    @Override
    public Optional<ReconciliationRun> findById(UUID id) {
        return reads.execute(status -> runs.findById(id).map(mapper::toDomain));
    }

    @Override
    public Optional<UUID> findIdByStatement(SupportedCurrency currency, Period period, String statementSha256) {
        return reads.execute(status -> runs.findByCurrencyAndPeriodFromAndPeriodToAndStatementSha256(
                        currency.name(), period.from(), period.to(), statementSha256)
                .map(entity -> entity.id));
    }

    @Override
    public List<ReconciliationRun> list(SupportedCurrency currency, RunStatus status) {
        return reads.execute(tx -> {
            List<RunEntity> rows;
            if (currency != null && status != null) {
                rows = runs.findTop50ByCurrencyAndStatusOrderByCreatedAtDesc(currency.name(), status.name());
            } else if (currency != null) {
                rows = runs.findTop50ByCurrencyOrderByCreatedAtDesc(currency.name());
            } else if (status != null) {
                rows = runs.findTop50ByStatusOrderByCreatedAtDesc(status.name());
            } else {
                rows = runs.findTop50ByOrderByCreatedAtDesc();
            }
            return rows.stream().map(mapper::toDomain).toList();
        });
    }
}
```

Register the adapter so the IT can inject `RunRepository` — create the wiring class now with just this bean (Task 9 extends it). `infrastructure/config/ReconciliationConfiguration.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.config;

import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.infrastructure.persistence.JpaRunRepository;
import com.ledgerplatform.reconciliation.infrastructure.persistence.RunJpaRepository;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.transaction.PlatformTransactionManager;

/** Wires the use cases to their adapters. Use cases stay plain classes without Spring annotations. */
@Configuration(proxyBeanMethods = false)
public class ReconciliationConfiguration {

    @Bean
    RunRepository runRepository(RunJpaRepository runs, PlatformTransactionManager transactionManager) {
        return new JpaRunRepository(runs, transactionManager);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec reconciliation-service ./mvnw -B verify -Dsurefire.skip=true -Dit.test='JpaRunRepositoryIT,ApplicationIT' -Dspotless.check.skip=true -Dcheckstyle.skip=true`
Expected: PASS, and Flyway logs `Migrating schema "public" to version "1 - init"` on the first run; `ddl-auto: validate` accepts the entities.

- [ ] **Step 5: Gates and commit**

```bash
docker compose exec reconciliation-service ./mvnw -B spotless:apply
make recon-test
git add services/reconciliation-service
git commit -m "feat(reconciliation-service): persist runs with JPA and Flyway, with optimistic locking"
```

---

### Task 9: HTTP API, problem details and wiring

**Files:**
- Create: `infrastructure/web/{RunController,RunResponse,ItemResponse,ListResponse,ResolveRequest,StatementTooLargeException,ProblemDetailsAdvice}.java`
- Create: `infrastructure/config/ReconciliationProperties.java`
- Modify: `infrastructure/config/ReconciliationConfiguration.java` (all beans), `src/main/resources/application.yaml` (the `reconciliation:` block)
- Create (test support): `support/{StubLedger,StubLedgerConfiguration}.java`
- Test: `infrastructure/web/RunApiIT.java`

**Interfaces:**
- Consumes: every use case from Task 7, `JpaRunRepository` from Task 8, `LedgerHttpClient`/`LedgerHealthIndicator` from Task 6, `BankStatementCsvParser` from Task 5.
- Produces: the HTTP API of spec §6 on port 8080; `ReconciliationProperties(String ledgerBaseUrl, int ledgerTimeoutMs, int matchWindowDays)` bound from `reconciliation.*`.

- [ ] **Step 1: Write the test support and the failing test**

`src/test/java/com/ledgerplatform/reconciliation/support/StubLedger.java`:

```java
package com.ledgerplatform.reconciliation.support;

import com.ledgerplatform.reconciliation.application.port.LedgerStatementPort;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.util.List;

/** Replaces ledger-service in integration tests. */
public final class StubLedger implements LedgerStatementPort {

    private volatile List<LedgerEntry> entries = List.of();
    private volatile RuntimeException failure;

    public void returns(LedgerEntry... value) {
        entries = List.of(value);
        failure = null;
    }

    public void failsWith(RuntimeException value) {
        failure = value;
    }

    public void reset() {
        entries = List.of();
        failure = null;
    }

    @Override
    public List<LedgerEntry> fetch(SupportedCurrency currency, Period period) {
        if (failure != null) {
            throw failure;
        }
        return entries;
    }
}
```

`src/test/java/com/ledgerplatform/reconciliation/support/StubLedgerConfiguration.java`:

```java
package com.ledgerplatform.reconciliation.support;

import org.springframework.boot.test.context.TestConfiguration;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Primary;

@TestConfiguration(proxyBeanMethods = false)
public class StubLedgerConfiguration {

    @Bean
    @Primary
    StubLedger stubLedger() {
        return new StubLedger();
    }
}
```

`src/test/java/com/ledgerplatform/reconciliation/infrastructure/web/RunApiIT.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.web;

import static com.ledgerplatform.reconciliation.domain.money.Direction.IN;
import static com.ledgerplatform.reconciliation.domain.money.Direction.OUT;
import static com.ledgerplatform.reconciliation.support.Fixtures.ledger;
import static org.assertj.core.api.Assertions.assertThat;
import static org.hamcrest.Matchers.hasSize;
import static org.hamcrest.Matchers.startsWith;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import com.ledgerplatform.reconciliation.application.port.LedgerUnavailableException;
import com.ledgerplatform.reconciliation.support.StubLedger;
import com.ledgerplatform.reconciliation.support.StubLedgerConfiguration;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.boot.webmvc.test.autoconfigure.AutoConfigureMockMvc;
import org.springframework.context.annotation.Import;
import org.springframework.http.MediaType;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.test.context.ActiveProfiles;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.test.web.servlet.ResultActions;
import org.springframework.test.web.servlet.request.MockMultipartHttpServletRequestBuilder;
import tools.jackson.databind.json.JsonMapper;

@SpringBootTest
@AutoConfigureMockMvc
@ActiveProfiles("test")
@Import(StubLedgerConfiguration.class)
class RunApiIT {

    private static final String PROBLEM = "urn:reconciliation:problem:";
    private static final String HEADER = "date,direction,description,reference,currency,amount\n";
    private static final String A = "0199a000-0000-7000-8000-00000000000a";
    private static final String B = "0199a000-0000-7000-8000-00000000000b";
    private static final String C = "0199a000-0000-7000-8000-00000000000c";

    @Autowired
    MockMvc mvc;

    @Autowired
    StubLedger ledger;

    @Autowired
    JdbcTemplate jdbc;

    @Autowired
    JsonMapper json;

    @BeforeEach
    void clean() {
        jdbc.execute("TRUNCATE reconciliation_items, reconciliation_runs");
        ledger.reset();
    }

    private MockMultipartHttpServletRequestBuilder upload(String csv, String currency, String from, String to) {
        MockMultipartHttpServletRequestBuilder request = multipart("/runs")
                .file(new MockMultipartFile("file", "statement.csv", "text/csv", csv.getBytes(StandardCharsets.UTF_8)));
        if (currency != null) {
            request.param("currency", currency);
        }
        if (from != null) {
            request.param("from", from);
        }
        if (to != null) {
            request.param("to", to);
        }
        return request;
    }

    private ResultActions uploadMarch(String rows) throws Exception {
        return mvc.perform(upload(HEADER + rows, "USD", "2026-03-01", "2026-03-31"));
    }

    private static void expectProblem(ResultActions result, int status, String type) throws Exception {
        result.andExpect(status().is(status))
                .andExpect(content().contentTypeCompatibleWith(MediaType.APPLICATION_PROBLEM_JSON))
                .andExpect(jsonPath("$.type").value(PROBLEM + type))
                .andExpect(jsonPath("$.status").value(status));
    }

    private Map<?, ?> body(ResultActions result) throws Exception {
        return json.readValue(result.andReturn().getResponse().getContentAsString(), Map.class);
    }

    @Test
    void reconcilesAStatementAndResolvesItsExceptions() throws Exception {
        ledger.returns(
                ledger(A, "2026-03-05T10:00:00Z", IN, "100.00"),
                ledger(B, "2026-03-07T12:00:00Z", OUT, "20.00"),
                ledger(C, "2026-03-20T09:00:00Z", IN, "15.00"));

        ResultActions created = uploadMarch("2026-03-05,CR,Salary," + A + ",USD,100.00\n"
                        + "2026-03-08,DR,ATM,,USD,20.00\n"
                        + "2026-03-21,CR,Refund,,USD,14.00\n")
                .andExpect(status().isCreated())
                .andExpect(header().string("Location", startsWith("/runs/")))
                .andExpect(jsonPath("$.currency").value("USD"))
                .andExpect(jsonPath("$.from").value("2026-03-01"))
                .andExpect(jsonPath("$.to").value("2026-03-31"))
                .andExpect(jsonPath("$.status").value("OPEN"))
                .andExpect(jsonPath("$.counts.MATCHED").value(2))
                .andExpect(jsonPath("$.counts.MISSING_IN_LEDGER").value(1))
                .andExpect(jsonPath("$.counts.MISSING_IN_BANK").value(1))
                .andExpect(jsonPath("$.unresolvedExceptions").value(2));
        String runId = (String) body(created).get("id");

        ResultActions items = mvc.perform(get("/runs/{id}/items", runId))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.items", hasSize(4)))
                .andExpect(jsonPath("$.items[0].kind").value("MATCHED"))
                .andExpect(jsonPath("$.items[0].matchMethod").value("REFERENCE"))
                .andExpect(jsonPath("$.items[0].bankLine.amount").value("100.00"))
                .andExpect(jsonPath("$.items[0].bankLine.direction").value("IN"))
                .andExpect(jsonPath("$.items[0].ledgerEntry.transferId").value(A))
                .andExpect(jsonPath("$.items[0].ledgerEntry.occurredAt").value("2026-03-05T10:00:00Z"))
                .andExpect(jsonPath("$.items[1].matchMethod").value("FUZZY"))
                .andExpect(jsonPath("$.items[2].kind").value("MISSING_IN_LEDGER"))
                .andExpect(jsonPath("$.items[3].kind").value("MISSING_IN_BANK"));
        List<?> list = (List<?>) body(items).get("items");
        String bankItem = (String) ((Map<?, ?>) list.get(2)).get("id");
        String ledgerItem = (String) ((Map<?, ?>) list.get(3)).get("id");

        mvc.perform(get("/runs/{id}/items", runId).param("unresolved", "true"))
                .andExpect(jsonPath("$.items", hasSize(2)));

        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, bankItem)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":\"MANUAL_MATCH\",\"counterpartItemId\":\"" + ledgerItem + "\"}")),
                409,
                "invalid-manual-match");

        mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, bankItem)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":\"MANUAL_MATCH\",\"counterpartItemId\":\"" + ledgerItem
                                + "\",\"note\":\"1.00 fee withheld by the bank\"}"))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.status").value("RECONCILED"))
                .andExpect(jsonPath("$.unresolvedExceptions").value(0));

        mvc.perform(get("/runs/{id}/items", runId).param("kind", "MISSING_IN_BANK"))
                .andExpect(jsonPath("$.items[0].resolution.kind").value("MANUAL_MATCH"))
                .andExpect(jsonPath("$.items[0].resolution.counterpartItemId").value(bankItem));

        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, bankItem)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":\"IGNORE\",\"note\":\"again\"}")),
                409,
                "item-already-resolved");

        mvc.perform(get("/runs/{id}", runId)).andExpect(jsonPath("$.status").value("RECONCILED"));
        mvc.perform(get("/runs").param("status", "RECONCILED"))
                .andExpect(jsonPath("$.items", hasSize(1)))
                .andExpect(jsonPath("$.items[0].id").value(runId));
    }

    @Test
    void reportsEveryInvalidRow() throws Exception {
        ResultActions result = uploadMarch("2026-03-05,CR,,,USD,abc\n2026-04-05,XX,,,USD,1.00\n");

        expectProblem(result, 422, "invalid-statement");
        result.andExpect(jsonPath("$.errors", hasSize(3)))
                .andExpect(jsonPath("$.errors[0].row").value(1))
                .andExpect(jsonPath("$.errors[0].field").value("amount"))
                .andExpect(jsonPath("$.errors[1].field").value("date"))
                .andExpect(jsonPath("$.errors[2].field").value("direction"));
        assertThat(jdbc.queryForObject("SELECT count(*) FROM reconciliation_runs", Long.class)).isZero();
    }

    @Test
    void rejectsADuplicateStatementWithTheExistingRunId() throws Exception {
        String rows = "2026-03-05,CR,,,USD,1.00\n";
        String firstId = (String) body(uploadMarch(rows).andExpect(status().isCreated())).get("id");

        ResultActions again = uploadMarch(rows);

        expectProblem(again, 409, "duplicate-statement");
        again.andExpect(jsonPath("$.existingRunId").value(firstId));
    }

    @Test
    void sameFileForAnotherPeriodIsNotADuplicate() throws Exception {
        String csv = HEADER + "2026-03-05,CR,,,USD,1.00\n";
        mvc.perform(upload(csv, "USD", "2026-03-01", "2026-03-31")).andExpect(status().isCreated());

        mvc.perform(upload(csv, "USD", "2026-03-01", "2026-03-30")).andExpect(status().isCreated());
    }

    @Test
    void rejectsBadFormFields() throws Exception {
        expectProblem(mvc.perform(upload(HEADER, "CHF", "2026-03-01", "2026-03-31")), 422, "unsupported-currency");
        expectProblem(mvc.perform(upload(HEADER, "USD", "2026-03-31", "2026-03-01")), 422, "invalid-period");
        expectProblem(mvc.perform(upload(HEADER, "USD", "2025-01-01", "2026-03-01")), 422, "invalid-period");
        expectProblem(mvc.perform(upload(HEADER, "USD", "03/01/2026", "2026-03-31")), 422, "validation-failed");
        ResultActions missing = mvc.perform(upload(HEADER, null, "2026-03-01", "2026-03-31"));
        expectProblem(missing, 422, "validation-failed");
        missing.andExpect(jsonPath("$.errors[0].field").value("currency"));
        expectProblem(
                mvc.perform(multipart("/runs")
                        .param("currency", "USD")
                        .param("from", "2026-03-01")
                        .param("to", "2026-03-31")),
                422,
                "validation-failed");
    }

    @Test
    void rejectsStatementsLargerThan5Mb() throws Exception {
        byte[] big = new byte[5 * 1024 * 1024 + 1];
        java.util.Arrays.fill(big, (byte) 'a');

        expectProblem(
                mvc.perform(multipart("/runs")
                        .file(new MockMultipartFile("file", "big.csv", "text/csv", big))
                        .param("currency", "USD")
                        .param("from", "2026-03-01")
                        .param("to", "2026-03-31")),
                413,
                "statement-too-large");
    }

    @Test
    void ledgerOutageIsA502AndStoresNothing() throws Exception {
        ledger.failsWith(new LedgerUnavailableException("connection refused"));

        expectProblem(uploadMarch("2026-03-05,CR,,,USD,1.00\n"), 502, "ledger-unavailable");
        assertThat(jdbc.queryForObject("SELECT count(*) FROM reconciliation_runs", Long.class)).isZero();
    }

    @Test
    void validatesResolveRequests() throws Exception {
        String runId = (String) body(uploadMarch("2026-03-05,CR,,,USD,1.00\n")).get("id");
        String itemId = (String) ((Map<?, ?>) ((List<?>) body(mvc.perform(get("/runs/{id}/items", runId)))
                                .get("items"))
                        .get(0))
                .get("id");

        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, itemId)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":\"IGNORE\"}")),
                422,
                "validation-failed");
        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, itemId)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":\"DELETE\",\"note\":\"x\"}")),
                422,
                "validation-failed");
        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, itemId)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"note\":\"x\"}")),
                422,
                "validation-failed");
        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, itemId)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":")),
                422,
                "validation-failed");
        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, "0199ffff-0000-7000-8000-000000000000")
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":\"IGNORE\",\"note\":\"x\"}")),
                404,
                "item-not-found");
    }

    @Test
    void notFoundAndMalformedIds() throws Exception {
        expectProblem(mvc.perform(get("/runs/0199ffff-0000-7000-8000-000000000000")), 404, "run-not-found");
        expectProblem(mvc.perform(get("/runs/not-a-uuid")), 422, "validation-failed");
        expectProblem(mvc.perform(get("/runs").param("status", "LOST")), 422, "validation-failed");
        expectProblem(mvc.perform(get("/runs").param("currency", "XXX")), 422, "unsupported-currency");
        expectProblem(mvc.perform(get("/nope")), 404, "not-found");
    }

    @Test
    void healthShowsTheDatabase() throws Exception {
        mvc.perform(get("/actuator/health")).andExpect(jsonPath("$.components.db.status").value("UP"));
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run: `docker compose exec reconciliation-service ./mvnw -B verify -Dsurefire.skip=true -Dit.test=RunApiIT -Dspotless.check.skip=true -Dcheckstyle.skip=true`
Expected: FAIL — `POST /runs` returns 404 (no controller).

- [ ] **Step 3: Implement**

Append to `src/main/resources/application.yaml`:

```yaml
reconciliation:
  ledger-base-url: ${LEDGER_BASE_URL:http://ledger-service}
  ledger-timeout-ms: ${LEDGER_TIMEOUT_MS:5000}
  match-window-days: ${MATCH_WINDOW_DAYS:2}
```

and append to `src/test/resources/application-test.yaml` (tests replace the ledger port with `StubLedger`; the health indicator points at a name that never resolves):

```yaml
reconciliation:
  ledger-base-url: http://ledger.invalid
  ledger-timeout-ms: 500
```

`infrastructure/config/ReconciliationProperties.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.config;

import jakarta.validation.constraints.Max;
import jakarta.validation.constraints.Min;
import jakarta.validation.constraints.NotBlank;
import org.springframework.boot.context.properties.ConfigurationProperties;
import org.springframework.validation.annotation.Validated;

/** Validated at startup: an invalid value stops the application. */
@Validated
@ConfigurationProperties("reconciliation")
public record ReconciliationProperties(
        @NotBlank String ledgerBaseUrl,
        @Min(100) @Max(60_000) int ledgerTimeoutMs,
        @Min(0) @Max(7) int matchWindowDays) {}
```

Replace `infrastructure/config/ReconciliationConfiguration.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.config;

import com.ledgerplatform.reconciliation.application.GetRun;
import com.ledgerplatform.reconciliation.application.ListItems;
import com.ledgerplatform.reconciliation.application.ListRuns;
import com.ledgerplatform.reconciliation.application.ResolveItem;
import com.ledgerplatform.reconciliation.application.StartReconciliation;
import com.ledgerplatform.reconciliation.application.port.IdGenerator;
import com.ledgerplatform.reconciliation.application.port.LedgerStatementPort;
import com.ledgerplatform.reconciliation.application.port.StatementParser;
import com.ledgerplatform.reconciliation.domain.matching.MatchingPolicy;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.infrastructure.csv.BankStatementCsvParser;
import com.ledgerplatform.reconciliation.infrastructure.ledger.LedgerHealthIndicator;
import com.ledgerplatform.reconciliation.infrastructure.ledger.LedgerHttpClient;
import com.ledgerplatform.reconciliation.infrastructure.persistence.JpaRunRepository;
import com.ledgerplatform.reconciliation.infrastructure.persistence.RunJpaRepository;
import com.ledgerplatform.reconciliation.infrastructure.system.UuidV7Generator;
import java.net.http.HttpClient;
import java.time.Clock;
import java.time.Duration;
import org.springframework.boot.context.properties.EnableConfigurationProperties;
import org.springframework.boot.health.contributor.HealthIndicator;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.http.client.JdkClientHttpRequestFactory;
import org.springframework.transaction.PlatformTransactionManager;
import org.springframework.web.client.RestClient;

/** Wires the use cases to their adapters. Use cases stay plain classes without Spring annotations. */
@Configuration(proxyBeanMethods = false)
@EnableConfigurationProperties(ReconciliationProperties.class)
public class ReconciliationConfiguration {

    @Bean
    Clock clock() {
        return Clock.systemUTC();
    }

    @Bean
    IdGenerator idGenerator() {
        return new UuidV7Generator();
    }

    @Bean
    MatchingPolicy matchingPolicy(ReconciliationProperties properties) {
        return new MatchingPolicy(properties.matchWindowDays());
    }

    @Bean
    RestClient ledgerRestClient(RestClient.Builder builder, ReconciliationProperties properties) {
        Duration timeout = Duration.ofMillis(properties.ledgerTimeoutMs());
        JdkClientHttpRequestFactory requestFactory = new JdkClientHttpRequestFactory(
                HttpClient.newBuilder().connectTimeout(timeout).build());
        requestFactory.setReadTimeout(timeout);
        return builder.baseUrl(properties.ledgerBaseUrl())
                .requestFactory(requestFactory)
                .build();
    }

    @Bean
    LedgerStatementPort ledgerStatementPort(RestClient ledgerRestClient) {
        return new LedgerHttpClient(ledgerRestClient);
    }

    /** Shows up as the "ledger" component of /actuator/health. */
    @Bean
    HealthIndicator ledgerHealthIndicator(RestClient ledgerRestClient) {
        return new LedgerHealthIndicator(ledgerRestClient);
    }

    @Bean
    StatementParser statementParser() {
        return new BankStatementCsvParser();
    }

    @Bean
    RunRepository runRepository(RunJpaRepository runs, PlatformTransactionManager transactionManager) {
        return new JpaRunRepository(runs, transactionManager);
    }

    @Bean
    StartReconciliation startReconciliation(
            RunRepository runs,
            StatementParser parser,
            LedgerStatementPort ledger,
            IdGenerator ids,
            Clock clock,
            MatchingPolicy policy) {
        return new StartReconciliation(runs, parser, ledger, ids, clock, policy);
    }

    @Bean
    ResolveItem resolveItem(RunRepository runs, Clock clock) {
        return new ResolveItem(runs, clock);
    }

    @Bean
    GetRun getRun(RunRepository runs) {
        return new GetRun(runs);
    }

    @Bean
    ListRuns listRuns(RunRepository runs) {
        return new ListRuns(runs);
    }

    @Bean
    ListItems listItems(RunRepository runs) {
        return new ListItems(runs);
    }
}
```

`infrastructure/web/RunResponse.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.web;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import java.time.Instant;
import java.time.LocalDate;
import java.util.Map;
import java.util.UUID;

public record RunResponse(
        UUID id,
        String currency,
        LocalDate from,
        LocalDate to,
        RunStatus status,
        Instant createdAt,
        Map<ItemKind, Long> counts,
        long unresolvedExceptions) {

    static RunResponse from(ReconciliationRun run) {
        return new RunResponse(
                run.id(),
                run.currency().name(),
                run.period().from(),
                run.period().to(),
                run.status(),
                run.createdAt(),
                run.counts(),
                run.unresolvedExceptions());
    }
}
```

`infrastructure/web/ItemResponse.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.web;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.matching.MatchMethod;
import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.run.ReconItem;
import com.ledgerplatform.reconciliation.domain.run.Resolution;
import com.ledgerplatform.reconciliation.domain.run.ResolutionKind;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import java.time.Instant;
import java.time.LocalDate;
import java.util.List;
import java.util.UUID;

/** Amounts are decimal strings, never JSON numbers. */
public record ItemResponse(
        UUID id,
        int position,
        ItemKind kind,
        MatchMethod matchMethod,
        BankLineView bankLine,
        LedgerEntryView ledgerEntry,
        List<LedgerEntryView> candidates,
        ResolutionView resolution) {

    public record BankLineView(
            int row, LocalDate date, Direction direction, String description, String reference, String amount) {}

    public record LedgerEntryView(
            UUID transferId, Instant occurredAt, Direction direction, String amount, String description) {}

    public record ResolutionView(ResolutionKind kind, String note, UUID counterpartItemId, Instant resolvedAt) {}

    static ItemResponse from(ReconItem item) {
        return new ItemResponse(
                item.id(),
                item.position(),
                item.kind(),
                item.matchMethod(),
                view(item.bankLine()),
                view(item.ledgerEntry()),
                item.candidates().stream().map(ItemResponse::view).toList(),
                view(item.resolution()));
    }

    private static BankLineView view(BankLine line) {
        return line == null
                ? null
                : new BankLineView(
                        line.row(),
                        line.date(),
                        line.direction(),
                        line.description(),
                        line.reference(),
                        line.amount().toDecimalString());
    }

    private static LedgerEntryView view(LedgerEntry entry) {
        return entry == null
                ? null
                : new LedgerEntryView(
                        entry.transferId(),
                        entry.occurredAt(),
                        entry.direction(),
                        entry.amount().toDecimalString(),
                        entry.description());
    }

    private static ResolutionView view(Resolution resolution) {
        return resolution == null
                ? null
                : new ResolutionView(
                        resolution.kind(), resolution.note(), resolution.counterpartItemId(), resolution.resolvedAt());
    }
}
```

`infrastructure/web/ListResponse.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.web;

import java.util.List;

public record ListResponse<T>(List<T> items) {}
```

`infrastructure/web/ResolveRequest.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.web;

import com.ledgerplatform.reconciliation.domain.run.ResolutionKind;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Size;
import java.util.UUID;

public record ResolveRequest(@NotNull ResolutionKind resolution, UUID counterpartItemId, @Size(max = 500) String note) {}
```

`infrastructure/web/StatementTooLargeException.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.web;

public class StatementTooLargeException extends RuntimeException {

    public StatementTooLargeException(long maxBytes) {
        super("A statement may be at most %d MB.".formatted(maxBytes / (1024 * 1024)));
    }
}
```

`infrastructure/web/RunController.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.web;

import com.ledgerplatform.reconciliation.application.GetRun;
import com.ledgerplatform.reconciliation.application.ListItems;
import com.ledgerplatform.reconciliation.application.ListRuns;
import com.ledgerplatform.reconciliation.application.ResolveItem;
import com.ledgerplatform.reconciliation.application.StartReconciliation;
import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import jakarta.validation.Valid;
import java.io.IOException;
import java.net.URI;
import java.time.LocalDate;
import java.util.UUID;
import org.springframework.format.annotation.DateTimeFormat;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.multipart.MultipartFile;

@RestController
@RequestMapping("/runs")
public class RunController {

    static final long MAX_STATEMENT_BYTES = 5L * 1024 * 1024;

    private final StartReconciliation start;
    private final ResolveItem resolve;
    private final GetRun getRun;
    private final ListRuns listRuns;
    private final ListItems listItems;

    public RunController(
            StartReconciliation start, ResolveItem resolve, GetRun getRun, ListRuns listRuns, ListItems listItems) {
        this.start = start;
        this.resolve = resolve;
        this.getRun = getRun;
        this.listRuns = listRuns;
        this.listItems = listItems;
    }

    @PostMapping(consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public ResponseEntity<RunResponse> start(
            @RequestParam("file") MultipartFile file,
            @RequestParam("currency") String currency,
            @RequestParam("from") @DateTimeFormat(iso = DateTimeFormat.ISO.DATE) LocalDate from,
            @RequestParam("to") @DateTimeFormat(iso = DateTimeFormat.ISO.DATE) LocalDate to)
            throws IOException {
        if (file.getSize() > MAX_STATEMENT_BYTES) {
            throw new StatementTooLargeException(MAX_STATEMENT_BYTES);
        }
        ReconciliationRun run = start.execute(new StartReconciliation.Command(currency, from, to, file.getBytes()));
        return ResponseEntity.created(URI.create("/runs/" + run.id())).body(RunResponse.from(run));
    }

    @GetMapping
    public ListResponse<RunResponse> list(
            @RequestParam(value = "currency", required = false) String currency,
            @RequestParam(value = "status", required = false) RunStatus status) {
        return new ListResponse<>(listRuns.execute(currency, status).stream()
                .map(RunResponse::from)
                .toList());
    }

    @GetMapping("/{id}")
    public RunResponse get(@PathVariable("id") UUID id) {
        return RunResponse.from(getRun.execute(id));
    }

    @GetMapping("/{id}/items")
    public ListResponse<ItemResponse> items(
            @PathVariable("id") UUID id,
            @RequestParam(value = "kind", required = false) ItemKind kind,
            @RequestParam(value = "unresolved", required = false) Boolean unresolved) {
        return new ListResponse<>(listItems.execute(id, kind, unresolved).stream()
                .map(ItemResponse::from)
                .toList());
    }

    @PostMapping(path = "/{id}/items/{itemId}/resolve", consumes = MediaType.APPLICATION_JSON_VALUE)
    public RunResponse resolve(
            @PathVariable("id") UUID id,
            @PathVariable("itemId") UUID itemId,
            @Valid @RequestBody ResolveRequest body) {
        return RunResponse.from(resolve.execute(
                new ResolveItem.Command(id, itemId, body.resolution(), body.counterpartItemId(), body.note())));
    }
}
```

`infrastructure/web/ProblemDetailsAdvice.java`:

```java
package com.ledgerplatform.reconciliation.infrastructure.web;

import com.ledgerplatform.reconciliation.application.port.InvalidStatementException;
import com.ledgerplatform.reconciliation.application.port.LedgerUnavailableException;
import com.ledgerplatform.reconciliation.domain.DomainException;
import com.ledgerplatform.reconciliation.domain.run.ConcurrentUpdateException;
import com.ledgerplatform.reconciliation.domain.run.DuplicateStatementException;
import com.ledgerplatform.reconciliation.domain.run.InvalidManualMatchException;
import com.ledgerplatform.reconciliation.domain.run.ItemAlreadyResolvedException;
import com.ledgerplatform.reconciliation.domain.run.ItemNotFoundException;
import com.ledgerplatform.reconciliation.domain.run.RunNotFoundException;
import java.net.URI;
import java.util.List;
import java.util.Map;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.http.HttpStatus;
import org.springframework.http.ProblemDetail;
import org.springframework.http.converter.HttpMessageNotReadableException;
import org.springframework.web.HttpMediaTypeNotSupportedException;
import org.springframework.web.HttpRequestMethodNotSupportedException;
import org.springframework.web.bind.MethodArgumentNotValidException;
import org.springframework.web.bind.MissingServletRequestParameterException;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.RestControllerAdvice;
import org.springframework.web.method.annotation.MethodArgumentTypeMismatchException;
import org.springframework.web.multipart.MaxUploadSizeExceededException;
import org.springframework.web.multipart.MultipartException;
import org.springframework.web.multipart.support.MissingServletRequestPartException;
import org.springframework.web.servlet.resource.NoResourceFoundException;

/** Renders every error as RFC 9457 application/problem+json. */
@RestControllerAdvice
public class ProblemDetailsAdvice {

    private static final Logger LOG = LoggerFactory.getLogger(ProblemDetailsAdvice.class);
    private static final String TYPE_PREFIX = "urn:reconciliation:problem:";

    /** Domain errors not listed here are business-rule violations: 422. */
    private static final Map<Class<? extends DomainException>, HttpStatus> DOMAIN_STATUS = Map.of(
            RunNotFoundException.class, HttpStatus.NOT_FOUND,
            ItemNotFoundException.class, HttpStatus.NOT_FOUND,
            ItemAlreadyResolvedException.class, HttpStatus.CONFLICT,
            InvalidManualMatchException.class, HttpStatus.CONFLICT,
            DuplicateStatementException.class, HttpStatus.CONFLICT,
            ConcurrentUpdateException.class, HttpStatus.CONFLICT);

    record FieldError(String field, String message) {}

    @ExceptionHandler(DomainException.class)
    ProblemDetail domain(DomainException e) {
        ProblemDetail problem =
                problem(DOMAIN_STATUS.getOrDefault(e.getClass(), HttpStatus.UNPROCESSABLE_ENTITY), e.code(), e.getMessage());
        if (e instanceof DuplicateStatementException duplicate) {
            problem.setProperty("existingRunId", duplicate.existingRunId());
        }
        return problem;
    }

    @ExceptionHandler(InvalidStatementException.class)
    ProblemDetail invalidStatement(InvalidStatementException e) {
        ProblemDetail problem = problem(HttpStatus.UNPROCESSABLE_ENTITY, "invalid-statement", e.getMessage());
        problem.setProperty("errors", e.errors());
        return problem;
    }

    @ExceptionHandler(LedgerUnavailableException.class)
    ProblemDetail ledgerUnavailable(LedgerUnavailableException e) {
        LOG.warn("Ledger unavailable: {}", e.getMessage());
        return problem(HttpStatus.BAD_GATEWAY, "ledger-unavailable", "The ledger could not be read: " + e.getMessage());
    }

    @ExceptionHandler({StatementTooLargeException.class, MaxUploadSizeExceededException.class})
    ProblemDetail tooLarge() {
        return problem(HttpStatus.CONTENT_TOO_LARGE, "statement-too-large", "A statement may be at most 5 MB.");
    }

    @ExceptionHandler(MethodArgumentNotValidException.class)
    ProblemDetail invalidBody(MethodArgumentNotValidException e) {
        return validation(e.getBindingResult().getFieldErrors().stream()
                .map(error -> new FieldError(error.getField(), error.getDefaultMessage()))
                .toList());
    }

    @ExceptionHandler(MissingServletRequestParameterException.class)
    ProblemDetail missingParameter(MissingServletRequestParameterException e) {
        return validation(List.of(new FieldError(e.getParameterName(), "is required")));
    }

    @ExceptionHandler(MissingServletRequestPartException.class)
    ProblemDetail missingPart(MissingServletRequestPartException e) {
        return validation(List.of(new FieldError(e.getRequestPartName(), "is required")));
    }

    @ExceptionHandler(MethodArgumentTypeMismatchException.class)
    ProblemDetail typeMismatch(MethodArgumentTypeMismatchException e) {
        return validation(List.of(new FieldError(e.getName(), "has an invalid value")));
    }

    @ExceptionHandler(HttpMessageNotReadableException.class)
    ProblemDetail unreadable() {
        return validation(List.of(new FieldError(null, "The request body is not valid JSON for this endpoint.")));
    }

    @ExceptionHandler(MultipartException.class)
    ProblemDetail badMultipart() {
        return validation(List.of(new FieldError("file", "is required as a multipart/form-data part")));
    }

    @ExceptionHandler(NoResourceFoundException.class)
    ProblemDetail notFound() {
        return problem(HttpStatus.NOT_FOUND, "not-found", "No such endpoint.");
    }

    @ExceptionHandler(HttpRequestMethodNotSupportedException.class)
    ProblemDetail methodNotAllowed(HttpRequestMethodNotSupportedException e) {
        return problem(HttpStatus.METHOD_NOT_ALLOWED, "method-not-allowed", e.getMessage());
    }

    @ExceptionHandler(HttpMediaTypeNotSupportedException.class)
    ProblemDetail unsupportedMediaType(HttpMediaTypeNotSupportedException e) {
        return problem(HttpStatus.UNSUPPORTED_MEDIA_TYPE, "unsupported-media-type", e.getMessage());
    }

    @ExceptionHandler(Exception.class)
    ProblemDetail unexpected(Exception e) {
        LOG.error("Unexpected error", e);
        return problem(HttpStatus.INTERNAL_SERVER_ERROR, "internal-error", "An unexpected error occurred.");
    }

    private static ProblemDetail validation(List<FieldError> errors) {
        ProblemDetail problem = problem(HttpStatus.UNPROCESSABLE_ENTITY, "validation-failed", "The request is invalid.");
        problem.setProperty("errors", errors);
        return problem;
    }

    private static ProblemDetail problem(HttpStatus status, String code, String detail) {
        ProblemDetail problem = ProblemDetail.forStatusAndDetail(status, detail);
        problem.setType(URI.create(TYPE_PREFIX + code));
        problem.setTitle(title(code));
        return problem;
    }

    private static String title(String code) {
        String words = code.replace('-', ' ');
        return Character.toUpperCase(words.charAt(0)) + words.substring(1);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec reconciliation-service ./mvnw -B verify -Dsurefire.skip=true -Dit.test='RunApiIT,JpaRunRepositoryIT,ApplicationIT' -Dspotless.check.skip=true -Dcheckstyle.skip=true`
Expected: PASS. Two framework details to check if a test fails:
- if `HttpStatus.CONTENT_TOO_LARGE` does not exist, use `HttpStatus.PAYLOAD_TOO_LARGE` (both are 413);
- if a missing multipart `file` surfaces as `MultipartException` instead of `MissingServletRequestPartException`, the `badMultipart` handler already maps it to 422 `validation-failed`; do not add a 400 path.

- [ ] **Step 5: Gates and commit**

```bash
docker compose exec reconciliation-service ./mvnw -B spotless:apply
make recon-test
git add services/reconciliation-service
git commit -m "feat(reconciliation-service): expose runs and resolutions over HTTP with problem details"
```

---

### Task 10: Documentation, live smoke test and final verification

**Files:**
- Create: `services/reconciliation-service/README.md`
- Modify: none (verification only), unless the smoke test finds a defect

**Interfaces:**
- Consumes: the whole service, ledger-service's live API.

- [ ] **Step 1: Write the README**

`services/reconciliation-service/README.md`:

````markdown
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
````

- [ ] **Step 2: Live smoke test against the real ledger**

```bash
make up-ledger-service && make up-reconciliation-service
SID=0199a000-0000-7000-8000-000000000840
A=$(curl -s -XPOST localhost:8081/accounts -H 'content-type: application/json' -d '{"name":"Recon smoke","currency":"USD"}' | sed 's/.*"id":"\([^"]*\)".*/\1/')
K=$(date +%s%N)
DEP=$(curl -s -XPOST localhost:8081/accounts/$A/deposits -H 'content-type: application/json' -H "Idempotency-Key: rs-dep-$K" -d '{"amount":"250.00","description":"Payroll"}' | sed 's/.*"id":"\([^"]*\)".*/\1/')
curl -s -XPOST localhost:8081/accounts/$A/withdrawals -H 'content-type: application/json' -H "Idempotency-Key: rs-wd-$K" -d '{"amount":"40.00","description":"ATM"}' >/dev/null
TODAY=$(date -u +%F)
printf 'date,direction,description,reference,currency,amount\n%s,CR,Payroll,%s,USD,250.00\n%s,DR,ATM,,USD,40.00\n%s,CR,Unknown,,USD,9.99\n' "$TODAY" "$DEP" "$TODAY" "$TODAY" > /tmp/recon-smoke.csv
RUN=$(curl -s -F file=@/tmp/recon-smoke.csv -F currency=USD -F from=$TODAY -F to=$TODAY localhost:8083/runs)
echo "$RUN"
curl -s "localhost:8083/runs/$(echo "$RUN" | sed 's/.*"id":"\([^"]*\)".*/\1/')/items?unresolved=true"
curl -s localhost:8083/actuator/health
```

Expected:
- the run's counts have at least `MATCHED: 2` (the deposit by reference, the withdrawal fuzzily) and `MISSING_IN_LEDGER: 1` (the 9.99 line); any earlier deposits or withdrawals on today's USD settlement account appear as `MISSING_IN_BANK`
- the unresolved items include the 9.99 line
- health shows `db` and `ledger` both `UP`

- [ ] **Step 3: Production image smoke test**

```bash
docker build --target runtime -t reconciliation-service:runtime services/reconciliation-service
docker run -d --rm --name recon-prod-smoke --network ledger-platform_default \
  -e SPRING_DATASOURCE_URL=jdbc:postgresql://postgres:5432/reconciliation \
  -e SPRING_DATASOURCE_USERNAME=ledger -e SPRING_DATASOURCE_PASSWORD=ledger \
  -e LEDGER_BASE_URL=http://ledger-service reconciliation-service:runtime
sleep 20 && docker exec recon-prod-smoke wget -qO- http://localhost:8080/actuator/health
docker inspect --format '{{.State.Health.Status}}' recon-prod-smoke   # healthy
docker stop recon-prod-smoke
```

Expected: health `UP`, container `healthy`.

- [ ] **Step 4: Final gate and commit**

```bash
make recon-test
git add services/reconciliation-service/README.md
git commit -m "docs(reconciliation-service): add README with statement format, API and matching rules"
git log main..HEAD --format='%B' | grep -icE 'claude|co-authored|generated with'   # expect 0
```

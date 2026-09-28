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
        assertThat(loaded.items()).usingRecursiveFieldByFieldElementComparator().containsExactlyElementsOf(run.items());
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
        assertThat(reloaded.items())
                .extracting(ReconItem::resolution)
                .allSatisfy(resolution -> assertThat(resolution.note()).isEqualTo("same"));
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
            List<Future<Boolean>> results =
                    pool.invokeAll(List.<Callable<Boolean>>of(() -> trySave(first), () -> trySave(second)));
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
        ReconciliationRun original =
                newRun("9".repeat(64), T0, MatchOutcome.missingInLedger(bank(1, "2026-03-05", IN, "1.00", null)));
        ReconciliationRun racer =
                newRun("9".repeat(64), T0, MatchOutcome.missingInLedger(bank(1, "2026-03-05", IN, "1.00", null)));
        runs.save(original);

        assertThatThrownBy(() -> runs.save(racer))
                .isInstanceOf(DuplicateStatementException.class)
                .satisfies(e -> assertThat(((DuplicateStatementException) e).existingRunId())
                        .isEqualTo(original.id()));
        assertThat(runs.findIdByStatement(SupportedCurrency.USD, march(), "9".repeat(64)))
                .contains(original.id());
        assertThat(runs.findIdByStatement(SupportedCurrency.EUR, march(), "9".repeat(64)))
                .isEmpty();
    }

    @Test
    void listsNewestFirstWithFilters() {
        ReconciliationRun older =
                newRun("1".repeat(64), T0, MatchOutcome.missingInLedger(bank(1, "2026-03-05", IN, "1.00", null)));
        ReconciliationRun newer = newRun(
                "2".repeat(64),
                T0.plusSeconds(10),
                MatchOutcome.matched(
                        MatchMethod.FUZZY,
                        bank(1, "2026-03-05", IN, "1.00", null),
                        ledger(A, "2026-03-05T10:00:00Z", IN, "1.00")));
        runs.save(older);
        runs.save(newer);

        assertThat(runs.list(null, null)).extracting(ReconciliationRun::id).containsExactly(newer.id(), older.id());
        assertThat(runs.list(SupportedCurrency.USD, RunStatus.OPEN))
                .extracting(ReconciliationRun::id)
                .containsExactly(older.id());
        assertThat(runs.list(null, RunStatus.RECONCILED))
                .extracting(ReconciliationRun::id)
                .containsExactly(newer.id());
        assertThat(runs.list(SupportedCurrency.EUR, null)).isEmpty();
        assertThat(runs.findById(UUID.fromString("0199ffff-0000-7000-8000-000000000000")))
                .isEmpty();
    }

    @Test
    void itemKindsSurviveAsText() {
        runs.save(newRun("3".repeat(64), T0, MatchOutcome.missingInLedger(bank(1, "2026-03-05", IN, "1.00", null))));

        assertThat(jdbc.queryForObject("SELECT kind FROM reconciliation_items", String.class))
                .isEqualTo(ItemKind.MISSING_IN_LEDGER.name());
    }
}

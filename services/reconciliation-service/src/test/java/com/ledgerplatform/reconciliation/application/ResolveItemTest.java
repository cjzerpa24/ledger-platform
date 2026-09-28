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
                run.id(),
                item(ItemKind.MISSING_IN_LEDGER),
                ResolutionKind.MANUAL_MATCH,
                item(ItemKind.MISSING_IN_BANK),
                null));

        assertThat(result.status()).isEqualTo(RunStatus.RECONCILED);
        assertThat(new GetRun(runs).execute(run.id()).status()).isEqualTo(RunStatus.RECONCILED);
    }

    @Test
    void unknownRunIsNotFound() {
        UUID unknown = UUID.fromString("0199ffff-0000-7000-8000-000000000000");

        assertThatThrownBy(() ->
                        resolve.execute(new ResolveItem.Command(unknown, unknown, ResolutionKind.IGNORE, null, "x")))
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

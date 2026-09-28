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
                .satisfies(e -> assertThat(((DuplicateStatementException) e).existingRunId())
                        .isEqualTo(first.id()));
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
        ReconciliationRun run = start.execute(new StartReconciliation.Command(
                "JPY",
                FROM,
                TO,
                "date,direction,description,reference,currency,amount\n2026-03-05,CR,,,JPY,1500\n"
                        .getBytes(StandardCharsets.UTF_8)));

        assertThat(run.currency()).isEqualTo(SupportedCurrency.JPY);
    }
}

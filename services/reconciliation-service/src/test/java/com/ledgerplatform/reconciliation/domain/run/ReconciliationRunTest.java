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
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import java.time.Instant;
import java.time.LocalDate;
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
        ReconciliationRun run =
                start(MatchOutcome.ambiguous(inTen, List.of(ledgerInTen)), MatchOutcome.missingInBank(ledgerInTen));

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
    void manualMatchRejectsDifferentCurrenciesEvenWithANote() {
        BankLine eurTen = new BankLine(
                1, LocalDate.parse("2026-03-05"), IN, null, null, Money.parse("10.00", SupportedCurrency.EUR));
        ReconciliationRun run = start(MatchOutcome.missingInLedger(eurTen), MatchOutcome.missingInBank(ledgerInTen));
        UUID bankSide = itemId(run, ItemKind.MISSING_IN_LEDGER);
        UUID ledgerSide = itemId(run, ItemKind.MISSING_IN_BANK);

        assertThatThrownBy(() -> run.manualMatch(bankSide, ledgerSide, "explained", T1))
                .isInstanceOf(InvalidManualMatchException.class)
                .hasMessageContaining("currency");
        assertThat(run.unresolvedExceptions()).isEqualTo(2);
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

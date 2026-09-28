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
        List<LedgerEntry> ledger =
                List.of(ledger(A, "2026-03-01T10:00:00Z", IN, "10.00"), ledger(B, "2026-03-15T10:00:00Z", OUT, "7.00"));

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

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

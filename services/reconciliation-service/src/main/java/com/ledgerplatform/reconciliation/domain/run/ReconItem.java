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

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

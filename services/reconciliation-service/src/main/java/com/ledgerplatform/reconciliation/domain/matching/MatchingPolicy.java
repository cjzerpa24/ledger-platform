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

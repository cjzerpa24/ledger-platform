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

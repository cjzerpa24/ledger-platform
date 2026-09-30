package com.ledgerplatform.reconciliation.domain.statement;

import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import java.time.LocalDate;
import java.util.Objects;
import java.util.Optional;
import java.util.UUID;
import java.util.regex.Pattern;

/** One row of the bank statement. {@code row} is 1-based over data rows; description and reference may be null. */
public record BankLine(
        int row, LocalDate date, Direction direction, String description, String reference, Money amount) {

    private static final Pattern CANONICAL_UUID =
            Pattern.compile("^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$");

    public BankLine {
        if (row < 1) {
            throw new IllegalArgumentException("row is 1-based");
        }
        Objects.requireNonNull(date, "date");
        Objects.requireNonNull(direction, "direction");
        Objects.requireNonNull(amount, "amount");
    }

    /** The reference as a ledger transfer id, when it is a canonical UUID (any letter case). */
    public Optional<UUID> referenceAsUuid() {
        if (reference == null || !CANONICAL_UUID.matcher(reference).matches()) {
            return Optional.empty();
        }
        return Optional.of(UUID.fromString(reference));
    }
}

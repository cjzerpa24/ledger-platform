package com.ledgerplatform.reconciliation.domain.run;

import java.time.Instant;
import java.util.Objects;
import java.util.UUID;

/** How an operator settled an exception. {@code note} and {@code counterpartItemId} may be null. */
public record Resolution(ResolutionKind kind, String note, UUID counterpartItemId, Instant resolvedAt) {

    public Resolution {
        Objects.requireNonNull(kind, "kind");
        Objects.requireNonNull(resolvedAt, "resolvedAt");
    }
}

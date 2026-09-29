package com.ledgerplatform.reconciliation.infrastructure.web;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import java.time.Instant;
import java.time.LocalDate;
import java.util.Map;
import java.util.UUID;

public record RunResponse(
        UUID id,
        String currency,
        LocalDate from,
        LocalDate to,
        RunStatus status,
        Instant createdAt,
        Map<ItemKind, Long> counts,
        long unresolvedExceptions) {

    static RunResponse from(ReconciliationRun run) {
        return new RunResponse(
                run.id(),
                run.currency().name(),
                run.period().from(),
                run.period().to(),
                run.status(),
                run.createdAt(),
                run.counts(),
                run.unresolvedExceptions());
    }
}

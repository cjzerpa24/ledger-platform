package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;
import java.util.UUID;

public final class DuplicateStatementException extends DomainException {

    private final UUID existingRunId;

    public DuplicateStatementException(UUID existingRunId) {
        super("This statement was already reconciled for the same currency and period.");
        this.existingRunId = existingRunId;
    }

    public UUID existingRunId() {
        return existingRunId;
    }

    @Override
    public String code() {
        return "duplicate-statement";
    }
}

package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;
import java.util.UUID;

public final class ConcurrentUpdateException extends DomainException {

    public ConcurrentUpdateException(UUID runId) {
        super("Run %s was changed by someone else; reload it and try again.".formatted(runId));
    }

    @Override
    public String code() {
        return "concurrent-update";
    }
}

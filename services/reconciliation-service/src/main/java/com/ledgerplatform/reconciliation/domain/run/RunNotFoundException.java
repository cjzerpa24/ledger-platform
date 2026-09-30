package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;
import java.util.UUID;

public final class RunNotFoundException extends DomainException {

    public RunNotFoundException(UUID id) {
        super("Reconciliation run %s does not exist.".formatted(id));
    }

    @Override
    public String code() {
        return "run-not-found";
    }
}

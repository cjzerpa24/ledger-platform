package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;

public final class InvalidManualMatchException extends DomainException {

    public InvalidManualMatchException(String message) {
        super(message);
    }

    @Override
    public String code() {
        return "invalid-manual-match";
    }
}

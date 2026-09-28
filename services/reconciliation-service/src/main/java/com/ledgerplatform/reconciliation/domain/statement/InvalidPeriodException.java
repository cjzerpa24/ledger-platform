package com.ledgerplatform.reconciliation.domain.statement;

import com.ledgerplatform.reconciliation.domain.DomainException;

public final class InvalidPeriodException extends DomainException {

    public InvalidPeriodException(String message) {
        super(message);
    }

    @Override
    public String code() {
        return "invalid-period";
    }
}

package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.DomainException;

public final class InvalidResolutionException extends DomainException {

    public InvalidResolutionException(String message) {
        super(message);
    }

    @Override
    public String code() {
        return "validation-failed";
    }
}

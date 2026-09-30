package com.ledgerplatform.reconciliation.domain.money;

import com.ledgerplatform.reconciliation.domain.DomainException;

public final class InvalidMoneyException extends DomainException {

    public InvalidMoneyException(String message) {
        super(message);
    }

    @Override
    public String code() {
        return "invalid-amount";
    }
}

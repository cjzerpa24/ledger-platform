package com.ledgerplatform.reconciliation.domain.money;

import com.ledgerplatform.reconciliation.domain.DomainException;

public final class UnsupportedCurrencyException extends DomainException {

    public UnsupportedCurrencyException(String code) {
        super("Unsupported currency \"%s\". Expected one of USD, EUR, GBP, TZS, JPY.".formatted(code));
    }

    @Override
    public String code() {
        return "unsupported-currency";
    }
}

package com.ledgerplatform.reconciliation.domain;

/** A business rule violation. Carries a stable code; the web layer maps it to an HTTP status. */
public abstract class DomainException extends RuntimeException {

    protected DomainException(String message) {
        super(message);
    }

    public abstract String code();
}

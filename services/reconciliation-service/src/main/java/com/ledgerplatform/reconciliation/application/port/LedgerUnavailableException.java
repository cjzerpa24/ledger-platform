package com.ledgerplatform.reconciliation.application.port;

/** The ledger could not provide a usable statement: unreachable, an error status, or an unreadable body. */
public class LedgerUnavailableException extends RuntimeException {

    public LedgerUnavailableException(String message) {
        super(message);
    }
}

package com.ledgerplatform.reconciliation.infrastructure.web;

public class StatementTooLargeException extends RuntimeException {

    public StatementTooLargeException(long maxBytes) {
        super("A statement may be at most %d MB.".formatted(maxBytes / (1024 * 1024)));
    }
}

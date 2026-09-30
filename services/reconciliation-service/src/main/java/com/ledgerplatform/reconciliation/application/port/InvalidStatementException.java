package com.ledgerplatform.reconciliation.application.port;

import java.util.List;

public class InvalidStatementException extends RuntimeException {

    private final transient List<RowError> errors;

    public InvalidStatementException(List<RowError> errors) {
        super("The statement is invalid (%d problem(s)).".formatted(errors.size()));
        this.errors = List.copyOf(errors);
    }

    public List<RowError> errors() {
        return errors;
    }
}

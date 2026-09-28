package com.ledgerplatform.reconciliation.application.port;

/** A problem in the uploaded statement. {@code row} (1-based data row) and {@code field} are null for file-level problems. */
public record RowError(Integer row, String field, String message) {}

package com.ledgerplatform.reconciliation.application.port;

import java.util.UUID;

public interface IdGenerator {

    /** A new time-ordered id (UUID v7). */
    UUID next();
}

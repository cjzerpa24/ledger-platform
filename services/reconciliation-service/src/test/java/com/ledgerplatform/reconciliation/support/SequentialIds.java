package com.ledgerplatform.reconciliation.support;

import com.ledgerplatform.reconciliation.application.port.IdGenerator;
import java.util.UUID;
import java.util.concurrent.atomic.AtomicLong;

public final class SequentialIds implements IdGenerator {

    private final AtomicLong next = new AtomicLong(1);

    @Override
    public UUID next() {
        return new UUID(0x0199a00000007000L, 0x8000000000000000L + next.getAndIncrement());
    }
}

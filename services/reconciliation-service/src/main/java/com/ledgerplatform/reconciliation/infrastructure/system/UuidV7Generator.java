package com.ledgerplatform.reconciliation.infrastructure.system;

import com.fasterxml.uuid.Generators;
import com.fasterxml.uuid.impl.TimeBasedEpochGenerator;
import com.ledgerplatform.reconciliation.application.port.IdGenerator;
import java.util.UUID;

public final class UuidV7Generator implements IdGenerator {

    private final TimeBasedEpochGenerator generator = Generators.timeBasedEpochGenerator();

    @Override
    public UUID next() {
        return generator.generate();
    }
}

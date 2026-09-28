package com.ledgerplatform.reconciliation.application;

import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunNotFoundException;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import java.util.UUID;

public final class GetRun {

    private final RunRepository runs;

    public GetRun(RunRepository runs) {
        this.runs = runs;
    }

    public ReconciliationRun execute(UUID runId) {
        return runs.findById(runId).orElseThrow(() -> new RunNotFoundException(runId));
    }
}

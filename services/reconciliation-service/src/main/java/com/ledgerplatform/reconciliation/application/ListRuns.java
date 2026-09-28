package com.ledgerplatform.reconciliation.application;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import java.util.List;

public final class ListRuns {

    private final RunRepository runs;

    public ListRuns(RunRepository runs) {
        this.runs = runs;
    }

    /** Newest first, at most 50. Null filters match everything. */
    public List<ReconciliationRun> execute(String currency, RunStatus status) {
        return runs.list(currency == null ? null : SupportedCurrency.of(currency), status);
    }
}

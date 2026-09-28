package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.util.List;
import java.util.Optional;
import java.util.UUID;

public interface RunRepository {

    /**
     * Inserts a new run, or stores the status and resolutions of an existing one.
     *
     * @throws DuplicateStatementException when the same statement already has a run for the currency and period
     * @throws ConcurrentUpdateException when the run changed since it was loaded
     */
    void save(ReconciliationRun run);

    Optional<ReconciliationRun> findById(UUID id);

    Optional<UUID> findIdByStatement(SupportedCurrency currency, Period period, String statementSha256);

    /** Newest first, at most 50. A null filter matches everything. */
    List<ReconciliationRun> list(SupportedCurrency currency, RunStatus status);
}

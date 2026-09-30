package com.ledgerplatform.reconciliation.infrastructure.persistence;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.run.ConcurrentUpdateException;
import com.ledgerplatform.reconciliation.domain.run.DuplicateStatementException;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.util.List;
import java.util.Optional;
import java.util.UUID;
import org.springframework.dao.DataIntegrityViolationException;
import org.springframework.dao.OptimisticLockingFailureException;
import org.springframework.transaction.PlatformTransactionManager;
import org.springframework.transaction.support.TransactionTemplate;

/**
 * Stores runs with Spring Data JPA. Each call is its own transaction, and the exceptions a commit can raise are
 * translated into the domain's {@link ConcurrentUpdateException} and {@link DuplicateStatementException}.
 */
public final class JpaRunRepository implements RunRepository {

    private final RunJpaRepository runs;
    private final TransactionTemplate writes;
    private final TransactionTemplate reads;
    private final RunMapper mapper = new RunMapper();

    public JpaRunRepository(RunJpaRepository runs, PlatformTransactionManager transactionManager) {
        this.runs = runs;
        this.writes = new TransactionTemplate(transactionManager);
        this.reads = new TransactionTemplate(transactionManager);
        this.reads.setReadOnly(true);
    }

    @Override
    public void save(ReconciliationRun run) {
        try {
            writes.executeWithoutResult(status -> {
                Optional<RunEntity> existing = runs.findForUpdate(run.id());
                if (existing.isEmpty()) {
                    runs.saveAndFlush(mapper.newEntity(run));
                    return;
                }
                RunEntity entity = existing.get();
                if (entity.version != run.version()) {
                    throw new ConcurrentUpdateException(run.id());
                }
                mapper.applyChanges(run, entity);
                runs.saveAndFlush(entity);
            });
        } catch (OptimisticLockingFailureException e) {
            throw new ConcurrentUpdateException(run.id());
        } catch (DataIntegrityViolationException e) {
            UUID existing = findIdByStatement(run.currency(), run.period(), run.statementSha256())
                    .orElseThrow(() -> e);
            throw new DuplicateStatementException(existing);
        }
    }

    @Override
    public Optional<ReconciliationRun> findById(UUID id) {
        return reads.execute(status -> runs.findById(id).map(mapper::toDomain));
    }

    @Override
    public Optional<UUID> findIdByStatement(SupportedCurrency currency, Period period, String statementSha256) {
        return reads.execute(status -> runs.findByCurrencyAndPeriodFromAndPeriodToAndStatementSha256(
                        currency.name(), period.from(), period.to(), statementSha256)
                .map(entity -> entity.id));
    }

    @Override
    public List<ReconciliationRun> list(SupportedCurrency currency, RunStatus status) {
        return reads.execute(tx -> {
            List<RunEntity> rows;
            if (currency != null && status != null) {
                rows = runs.findTop50ByCurrencyAndStatusOrderByCreatedAtDesc(currency.name(), status.name());
            } else if (currency != null) {
                rows = runs.findTop50ByCurrencyOrderByCreatedAtDesc(currency.name());
            } else if (status != null) {
                rows = runs.findTop50ByStatusOrderByCreatedAtDesc(status.name());
            } else {
                rows = runs.findTop50ByOrderByCreatedAtDesc();
            }
            return rows.stream().map(mapper::toDomain).toList();
        });
    }
}

package com.ledgerplatform.reconciliation.support;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.run.ConcurrentUpdateException;
import com.ledgerplatform.reconciliation.domain.run.DuplicateStatementException;
import com.ledgerplatform.reconciliation.domain.run.ReconItem;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.util.Comparator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.UUID;

/** Stores copies, bumps versions on update and enforces the duplicate-statement constraint, like the JPA adapter. */
public final class InMemoryRunRepository implements RunRepository {

    private final Map<UUID, ReconciliationRun> rows = new LinkedHashMap<>();

    @Override
    public void save(ReconciliationRun run) {
        ReconciliationRun existing = rows.get(run.id());
        if (existing == null) {
            findIdByStatement(run.currency(), run.period(), run.statementSha256())
                    .ifPresent(id -> {
                        throw new DuplicateStatementException(id);
                    });
            rows.put(run.id(), copy(run, 0));
            return;
        }
        if (existing.version() != run.version()) {
            throw new ConcurrentUpdateException(run.id());
        }
        rows.put(run.id(), copy(run, run.version() + 1));
    }

    @Override
    public Optional<ReconciliationRun> findById(UUID id) {
        return Optional.ofNullable(rows.get(id)).map(run -> copy(run, run.version()));
    }

    @Override
    public Optional<UUID> findIdByStatement(SupportedCurrency currency, Period period, String statementSha256) {
        return rows.values().stream()
                .filter(run -> run.currency() == currency
                        && run.period().equals(period)
                        && run.statementSha256().equals(statementSha256))
                .map(ReconciliationRun::id)
                .findFirst();
    }

    @Override
    public List<ReconciliationRun> list(SupportedCurrency currency, RunStatus status) {
        return rows.values().stream()
                .filter(run -> currency == null || run.currency() == currency)
                .filter(run -> status == null || run.status() == status)
                .sorted(Comparator.comparing(ReconciliationRun::createdAt).reversed())
                .limit(50)
                .map(run -> copy(run, run.version()))
                .toList();
    }

    public int size() {
        return rows.size();
    }

    private static ReconciliationRun copy(ReconciliationRun run, long version) {
        List<ReconItem> items = run.items().stream()
                .map(item -> new ReconItem(
                        item.id(),
                        item.position(),
                        item.kind(),
                        item.matchMethod(),
                        item.bankLine(),
                        item.ledgerEntry(),
                        item.candidates(),
                        item.resolution()))
                .toList();
        return ReconciliationRun.restore(
                run.id(),
                run.currency(),
                run.period(),
                run.statementSha256(),
                run.status(),
                run.createdAt(),
                version,
                items);
    }
}

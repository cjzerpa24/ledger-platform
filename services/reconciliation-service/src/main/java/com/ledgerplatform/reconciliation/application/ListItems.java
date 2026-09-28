package com.ledgerplatform.reconciliation.application;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.run.ReconItem;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import java.util.List;
import java.util.UUID;

public final class ListItems {

    private final GetRun getRun;

    public ListItems(RunRepository runs) {
        this.getRun = new GetRun(runs);
    }

    /** Items in matcher order. {@code unresolved = true} keeps open exceptions only; {@code false} keeps the rest. */
    public List<ReconItem> execute(UUID runId, ItemKind kind, Boolean unresolved) {
        return getRun.execute(runId).items().stream()
                .filter(item -> kind == null || item.kind() == kind)
                .filter(item -> unresolved == null || item.isUnresolvedException() == unresolved)
                .toList();
    }
}

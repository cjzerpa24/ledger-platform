package com.ledgerplatform.reconciliation.application;

import com.ledgerplatform.reconciliation.domain.run.InvalidResolutionException;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.ResolutionKind;
import com.ledgerplatform.reconciliation.domain.run.RunNotFoundException;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import java.time.Clock;
import java.util.UUID;

public final class ResolveItem {

    public record Command(UUID runId, UUID itemId, ResolutionKind resolution, UUID counterpartItemId, String note) {}

    private final RunRepository runs;
    private final Clock clock;

    public ResolveItem(RunRepository runs, Clock clock) {
        this.runs = runs;
        this.clock = clock;
    }

    public ReconciliationRun execute(Command command) {
        ReconciliationRun run =
                runs.findById(command.runId()).orElseThrow(() -> new RunNotFoundException(command.runId()));
        if (command.resolution() == null) {
            throw new InvalidResolutionException("A resolution is required.");
        }
        switch (command.resolution()) {
            case IGNORE -> run.ignore(command.itemId(), command.note(), clock.instant());
            case MANUAL_MATCH ->
                run.manualMatch(command.itemId(), command.counterpartItemId(), command.note(), clock.instant());
        }
        runs.save(run);
        return run;
    }
}

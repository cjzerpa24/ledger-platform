package com.ledgerplatform.reconciliation.infrastructure.persistence;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.matching.MatchMethod;
import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.run.ReconItem;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.Resolution;
import com.ledgerplatform.reconciliation.domain.run.ResolutionKind;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.time.Instant;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.function.Function;
import java.util.stream.Collectors;
import tools.jackson.core.type.TypeReference;
import tools.jackson.databind.json.JsonMapper;

/** Translates between the aggregate and its rows. Items are immutable apart from their resolution. */
final class RunMapper {

    private static final JsonMapper JSON = JsonMapper.builder().build();

    record CandidateJson(
            UUID transferId, Instant occurredAt, Direction direction, long amountMinor, String description) {}

    ReconciliationRun toDomain(RunEntity entity) {
        SupportedCurrency currency = SupportedCurrency.valueOf(entity.currency);
        List<ReconItem> items =
                entity.items.stream().map(item -> toDomain(item, currency)).toList();
        return ReconciliationRun.restore(
                entity.id,
                currency,
                new Period(entity.periodFrom, entity.periodTo),
                entity.statementSha256,
                RunStatus.valueOf(entity.status),
                entity.createdAt,
                entity.version,
                items);
    }

    RunEntity newEntity(ReconciliationRun run) {
        RunEntity entity = new RunEntity();
        entity.id = run.id();
        entity.currency = run.currency().name();
        entity.periodFrom = run.period().from();
        entity.periodTo = run.period().to();
        entity.statementSha256 = run.statementSha256();
        entity.status = run.status().name();
        entity.createdAt = run.createdAt();
        entity.version = null;
        for (ReconItem item : run.items()) {
            entity.items.add(newItem(entity, item));
        }
        return entity;
    }

    /** Copies what can change after creation: the run status and each item's resolution. */
    void applyChanges(ReconciliationRun run, RunEntity entity) {
        entity.status = run.status().name();
        Map<UUID, ReconItem> byId = run.items().stream().collect(Collectors.toMap(ReconItem::id, Function.identity()));
        for (ItemEntity row : entity.items) {
            Resolution resolution = byId.get(row.id).resolution();
            row.resolutionKind = resolution == null ? null : resolution.kind().name();
            row.resolutionNote = resolution == null ? null : resolution.note();
            row.counterpartItemId = resolution == null ? null : resolution.counterpartItemId();
            row.resolvedAt = resolution == null ? null : resolution.resolvedAt();
        }
    }

    private static ItemEntity newItem(RunEntity run, ReconItem item) {
        ItemEntity row = new ItemEntity();
        row.id = item.id();
        row.run = run;
        row.position = item.position();
        row.kind = item.kind().name();
        row.matchMethod = item.matchMethod() == null ? null : item.matchMethod().name();
        BankLine bank = item.bankLine();
        if (bank != null) {
            row.bankRow = bank.row();
            row.bankDate = bank.date();
            row.bankDirection = bank.direction().name();
            row.bankDescription = bank.description();
            row.bankReference = bank.reference();
            row.bankAmountMinor = bank.amount().minor();
        }
        LedgerEntry ledger = item.ledgerEntry();
        if (ledger != null) {
            row.ledgerTransferId = ledger.transferId();
            row.ledgerOccurredAt = ledger.occurredAt();
            row.ledgerDirection = ledger.direction().name();
            row.ledgerAmountMinor = ledger.amount().minor();
            row.ledgerDescription = ledger.description();
        }
        if (!item.candidates().isEmpty()) {
            row.candidates = JSON.writeValueAsString(item.candidates().stream()
                    .map(c -> new CandidateJson(
                            c.transferId(),
                            c.occurredAt(),
                            c.direction(),
                            c.amount().minor(),
                            c.description()))
                    .toList());
        }
        Resolution resolution = item.resolution();
        if (resolution != null) {
            row.resolutionKind = resolution.kind().name();
            row.resolutionNote = resolution.note();
            row.counterpartItemId = resolution.counterpartItemId();
            row.resolvedAt = resolution.resolvedAt();
        }
        return row;
    }

    private static ReconItem toDomain(ItemEntity row, SupportedCurrency currency) {
        BankLine bank = row.bankRow == null
                ? null
                : new BankLine(
                        row.bankRow,
                        row.bankDate,
                        Direction.valueOf(row.bankDirection),
                        row.bankDescription,
                        row.bankReference,
                        new Money(row.bankAmountMinor, currency));
        LedgerEntry ledger = row.ledgerTransferId == null
                ? null
                : new LedgerEntry(
                        row.ledgerTransferId,
                        row.ledgerOccurredAt,
                        Direction.valueOf(row.ledgerDirection),
                        new Money(row.ledgerAmountMinor, currency),
                        row.ledgerDescription);
        List<LedgerEntry> candidates = row.candidates == null
                ? List.of()
                : JSON.readValue(row.candidates, new TypeReference<List<CandidateJson>>() {}).stream()
                        .map(c -> new LedgerEntry(
                                c.transferId(),
                                c.occurredAt(),
                                c.direction(),
                                new Money(c.amountMinor(), currency),
                                c.description()))
                        .toList();
        Resolution resolution = row.resolutionKind == null
                ? null
                : new Resolution(
                        ResolutionKind.valueOf(row.resolutionKind),
                        row.resolutionNote,
                        row.counterpartItemId,
                        row.resolvedAt);
        return new ReconItem(
                row.id,
                row.position,
                ItemKind.valueOf(row.kind),
                row.matchMethod == null ? null : MatchMethod.valueOf(row.matchMethod),
                bank,
                ledger,
                candidates,
                resolution);
    }
}

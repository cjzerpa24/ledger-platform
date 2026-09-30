package com.ledgerplatform.reconciliation.infrastructure.web;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.matching.MatchMethod;
import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.run.ReconItem;
import com.ledgerplatform.reconciliation.domain.run.Resolution;
import com.ledgerplatform.reconciliation.domain.run.ResolutionKind;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import java.time.Instant;
import java.time.LocalDate;
import java.util.List;
import java.util.UUID;

/** Amounts are decimal strings, never JSON numbers. */
public record ItemResponse(
        UUID id,
        int position,
        ItemKind kind,
        MatchMethod matchMethod,
        BankLineView bankLine,
        LedgerEntryView ledgerEntry,
        List<LedgerEntryView> candidates,
        ResolutionView resolution) {

    public record BankLineView(
            int row, LocalDate date, Direction direction, String description, String reference, String amount) {}

    public record LedgerEntryView(
            UUID transferId, Instant occurredAt, Direction direction, String amount, String description) {}

    public record ResolutionView(ResolutionKind kind, String note, UUID counterpartItemId, Instant resolvedAt) {}

    static ItemResponse from(ReconItem item) {
        return new ItemResponse(
                item.id(),
                item.position(),
                item.kind(),
                item.matchMethod(),
                view(item.bankLine()),
                view(item.ledgerEntry()),
                item.candidates().stream().map(ItemResponse::view).toList(),
                view(item.resolution()));
    }

    private static BankLineView view(BankLine line) {
        return line == null
                ? null
                : new BankLineView(
                        line.row(),
                        line.date(),
                        line.direction(),
                        line.description(),
                        line.reference(),
                        line.amount().toDecimalString());
    }

    private static LedgerEntryView view(LedgerEntry entry) {
        return entry == null
                ? null
                : new LedgerEntryView(
                        entry.transferId(),
                        entry.occurredAt(),
                        entry.direction(),
                        entry.amount().toDecimalString(),
                        entry.description());
    }

    private static ResolutionView view(Resolution resolution) {
        return resolution == null
                ? null
                : new ResolutionView(
                        resolution.kind(), resolution.note(), resolution.counterpartItemId(), resolution.resolvedAt());
    }
}

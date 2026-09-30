package com.ledgerplatform.reconciliation.domain.run;

import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.matching.MatchOutcome;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.time.Instant;
import java.util.ArrayList;
import java.util.Collections;
import java.util.EnumMap;
import java.util.List;
import java.util.Map;
import java.util.Objects;
import java.util.UUID;
import java.util.function.Supplier;

/** Aggregate root: one reconciliation of a bank statement against the ledger, and its resolutions. */
public final class ReconciliationRun {

    private static final int MAX_NOTE_LENGTH = 500;

    private final UUID id;
    private final SupportedCurrency currency;
    private final Period period;
    private final String statementSha256;
    private final Instant createdAt;
    private final long version;
    private final List<ReconItem> items;
    private RunStatus status;

    private ReconciliationRun(
            UUID id,
            SupportedCurrency currency,
            Period period,
            String statementSha256,
            RunStatus status,
            Instant createdAt,
            long version,
            List<ReconItem> items) {
        this.id = Objects.requireNonNull(id, "id");
        this.currency = Objects.requireNonNull(currency, "currency");
        this.period = Objects.requireNonNull(period, "period");
        this.statementSha256 = Objects.requireNonNull(statementSha256, "statementSha256");
        this.status = Objects.requireNonNull(status, "status");
        this.createdAt = Objects.requireNonNull(createdAt, "createdAt");
        this.version = version;
        this.items = List.copyOf(items);
    }

    public static ReconciliationRun start(
            UUID id,
            SupportedCurrency currency,
            Period period,
            String statementSha256,
            List<MatchOutcome> outcomes,
            Supplier<UUID> itemIds,
            Instant now) {
        List<ReconItem> items = new ArrayList<>();
        for (int position = 0; position < outcomes.size(); position++) {
            items.add(ReconItem.from(itemIds.get(), position, outcomes.get(position)));
        }
        ReconciliationRun run =
                new ReconciliationRun(id, currency, period, statementSha256, RunStatus.OPEN, now, 0, items);
        run.refreshStatus();
        return run;
    }

    public static ReconciliationRun restore(
            UUID id,
            SupportedCurrency currency,
            Period period,
            String statementSha256,
            RunStatus status,
            Instant createdAt,
            long version,
            List<ReconItem> items) {
        return new ReconciliationRun(id, currency, period, statementSha256, status, createdAt, version, items);
    }

    /** Accepts an exception as is. A note explaining why is required. */
    public void ignore(UUID itemId, String note, Instant now) {
        ReconItem item = unresolvedException(itemId);
        String text = optionalNote(note);
        if (text == null) {
            throw new InvalidResolutionException("A note is required to ignore an item.");
        }
        item.resolve(new Resolution(ResolutionKind.IGNORE, text, null, now));
        refreshStatus();
    }

    /**
     * Pairs a bank-side exception ({@code MISSING_IN_LEDGER} or {@code AMBIGUOUS}) with a {@code MISSING_IN_BANK}
     * item, in either argument order. Different amounts are allowed only with a note.
     */
    public void manualMatch(UUID itemId, UUID counterpartItemId, String note, Instant now) {
        if (counterpartItemId == null) {
            throw new InvalidManualMatchException("A manual match needs counterpartItemId.");
        }
        ReconItem first = unresolvedException(itemId);
        ReconItem second = unresolvedException(counterpartItemId);
        ReconItem ledgerSide = first.kind() == ItemKind.MISSING_IN_BANK ? first : second;
        ReconItem bankSide = ledgerSide == first ? second : first;
        boolean bankKindOk = bankSide.kind() == ItemKind.MISSING_IN_LEDGER || bankSide.kind() == ItemKind.AMBIGUOUS;
        if (first == second || ledgerSide.kind() != ItemKind.MISSING_IN_BANK || !bankKindOk) {
            throw new InvalidManualMatchException(
                    "A manual match pairs one MISSING_IN_LEDGER or AMBIGUOUS item with one MISSING_IN_BANK item.");
        }
        if (bankSide.bankLine().direction() != ledgerSide.ledgerEntry().direction()) {
            throw new InvalidManualMatchException("Both items must move money in the same direction.");
        }
        if (bankSide.bankLine().amount().currency()
                != ledgerSide.ledgerEntry().amount().currency()) {
            throw new InvalidManualMatchException("Both items must be in the same currency.");
        }
        String text = optionalNote(note);
        if (!bankSide.bankLine().amount().equals(ledgerSide.ledgerEntry().amount()) && text == null) {
            throw new InvalidManualMatchException("The amounts differ; explain the match in a note.");
        }
        bankSide.resolve(new Resolution(ResolutionKind.MANUAL_MATCH, text, ledgerSide.id(), now));
        ledgerSide.resolve(new Resolution(ResolutionKind.MANUAL_MATCH, text, bankSide.id(), now));
        refreshStatus();
    }

    public Map<ItemKind, Long> counts() {
        Map<ItemKind, Long> counts = new EnumMap<>(ItemKind.class);
        for (ItemKind kind : ItemKind.values()) {
            counts.put(kind, 0L);
        }
        for (ReconItem item : items) {
            counts.merge(item.kind(), 1L, Long::sum);
        }
        return Collections.unmodifiableMap(counts);
    }

    public long unresolvedExceptions() {
        return items.stream().filter(ReconItem::isUnresolvedException).count();
    }

    public UUID id() {
        return id;
    }

    public SupportedCurrency currency() {
        return currency;
    }

    public Period period() {
        return period;
    }

    public String statementSha256() {
        return statementSha256;
    }

    public RunStatus status() {
        return status;
    }

    public Instant createdAt() {
        return createdAt;
    }

    /** Optimistic-locking version as loaded; 0 for a run that was never saved. */
    public long version() {
        return version;
    }

    public List<ReconItem> items() {
        return items;
    }

    private ReconItem unresolvedException(UUID itemId) {
        ReconItem item = items.stream()
                .filter(candidate -> candidate.id().equals(itemId))
                .findFirst()
                .orElseThrow(() -> new ItemNotFoundException(itemId));
        if (!item.isUnresolvedException()) {
            throw new ItemAlreadyResolvedException(itemId);
        }
        return item;
    }

    private static String optionalNote(String note) {
        if (note == null || note.isBlank()) {
            return null;
        }
        String text = note.strip();
        if (text.length() > MAX_NOTE_LENGTH) {
            throw new InvalidResolutionException("A note may be at most %d characters.".formatted(MAX_NOTE_LENGTH));
        }
        return text;
    }

    private void refreshStatus() {
        status = unresolvedExceptions() == 0 ? RunStatus.RECONCILED : RunStatus.OPEN;
    }
}

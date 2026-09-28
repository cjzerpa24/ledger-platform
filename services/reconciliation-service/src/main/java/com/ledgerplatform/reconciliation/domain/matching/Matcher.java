package com.ledgerplatform.reconciliation.domain.matching;

import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.Set;
import java.util.UUID;

/**
 * Pairs bank lines with ledger entries: by reference first, then by a fuzzy match that must be unique on both
 * sides. Ambiguity is reported, never guessed. Pure and deterministic.
 */
public final class Matcher {

    private static final Comparator<BankLine> BANK_ORDER =
            Comparator.comparing(BankLine::date).thenComparingInt(BankLine::row);
    private static final Comparator<LedgerEntry> LEDGER_ORDER =
            Comparator.comparing(LedgerEntry::occurredAt).thenComparing(LedgerEntry::transferId);

    /**
     * Returns one outcome per bank line in (date, row) order, then one {@code MISSING_IN_BANK} outcome per unpaired
     * ledger entry dated inside {@code period}, in (occurredAt, transferId) order.
     *
     * @throws IllegalArgumentException if ledgerEntries contains duplicate transferIds
     * @precondition ledgerEntries must have distinct transferIds
     */
    public List<MatchOutcome> match(
            List<BankLine> bankLines, List<LedgerEntry> ledgerEntries, Period period, MatchingPolicy policy) {
        validateNoDuplicateTransferIds(ledgerEntries);
        List<BankLine> bank = bankLines.stream().sorted(BANK_ORDER).toList();
        List<LedgerEntry> ledger = ledgerEntries.stream().sorted(LEDGER_ORDER).toList();
        Map<BankLine, MatchOutcome> outcomes = new HashMap<>();
        Set<UUID> paired = new HashSet<>();

        matchByReference(bank, ledger, outcomes, paired);
        matchFuzzy(bank, ledger, policy, outcomes, paired);

        List<MatchOutcome> result = new ArrayList<>();
        for (BankLine line : bank) {
            result.add(outcomes.getOrDefault(line, MatchOutcome.missingInLedger(line)));
        }
        for (LedgerEntry entry : ledger) {
            if (!paired.contains(entry.transferId()) && period.contains(entry.date())) {
                result.add(MatchOutcome.missingInBank(entry));
            }
        }
        return List.copyOf(result);
    }

    private static void matchByReference(
            List<BankLine> bank, List<LedgerEntry> ledger, Map<BankLine, MatchOutcome> outcomes, Set<UUID> paired) {
        Map<UUID, LedgerEntry> byTransferId = new HashMap<>();
        for (LedgerEntry entry : ledger) {
            byTransferId.putIfAbsent(entry.transferId(), entry);
        }
        for (BankLine line : bank) {
            Optional<LedgerEntry> hit =
                    line.referenceAsUuid().map(byTransferId::get).filter(entry -> !paired.contains(entry.transferId()));
            if (hit.isEmpty()) {
                continue;
            }
            LedgerEntry entry = hit.get();
            paired.add(entry.transferId());
            boolean same = entry.amount().equals(line.amount()) && entry.direction() == line.direction();
            outcomes.put(
                    line,
                    same
                            ? MatchOutcome.matched(MatchMethod.REFERENCE, line, entry)
                            : MatchOutcome.amountMismatch(line, entry));
        }
    }

    private static void matchFuzzy(
            List<BankLine> bank,
            List<LedgerEntry> ledger,
            MatchingPolicy policy,
            Map<BankLine, MatchOutcome> outcomes,
            Set<UUID> paired) {
        Map<BankLine, List<LedgerEntry>> candidates = new LinkedHashMap<>();
        Map<UUID, Integer> claims = new HashMap<>();
        for (BankLine line : bank) {
            if (outcomes.containsKey(line)) {
                continue;
            }
            List<LedgerEntry> fits = ledger.stream()
                    .filter(entry -> !paired.contains(entry.transferId()))
                    .filter(entry -> fits(line, entry, policy))
                    .toList();
            candidates.put(line, fits);
            for (LedgerEntry entry : fits) {
                claims.merge(entry.transferId(), 1, Integer::sum);
            }
        }
        candidates.forEach((line, fits) -> {
            if (fits.size() == 1 && claims.get(fits.get(0).transferId()) == 1) {
                paired.add(fits.get(0).transferId());
                outcomes.put(line, MatchOutcome.matched(MatchMethod.FUZZY, line, fits.get(0)));
            } else if (!fits.isEmpty()) {
                outcomes.put(line, MatchOutcome.ambiguous(line, fits));
            }
        });
    }

    private static boolean fits(BankLine line, LedgerEntry entry, MatchingPolicy policy) {
        return entry.direction() == line.direction()
                && entry.amount().equals(line.amount())
                && Math.abs(ChronoUnit.DAYS.between(line.date(), entry.date())) <= policy.windowDays();
    }

    private static void validateNoDuplicateTransferIds(List<LedgerEntry> ledgerEntries) {
        Set<UUID> seen = new HashSet<>();
        for (LedgerEntry entry : ledgerEntries) {
            UUID transferId = entry.transferId();
            if (!seen.add(transferId)) {
                throw new IllegalArgumentException("Duplicate ledger transferId " + transferId);
            }
        }
    }
}

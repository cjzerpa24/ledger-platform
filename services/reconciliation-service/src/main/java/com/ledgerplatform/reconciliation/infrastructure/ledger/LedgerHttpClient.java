package com.ledgerplatform.reconciliation.infrastructure.ledger;

import com.ledgerplatform.reconciliation.application.port.LedgerStatementPort;
import com.ledgerplatform.reconciliation.application.port.LedgerUnavailableException;
import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.time.OffsetDateTime;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import java.util.UUID;
import org.springframework.http.MediaType;
import org.springframework.web.client.RestClient;
import org.springframework.web.client.RestClientException;

/**
 * Reads the settlement account statement from ledger-service. A settlement debit is a deposit (money into the bank),
 * a credit is a withdrawal.
 */
public final class LedgerHttpClient implements LedgerStatementPort {

    /** ledger-service rejects a statement whose "to" is more than 366 days after "from". */
    static final int MAX_DAYS_PER_REQUEST = 366;

    private final RestClient client;

    public LedgerHttpClient(RestClient client) {
        this.client = client;
    }

    @Override
    public List<LedgerEntry> fetch(SupportedCurrency currency, Period period) {
        UUID account = SettlementAccounts.forCurrency(currency);
        List<LedgerEntry> entries = new ArrayList<>();
        for (Period part : period.split(MAX_DAYS_PER_REQUEST)) {
            entries.addAll(toEntries(get(account, part), currency));
        }
        requireNoDuplicateTransferIds(entries);
        return List.copyOf(entries);
    }

    private StatementResponse get(UUID account, Period part) {
        StatementResponse body;
        try {
            body = client.get()
                    .uri("/accounts/{id}/statement?from={from}&to={to}", account, part.from(), part.to())
                    .accept(MediaType.APPLICATION_JSON)
                    .retrieve()
                    .body(StatementResponse.class);
        } catch (RestClientException e) {
            throw new LedgerUnavailableException("The ledger statement request failed: " + e.getMessage());
        }
        if (body == null) {
            throw new LedgerUnavailableException("The ledger returned an empty statement.");
        }
        return body;
    }

    private static List<LedgerEntry> toEntries(StatementResponse statement, SupportedCurrency currency) {
        if (!currency.name().equals(statement.currency()) || statement.lines() == null) {
            throw new LedgerUnavailableException(
                    "The ledger returned a %s statement instead of %s.".formatted(statement.currency(), currency));
        }
        try {
            return statement.lines().stream()
                    .map(line -> toEntry(line, currency))
                    .toList();
        } catch (RuntimeException e) {
            throw new LedgerUnavailableException("The ledger returned an unreadable statement line: " + e.getMessage());
        }
    }

    private static LedgerEntry toEntry(StatementResponse.Line line, SupportedCurrency currency) {
        Direction direction =
                switch (line.direction()) {
                    case "debit" -> Direction.IN;
                    case "credit" -> Direction.OUT;
                    default -> throw new IllegalArgumentException("unknown direction " + line.direction());
                };
        return new LedgerEntry(
                UUID.fromString(line.transferId()),
                OffsetDateTime.parse(line.occurredAt()).toInstant(),
                direction,
                Money.parse(line.amount(), currency),
                line.description());
    }

    private static void requireNoDuplicateTransferIds(List<LedgerEntry> entries) {
        Set<UUID> seen = new HashSet<>();
        for (LedgerEntry entry : entries) {
            if (!seen.add(entry.transferId())) {
                throw new LedgerUnavailableException("The ledger returned transfer " + entry.transferId() + " twice.");
            }
        }
    }
}

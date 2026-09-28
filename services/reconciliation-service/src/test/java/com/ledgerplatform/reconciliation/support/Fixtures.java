package com.ledgerplatform.reconciliation.support;

import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;

public final class Fixtures {

    private Fixtures() {}

    public static Money usd(String amount) {
        return Money.parse(amount, SupportedCurrency.USD);
    }

    public static BankLine bank(int row, String date, Direction direction, String amount, String reference) {
        return new BankLine(row, LocalDate.parse(date), direction, "line " + row, reference, usd(amount));
    }

    public static LedgerEntry ledger(String transferId, String occurredAt, Direction direction, String amount) {
        return new LedgerEntry(
                UUID.fromString(transferId), Instant.parse(occurredAt), direction, usd(amount), "ledger " + transferId);
    }

    public static Period march() {
        return Period.of(LocalDate.parse("2026-03-01"), LocalDate.parse("2026-03-31"));
    }
}

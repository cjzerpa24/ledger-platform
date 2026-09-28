package com.ledgerplatform.reconciliation.domain.statement;

import static org.assertj.core.api.Assertions.assertThat;

import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;
import org.junit.jupiter.api.Test;

class LedgerEntryTest {

    @Test
    void ledgerDateIsTheUtcCalendarDate() {
        LedgerEntry late = new LedgerEntry(
                UUID.randomUUID(),
                Instant.parse("2026-03-01T23:30:00Z"),
                Direction.OUT,
                new Money(500, SupportedCurrency.USD),
                "ATM");

        assertThat(late.date()).isEqualTo(LocalDate.parse("2026-03-01"));
    }
}

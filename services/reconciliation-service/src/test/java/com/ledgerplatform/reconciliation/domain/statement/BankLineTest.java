package com.ledgerplatform.reconciliation.domain.statement;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import java.time.LocalDate;
import java.util.UUID;
import org.junit.jupiter.api.Test;

class BankLineTest {

    private static BankLine withReference(String reference) {
        return new BankLine(
                1, LocalDate.parse("2026-03-01"), Direction.IN, null, reference, new Money(100, SupportedCurrency.USD));
    }

    @Test
    void exposesAUuidReference() {
        UUID id = UUID.fromString("0199a111-2222-7333-8444-555566667777");

        assertThat(withReference("0199a111-2222-7333-8444-555566667777").referenceAsUuid())
                .contains(id);
        assertThat(withReference("0199A111-2222-7333-8444-555566667777").referenceAsUuid())
                .contains(id);
    }

    @Test
    void ignoresReferencesThatAreNotCanonicalUuids() {
        assertThat(withReference(null).referenceAsUuid()).isEmpty();
        assertThat(withReference("INV-2026-001").referenceAsUuid()).isEmpty();
        assertThat(withReference("1-1-1-1-1").referenceAsUuid()).isEmpty();
    }

    @Test
    void requiresAPositiveRowAndItsValues() {
        Money money = new Money(1, SupportedCurrency.USD);
        LocalDate date = LocalDate.parse("2026-03-01");

        assertThatThrownBy(() -> new BankLine(0, date, Direction.IN, null, null, money))
                .isInstanceOf(IllegalArgumentException.class);
        assertThatThrownBy(() -> new BankLine(1, null, Direction.IN, null, null, money))
                .isInstanceOf(NullPointerException.class);
        assertThatThrownBy(() -> new BankLine(1, date, null, null, null, money))
                .isInstanceOf(NullPointerException.class);
        assertThatThrownBy(() -> new BankLine(1, date, Direction.IN, null, null, null))
                .isInstanceOf(NullPointerException.class);
    }
}

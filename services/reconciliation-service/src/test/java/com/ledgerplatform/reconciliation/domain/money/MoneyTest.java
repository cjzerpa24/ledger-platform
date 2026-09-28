package com.ledgerplatform.reconciliation.domain.money;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.ValueSource;

class MoneyTest {

    @Test
    void parsesDecimalStringsIntoMinorUnits() {
        assertThat(Money.parse("10.50", SupportedCurrency.USD)).isEqualTo(new Money(1050, SupportedCurrency.USD));
        assertThat(Money.parse("10.5", SupportedCurrency.USD)).isEqualTo(new Money(1050, SupportedCurrency.USD));
        assertThat(Money.parse("7", SupportedCurrency.EUR)).isEqualTo(new Money(700, SupportedCurrency.EUR));
        assertThat(Money.parse("1500", SupportedCurrency.JPY)).isEqualTo(new Money(1500, SupportedCurrency.JPY));
    }

    @Test
    void formatsWithTheCurrencyScale() {
        assertThat(new Money(1050, SupportedCurrency.USD).toDecimalString()).isEqualTo("10.50");
        assertThat(new Money(5, SupportedCurrency.GBP).toDecimalString()).isEqualTo("0.05");
        assertThat(new Money(1500, SupportedCurrency.JPY).toDecimalString()).isEqualTo("1500");
    }

    @ParameterizedTest
    @ValueSource(
            strings = {
                "",
                " 10",
                "10 ",
                "-5",
                "+5",
                "10.",
                ".5",
                "1e3",
                "10,50",
                "1.005",
                "0",
                "0.00",
                "abc",
                "92233720368547758.08",
                "١٠"
            })
    void rejectsNonCanonicalOrNonPositiveAmounts(String raw) {
        assertThatThrownBy(() -> Money.parse(raw, SupportedCurrency.USD)).isInstanceOf(InvalidMoneyException.class);
    }

    @Test
    void rejectsDecimalsForZeroScaleCurrencies() {
        assertThatThrownBy(() -> Money.parse("10.00", SupportedCurrency.JPY))
                .isInstanceOf(InvalidMoneyException.class)
                .hasMessageContaining("0 decimals");
    }

    @Test
    void rejectsNull() {
        assertThatThrownBy(() -> Money.parse(null, SupportedCurrency.USD)).isInstanceOf(InvalidMoneyException.class);
    }

    @Test
    void resolvesSupportedCurrenciesByCode() {
        assertThat(SupportedCurrency.of("TZS")).isEqualTo(SupportedCurrency.TZS);
        assertThat(SupportedCurrency.JPY.scale()).isZero();
        assertThatThrownBy(() -> SupportedCurrency.of("usd"))
                .isInstanceOf(UnsupportedCurrencyException.class)
                .extracting(e -> ((UnsupportedCurrencyException) e).code())
                .isEqualTo("unsupported-currency");
        assertThatThrownBy(() -> SupportedCurrency.of("CHF")).isInstanceOf(UnsupportedCurrencyException.class);
        assertThatThrownBy(() -> SupportedCurrency.of(null)).isInstanceOf(UnsupportedCurrencyException.class);
    }
}

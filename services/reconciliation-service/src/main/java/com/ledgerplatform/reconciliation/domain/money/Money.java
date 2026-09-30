package com.ledgerplatform.reconciliation.domain.money;

import java.math.BigDecimal;
import java.util.Objects;
import java.util.regex.Pattern;

/** A positive amount in minor units. Never a floating-point number. */
public record Money(long minor, SupportedCurrency currency) {

    private static final Pattern DECIMAL = Pattern.compile("^[0-9]+(\\.[0-9]+)?$");

    public Money {
        Objects.requireNonNull(currency, "currency");
        if (minor <= 0) {
            throw new InvalidMoneyException("An amount must be greater than zero.");
        }
    }

    /** Accepts only canonical positive decimals such as "10.50", with at most the currency's decimals. */
    public static Money parse(String raw, SupportedCurrency currency) {
        if (raw == null || !DECIMAL.matcher(raw).matches()) {
            throw new InvalidMoneyException("Use a positive decimal such as 10.50.");
        }
        BigDecimal value = new BigDecimal(raw);
        if (value.scale() > currency.scale()) {
            throw new InvalidMoneyException(
                    "%s amounts have at most %d decimals.".formatted(currency, currency.scale()));
        }
        try {
            return new Money(value.movePointRight(currency.scale()).longValueExact(), currency);
        } catch (ArithmeticException e) {
            throw new InvalidMoneyException("The amount is too large.");
        }
    }

    public String toDecimalString() {
        return BigDecimal.valueOf(minor, currency.scale()).toPlainString();
    }
}

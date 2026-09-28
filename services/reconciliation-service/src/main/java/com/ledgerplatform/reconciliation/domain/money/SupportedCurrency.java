package com.ledgerplatform.reconciliation.domain.money;

/** The currencies the ledger supports, with their number of decimals. */
public enum SupportedCurrency {
    USD(2),
    EUR(2),
    GBP(2),
    TZS(2),
    JPY(0);

    private final int scale;

    SupportedCurrency(int scale) {
        this.scale = scale;
    }

    public int scale() {
        return scale;
    }

    public static SupportedCurrency of(String code) {
        for (SupportedCurrency currency : values()) {
            if (currency.name().equals(code)) {
                return currency;
            }
        }
        throw new UnsupportedCurrencyException(code);
    }
}

package com.ledgerplatform.reconciliation.infrastructure.ledger;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import java.util.Map;
import java.util.UUID;

/** The settlement account ids ledger-service seeds: the suffix is the ISO 4217 numeric code. */
final class SettlementAccounts {

    private static final Map<SupportedCurrency, UUID> IDS = Map.of(
            SupportedCurrency.USD, UUID.fromString("0199a000-0000-7000-8000-000000000840"),
            SupportedCurrency.EUR, UUID.fromString("0199a000-0000-7000-8000-000000000978"),
            SupportedCurrency.GBP, UUID.fromString("0199a000-0000-7000-8000-000000000826"),
            SupportedCurrency.TZS, UUID.fromString("0199a000-0000-7000-8000-000000000834"),
            SupportedCurrency.JPY, UUID.fromString("0199a000-0000-7000-8000-000000000392"));

    private SettlementAccounts() {}

    static UUID forCurrency(SupportedCurrency currency) {
        return IDS.get(currency);
    }
}

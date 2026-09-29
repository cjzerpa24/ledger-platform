package com.ledgerplatform.reconciliation.support;

import com.ledgerplatform.reconciliation.application.port.LedgerStatementPort;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.util.List;

/** Replaces ledger-service in integration tests. */
public final class StubLedger implements LedgerStatementPort {

    private volatile List<LedgerEntry> entries = List.of();
    private volatile RuntimeException failure;

    public void returns(LedgerEntry... value) {
        entries = List.of(value);
        failure = null;
    }

    public void failsWith(RuntimeException value) {
        failure = value;
    }

    public void reset() {
        entries = List.of();
        failure = null;
    }

    @Override
    public List<LedgerEntry> fetch(SupportedCurrency currency, Period period) {
        if (failure != null) {
            throw failure;
        }
        return entries;
    }
}

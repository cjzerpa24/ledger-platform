package com.ledgerplatform.reconciliation.application.port;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.util.List;

public interface LedgerStatementPort {

    /**
     * Every deposit and withdrawal the ledger posted on the currency's settlement account within {@code period}.
     *
     * @throws LedgerUnavailableException when the ledger cannot answer
     */
    List<LedgerEntry> fetch(SupportedCurrency currency, Period period);
}

package com.ledgerplatform.reconciliation.application.port;

import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.util.List;

public interface StatementParser {

    /**
     * Parses the whole statement or nothing.
     *
     * @throws InvalidStatementException listing every problem found
     */
    List<BankLine> parse(byte[] content, SupportedCurrency currency, Period period);
}

package com.ledgerplatform.reconciliation.application;

import com.ledgerplatform.reconciliation.application.port.IdGenerator;
import com.ledgerplatform.reconciliation.application.port.LedgerStatementPort;
import com.ledgerplatform.reconciliation.application.port.StatementParser;
import com.ledgerplatform.reconciliation.domain.matching.Matcher;
import com.ledgerplatform.reconciliation.domain.matching.MatchingPolicy;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.run.DuplicateStatementException;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Clock;
import java.time.LocalDate;
import java.util.HexFormat;
import java.util.List;

/** Parses a statement, pulls the ledger side, matches and stores the run. Nothing is stored unless every step succeeds. */
public final class StartReconciliation {

    public record Command(String currency, LocalDate from, LocalDate to, byte[] statement) {}

    private final RunRepository runs;
    private final StatementParser parser;
    private final LedgerStatementPort ledger;
    private final IdGenerator ids;
    private final Clock clock;
    private final MatchingPolicy policy;
    private final Matcher matcher = new Matcher();

    public StartReconciliation(
            RunRepository runs,
            StatementParser parser,
            LedgerStatementPort ledger,
            IdGenerator ids,
            Clock clock,
            MatchingPolicy policy) {
        this.runs = runs;
        this.parser = parser;
        this.ledger = ledger;
        this.ids = ids;
        this.clock = clock;
        this.policy = policy;
    }

    public ReconciliationRun execute(Command command) {
        SupportedCurrency currency = SupportedCurrency.of(command.currency());
        Period period = Period.of(command.from(), command.to());
        String sha256 = sha256Hex(command.statement());
        runs.findIdByStatement(currency, period, sha256).ifPresent(existing -> {
            throw new DuplicateStatementException(existing);
        });

        List<BankLine> lines = parser.parse(command.statement(), currency, period);
        List<LedgerEntry> entries = ledger.fetch(currency, period.widen(policy.windowDays()));

        ReconciliationRun run = ReconciliationRun.start(
                ids.next(),
                currency,
                period,
                sha256,
                matcher.match(lines, entries, period, policy),
                ids::next,
                clock.instant());
        runs.save(run);
        return run;
    }

    static String sha256Hex(byte[] content) {
        try {
            return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(content));
        } catch (NoSuchAlgorithmException e) {
            throw new IllegalStateException("SHA-256 is always available", e);
        }
    }
}

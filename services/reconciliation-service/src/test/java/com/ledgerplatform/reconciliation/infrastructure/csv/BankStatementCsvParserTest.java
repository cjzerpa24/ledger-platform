package com.ledgerplatform.reconciliation.infrastructure.csv;

import static com.ledgerplatform.reconciliation.support.Fixtures.march;
import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ledgerplatform.reconciliation.application.port.InvalidStatementException;
import com.ledgerplatform.reconciliation.application.port.RowError;
import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import java.nio.charset.Charset;
import java.nio.charset.StandardCharsets;
import java.time.LocalDate;
import java.util.List;
import org.junit.jupiter.api.Test;

class BankStatementCsvParserTest {

    private static final String HEADER = "date,direction,description,reference,currency,amount\n";

    private final BankStatementCsvParser parser = new BankStatementCsvParser();

    private List<BankLine> parse(String csv) {
        return parser.parse(csv.getBytes(StandardCharsets.UTF_8), SupportedCurrency.USD, march());
    }

    private List<RowError> errorsOf(String csv) {
        try {
            parse(csv);
        } catch (InvalidStatementException e) {
            return e.errors();
        }
        throw new AssertionError("expected InvalidStatementException");
    }

    @Test
    void parsesRowsInFileOrder() {
        List<BankLine> lines = parse(HEADER
                + "2026-03-05,CR,Salary,0199a111-2222-7333-8444-555566667777,USD,100.00\n"
                + "2026-03-06,DR,,,USD,5\n");

        assertThat(lines)
                .containsExactly(
                        new BankLine(
                                1,
                                LocalDate.parse("2026-03-05"),
                                Direction.IN,
                                "Salary",
                                "0199a111-2222-7333-8444-555566667777",
                                new Money(10000, SupportedCurrency.USD)),
                        new BankLine(
                                2,
                                LocalDate.parse("2026-03-06"),
                                Direction.OUT,
                                null,
                                null,
                                new Money(500, SupportedCurrency.USD)));
    }

    @Test
    void handlesQuotingBomCrlfBlankLinesAndSurroundingSpaces() {
        String csv = "﻿" + HEADER.replace("\n", "\r\n")
                + "\r\n"
                + " 2026-03-05 , CR ,\"Rent, March \"\"flat 2\"\"\nsecond line\", REF-1 , USD , 10.00 \r\n";

        List<BankLine> lines = parse(csv);

        assertThat(lines).singleElement().satisfies(line -> {
            assertThat(line.row()).isEqualTo(1);
            assertThat(line.description()).isEqualTo("Rent, March \"flat 2\"\nsecond line");
            assertThat(line.reference()).isEqualTo("REF-1");
            assertThat(line.amount()).isEqualTo(new Money(1000, SupportedCurrency.USD));
        });
    }

    @Test
    void reportsEveryRowErrorWithItsRowAndField() {
        List<RowError> errors = errorsOf(HEADER
                + "2026-02-28,CR,,,USD,1.00\n"
                + "2026-03-31,XX,,,EUR,1.001\n"
                + "03/05/2026,DR,,,USD,-1\n"
                + "2026-03-05,CR,,,USD\n"
                + "2026-03-05,CR," + "d".repeat(256) + "," + "r".repeat(65) + ",USD,1.00\n");

        assertThat(errors)
                .extracting(RowError::row, RowError::field)
                .containsExactly(
                        org.assertj.core.groups.Tuple.tuple(1, "date"),
                        org.assertj.core.groups.Tuple.tuple(2, "direction"),
                        org.assertj.core.groups.Tuple.tuple(2, "currency"),
                        org.assertj.core.groups.Tuple.tuple(2, "amount"),
                        org.assertj.core.groups.Tuple.tuple(3, "date"),
                        org.assertj.core.groups.Tuple.tuple(3, "amount"),
                        org.assertj.core.groups.Tuple.tuple(4, null),
                        org.assertj.core.groups.Tuple.tuple(5, "description"),
                        org.assertj.core.groups.Tuple.tuple(5, "reference"));
        assertThat(errors.get(0).message()).contains("outside the period");
        assertThat(errors.get(6).message()).isEqualTo("Expected 6 columns, found 5.");
    }

    @Test
    void rejectsAWrongOrMissingHeader() {
        assertThat(errorsOf("date,amount,currency,direction,reference,description\n2026-03-05,1.00,USD,CR,,\n"))
                .singleElement()
                .satisfies(error -> {
                    assertThat(error.row()).isNull();
                    assertThat(error.message()).contains("date,direction,description,reference,currency,amount");
                });
        assertThat(errorsOf(""))
                .singleElement()
                .satisfies(error -> assertThat(error.message()).contains("empty"));
    }

    @Test
    void requiresAtLeastOneDataRow() {
        assertThat(errorsOf(HEADER + "\n\n"))
                .singleElement()
                .satisfies(error -> assertThat(error.message()).contains("no data rows"));
    }

    @Test
    void rejectsMoreThanTheMaximumNumberOfRows() {
        StringBuilder csv = new StringBuilder(HEADER);
        for (int i = 0; i <= BankStatementCsvParser.MAX_ROWS; i++) {
            csv.append("2026-03-05,CR,,,USD,1.00\n");
        }

        assertThat(errorsOf(csv.toString()))
                .singleElement()
                .satisfies(error -> assertThat(error.message()).contains("10000"));
    }

    @Test
    void rejectsNonUtf8Files() {
        byte[] latin1 = (HEADER + "2026-03-05,CR,Café,,USD,1.00\n").getBytes(Charset.forName("windows-1252"));

        assertThatThrownBy(() -> parser.parse(latin1, SupportedCurrency.USD, march()))
                .isInstanceOf(InvalidStatementException.class)
                .satisfies(e -> assertThat(((InvalidStatementException) e).errors())
                        .singleElement()
                        .satisfies(error -> assertThat(error.message()).contains("UTF-8")));
    }

    @Test
    void rejectsUnbalancedQuotes() {
        assertThat(errorsOf(HEADER + "2026-03-05,CR,\"never closed,,USD,1.00\n"))
                .singleElement()
                .satisfies(error -> assertThat(error.message()).contains("not valid CSV"));
    }
}

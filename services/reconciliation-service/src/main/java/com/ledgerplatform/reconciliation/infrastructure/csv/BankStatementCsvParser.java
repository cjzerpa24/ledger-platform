package com.ledgerplatform.reconciliation.infrastructure.csv;

import com.ledgerplatform.reconciliation.application.port.InvalidStatementException;
import com.ledgerplatform.reconciliation.application.port.RowError;
import com.ledgerplatform.reconciliation.application.port.StatementParser;
import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.InvalidMoneyException;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.BankLine;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.io.IOException;
import java.io.StringReader;
import java.io.UncheckedIOException;
import java.nio.ByteBuffer;
import java.nio.charset.CharacterCodingException;
import java.nio.charset.CodingErrorAction;
import java.nio.charset.StandardCharsets;
import java.time.LocalDate;
import java.time.format.DateTimeParseException;
import java.util.ArrayList;
import java.util.Iterator;
import java.util.List;
import java.util.Optional;
import org.apache.commons.csv.CSVFormat;
import org.apache.commons.csv.CSVParser;
import org.apache.commons.csv.CSVRecord;

/** Parses the statement format of spec §5.2: UTF-8, RFC 4180, a fixed header, all-or-nothing. */
public final class BankStatementCsvParser implements StatementParser {

    static final List<String> HEADER = List.of("date", "direction", "description", "reference", "currency", "amount");
    static final int MAX_ROWS = 10_000;
    private static final int MAX_DESCRIPTION = 255;
    private static final int MAX_REFERENCE = 64;
    private static final CSVFormat FORMAT =
            CSVFormat.RFC4180.builder().setIgnoreEmptyLines(true).get();

    @Override
    public List<BankLine> parse(byte[] content, SupportedCurrency currency, Period period) {
        String text = decode(content);
        List<RowError> errors = new ArrayList<>();
        List<BankLine> lines = new ArrayList<>();
        try (CSVParser csv = FORMAT.parse(new StringReader(text))) {
            Iterator<CSVRecord> records = csv.iterator();
            if (!records.hasNext()) {
                throw invalid("The file is empty; the first line must be: " + String.join(",", HEADER));
            }
            List<String> header =
                    records.next().toList().stream().map(String::strip).toList();
            if (!HEADER.equals(header)) {
                throw invalid("The first line must be exactly: " + String.join(",", HEADER));
            }
            while (records.hasNext()) {
                CSVRecord record = records.next();
                int row = (int) record.getRecordNumber() - 1;
                if (row > MAX_ROWS) {
                    errors.add(
                            new RowError(null, null, "A statement may have at most %d data rows.".formatted(MAX_ROWS)));
                    break;
                }
                parseRow(record, row, currency, period, errors).ifPresent(lines::add);
            }
        } catch (IOException | UncheckedIOException | IllegalStateException e) {
            errors.add(new RowError(null, null, "The file is not valid CSV: " + e.getMessage()));
        }
        if (errors.isEmpty() && lines.isEmpty()) {
            errors.add(new RowError(null, null, "The statement has no data rows."));
        }
        if (!errors.isEmpty()) {
            throw new InvalidStatementException(errors);
        }
        return List.copyOf(lines);
    }

    private static Optional<BankLine> parseRow(
            CSVRecord record, int row, SupportedCurrency currency, Period period, List<RowError> errors) {
        if (record.size() != HEADER.size()) {
            errors.add(
                    new RowError(row, null, "Expected %d columns, found %d.".formatted(HEADER.size(), record.size())));
            return Optional.empty();
        }
        int before = errors.size();
        LocalDate date = parseDate(record.get(0).strip(), row, period, errors);
        Direction direction = parseDirection(record.get(1).strip(), row, errors);
        String description = optionalText(record.get(2).strip(), "description", MAX_DESCRIPTION, row, errors);
        String reference = optionalText(record.get(3).strip(), "reference", MAX_REFERENCE, row, errors);
        String code = record.get(4).strip();
        if (!currency.name().equals(code)) {
            errors.add(new RowError(row, "currency", "Expected %s, found \"%s\".".formatted(currency, code)));
        }
        Money amount = parseAmount(record.get(5).strip(), currency, row, errors);
        if (errors.size() > before) {
            return Optional.empty();
        }
        return Optional.of(new BankLine(row, date, direction, description, reference, amount));
    }

    private static LocalDate parseDate(String value, int row, Period period, List<RowError> errors) {
        LocalDate date;
        try {
            date = LocalDate.parse(value);
        } catch (DateTimeParseException e) {
            errors.add(new RowError(row, "date", "Use the YYYY-MM-DD format."));
            return null;
        }
        if (!period.contains(date)) {
            errors.add(new RowError(
                    row, "date", "%s is outside the period %s to %s.".formatted(date, period.from(), period.to())));
            return null;
        }
        return date;
    }

    private static Direction parseDirection(String value, int row, List<RowError> errors) {
        return switch (value) {
            case "CR" -> Direction.IN;
            case "DR" -> Direction.OUT;
            default -> {
                errors.add(new RowError(row, "direction", "Use CR (money in) or DR (money out)."));
                yield null;
            }
        };
    }

    private static String optionalText(String value, String field, int max, int row, List<RowError> errors) {
        if (value.isEmpty()) {
            return null;
        }
        if (value.length() > max) {
            errors.add(new RowError(row, field, "At most %d characters.".formatted(max)));
            return null;
        }
        return value;
    }

    private static Money parseAmount(String value, SupportedCurrency currency, int row, List<RowError> errors) {
        try {
            return Money.parse(value, currency);
        } catch (InvalidMoneyException e) {
            errors.add(new RowError(row, "amount", e.getMessage()));
            return null;
        }
    }

    private static String decode(byte[] content) {
        int offset = content.length >= 3
                        && (content[0] & 0xFF) == 0xEF
                        && (content[1] & 0xFF) == 0xBB
                        && (content[2] & 0xFF) == 0xBF
                ? 3
                : 0;
        try {
            return StandardCharsets.UTF_8
                    .newDecoder()
                    .onMalformedInput(CodingErrorAction.REPORT)
                    .onUnmappableCharacter(CodingErrorAction.REPORT)
                    .decode(ByteBuffer.wrap(content, offset, content.length - offset))
                    .toString();
        } catch (CharacterCodingException e) {
            throw invalid("The file is not valid UTF-8.");
        }
    }

    private static InvalidStatementException invalid(String message) {
        return new InvalidStatementException(List.of(new RowError(null, null, message)));
    }
}

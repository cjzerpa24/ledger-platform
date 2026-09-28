package com.ledgerplatform.reconciliation.domain.statement;

import java.time.LocalDate;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.List;
import java.util.Objects;

/** An inclusive range of calendar dates. */
public record Period(LocalDate from, LocalDate to) {

    /** The longest period a run may cover, counting both ends. */
    public static final int MAX_DAYS = 366;

    public Period {
        Objects.requireNonNull(from, "from");
        Objects.requireNonNull(to, "to");
        if (from.isAfter(to)) {
            throw new InvalidPeriodException("\"from\" (%s) must not be after \"to\" (%s).".formatted(from, to));
        }
    }

    /** A run's period: both dates required, at most {@link #MAX_DAYS} days. */
    public static Period of(LocalDate from, LocalDate to) {
        if (from == null || to == null) {
            throw new InvalidPeriodException("Both \"from\" and \"to\" are required.");
        }
        Period period = new Period(from, to);
        if (period.days() > MAX_DAYS) {
            throw new InvalidPeriodException("A period may cover at most %d days.".formatted(MAX_DAYS));
        }
        return period;
    }

    public long days() {
        return ChronoUnit.DAYS.between(from, to) + 1;
    }

    public boolean contains(LocalDate date) {
        return !date.isBefore(from) && !date.isAfter(to);
    }

    public Period widen(int days) {
        return new Period(from.minusDays(days), to.plusDays(days));
    }

    /** Consecutive periods of at most {@code maxDays} days that together cover this one. */
    public List<Period> split(int maxDays) {
        if (maxDays < 1) {
            throw new IllegalArgumentException("maxDays must be positive");
        }
        List<Period> parts = new ArrayList<>();
        LocalDate start = from;
        while (!start.isAfter(to)) {
            LocalDate end = start.plusDays(maxDays - 1L);
            if (end.isAfter(to)) {
                end = to;
            }
            parts.add(new Period(start, end));
            start = end.plusDays(1);
        }
        return List.copyOf(parts);
    }
}

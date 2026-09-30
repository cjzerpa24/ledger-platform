package com.ledgerplatform.reconciliation.domain.statement;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import java.time.LocalDate;
import org.junit.jupiter.api.Test;

class PeriodTest {

    private static LocalDate d(String iso) {
        return LocalDate.parse(iso);
    }

    @Test
    void isInclusiveOnBothEnds() {
        Period march = Period.of(d("2026-03-01"), d("2026-03-31"));

        assertThat(march.days()).isEqualTo(31);
        assertThat(march.contains(d("2026-03-01"))).isTrue();
        assertThat(march.contains(d("2026-03-31"))).isTrue();
        assertThat(march.contains(d("2026-02-28"))).isFalse();
        assertThat(march.contains(d("2026-04-01"))).isFalse();
    }

    @Test
    void allowsASingleDay() {
        assertThat(Period.of(d("2026-03-05"), d("2026-03-05")).days()).isEqualTo(1);
    }

    @Test
    void rejectsFromAfterTo() {
        assertThatThrownBy(() -> Period.of(d("2026-03-10"), d("2026-03-01")))
                .isInstanceOf(InvalidPeriodException.class)
                .extracting(e -> ((InvalidPeriodException) e).code())
                .isEqualTo("invalid-period");
    }

    @Test
    void allowsAtMost366Days() {
        assertThat(Period.of(d("2028-01-01"), d("2028-12-31")).days()).isEqualTo(366);
        assertThatThrownBy(() -> Period.of(d("2026-01-01"), d("2027-01-02")))
                .isInstanceOf(InvalidPeriodException.class);
    }

    @Test
    void requiresBothDates() {
        assertThatThrownBy(() -> Period.of(null, d("2026-01-01"))).isInstanceOf(InvalidPeriodException.class);
        assertThatThrownBy(() -> Period.of(d("2026-01-01"), null)).isInstanceOf(InvalidPeriodException.class);
    }

    @Test
    void widensOnBothSidesBeyondTheMaximum() {
        Period year = Period.of(d("2028-01-01"), d("2028-12-31"));

        Period widened = year.widen(2);

        assertThat(widened).isEqualTo(new Period(d("2027-12-30"), d("2029-01-02")));
        assertThat(widened.days()).isEqualTo(370);
    }

    @Test
    void splitsIntoConsecutiveParts() {
        Period period = new Period(d("2026-01-01"), d("2026-01-10"));

        assertThat(period.split(4))
                .containsExactly(
                        new Period(d("2026-01-01"), d("2026-01-04")),
                        new Period(d("2026-01-05"), d("2026-01-08")),
                        new Period(d("2026-01-09"), d("2026-01-10")));
        assertThat(period.split(10)).containsExactly(period);
        assertThat(period.split(366)).containsExactly(period);
    }
}

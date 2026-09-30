package com.ledgerplatform.reconciliation.infrastructure.ledger;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.springframework.test.web.client.match.MockRestRequestMatchers.method;
import static org.springframework.test.web.client.match.MockRestRequestMatchers.requestTo;
import static org.springframework.test.web.client.response.MockRestResponseCreators.withResourceNotFound;
import static org.springframework.test.web.client.response.MockRestResponseCreators.withServerError;
import static org.springframework.test.web.client.response.MockRestResponseCreators.withSuccess;

import com.ledgerplatform.reconciliation.application.port.LedgerUnavailableException;
import com.ledgerplatform.reconciliation.domain.money.Direction;
import com.ledgerplatform.reconciliation.domain.money.Money;
import com.ledgerplatform.reconciliation.domain.money.SupportedCurrency;
import com.ledgerplatform.reconciliation.domain.statement.LedgerEntry;
import com.ledgerplatform.reconciliation.domain.statement.Period;
import java.io.IOException;
import java.time.Instant;
import java.time.LocalDate;
import java.util.List;
import java.util.UUID;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.boot.health.contributor.Status;
import org.springframework.http.HttpMethod;
import org.springframework.http.MediaType;
import org.springframework.test.web.client.MockRestServiceServer;
import org.springframework.web.client.RestClient;

class LedgerHttpClientTest {

    private static final String USD_ACCOUNT = "0199a000-0000-7000-8000-000000000840";
    private static final String BASE = "http://ledger.test";

    private MockRestServiceServer server;
    private LedgerHttpClient client;
    private RestClient restClient;

    @BeforeEach
    void setUp() {
        RestClient.Builder builder = RestClient.builder().baseUrl(BASE);
        server = MockRestServiceServer.bindTo(builder).build();
        restClient = builder.build();
        client = new LedgerHttpClient(restClient);
    }

    private static String statement(String currency, String lines) {
        return """
                {"accountId":"%s","currency":"%s","from":"2026-03-01","to":"2026-03-31",
                 "openingBalance":"0.00","closingBalance":"-95.00","lines":[%s]}""".formatted(USD_ACCOUNT, currency, lines);
    }

    private static final String DEPOSIT = """
            {"postingId":"01a0de17-1e23-79a3-b479-1cb1d6981749","occurredAt":"2026-03-05T14:20:52.000+00:00",
             "description":"Salary","transferId":"01a0de17-1e23-785b-b479-1cb1d53637cd","direction":"debit",
             "amount":"100.00","balanceAfter":"-100.00"}""";
    private static final String WITHDRAWAL = """
            {"postingId":"01a0de17-1eb1-7d46-b808-3996db1eec83","occurredAt":"2026-03-06T08:00:00.000+00:00",
             "description":null,"transferId":"01a0de17-1eb1-7c2e-b808-3996d947888f","direction":"credit",
             "amount":"5.00","balanceAfter":"-95.00"}""";

    private static Period march() {
        return new Period(LocalDate.parse("2026-03-01"), LocalDate.parse("2026-03-31"));
    }

    @Test
    void mapsSettlementPostingsToLedgerEntries() {
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andExpect(method(HttpMethod.GET))
                .andRespond(withSuccess(statement("USD", DEPOSIT + "," + WITHDRAWAL), MediaType.APPLICATION_JSON));

        List<LedgerEntry> entries = client.fetch(SupportedCurrency.USD, march());

        assertThat(entries)
                .containsExactly(
                        new LedgerEntry(
                                UUID.fromString("01a0de17-1e23-785b-b479-1cb1d53637cd"),
                                Instant.parse("2026-03-05T14:20:52Z"),
                                Direction.IN,
                                new Money(10000, SupportedCurrency.USD),
                                "Salary"),
                        new LedgerEntry(
                                UUID.fromString("01a0de17-1eb1-7c2e-b808-3996d947888f"),
                                Instant.parse("2026-03-06T08:00:00Z"),
                                Direction.OUT,
                                new Money(500, SupportedCurrency.USD),
                                null));
        server.verify();
    }

    @Test
    void fetchesLongPeriodsInChunksTheLedgerAccepts() {
        Period widened = new Period(LocalDate.parse("2027-12-30"), LocalDate.parse("2029-01-02"));
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2027-12-30&to=2028-12-29"))
                .andRespond(withSuccess(statement("USD", DEPOSIT), MediaType.APPLICATION_JSON));
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2028-12-30&to=2029-01-02"))
                .andRespond(withSuccess(statement("USD", WITHDRAWAL), MediaType.APPLICATION_JSON));

        assertThat(client.fetch(SupportedCurrency.USD, widened)).hasSize(2);
        server.verify();
    }

    @Test
    void usesTheSettlementAccountOfEachCurrency() {
        assertThat(SettlementAccounts.forCurrency(SupportedCurrency.EUR))
                .isEqualTo(UUID.fromString("0199a000-0000-7000-8000-000000000978"));
        assertThat(SettlementAccounts.forCurrency(SupportedCurrency.JPY))
                .isEqualTo(UUID.fromString("0199a000-0000-7000-8000-000000000392"));
        for (SupportedCurrency currency : SupportedCurrency.values()) {
            assertThat(SettlementAccounts.forCurrency(currency)).isNotNull();
        }
    }

    @Test
    void notFoundIsUnavailable() {
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(withResourceNotFound());

        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class);
    }

    @Test
    void serverErrorIsUnavailable() {
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(withServerError());

        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class);
    }

    @Test
    void connectionFailureIsUnavailable() {
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(request -> {
                    throw new IOException("connect timed out");
                });

        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class)
                .hasMessageContaining("connect timed out");
    }

    @Test
    void garbageOrWrongCurrencyIsUnavailable() {
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(withSuccess("<html>oops</html>", MediaType.APPLICATION_JSON));
        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class);

        server.reset();
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(withSuccess(statement("EUR", DEPOSIT), MediaType.APPLICATION_JSON));
        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class)
                .hasMessageContaining("EUR");

        server.reset();
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(withSuccess(
                        statement("USD", DEPOSIT.replace("\"debit\"", "\"sideways\"")), MediaType.APPLICATION_JSON));
        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class);
    }

    @Test
    void duplicateTransferIdsAreUnavailable() {
        server.expect(requestTo(BASE + "/accounts/" + USD_ACCOUNT + "/statement?from=2026-03-01&to=2026-03-31"))
                .andRespond(withSuccess(statement("USD", DEPOSIT + "," + DEPOSIT), MediaType.APPLICATION_JSON));

        assertThatThrownBy(() -> client.fetch(SupportedCurrency.USD, march()))
                .isInstanceOf(LedgerUnavailableException.class)
                .hasMessageContaining("01a0de17-1e23-785b-b479-1cb1d53637cd");
    }

    @Test
    void healthReflectsLedgerReachability() {
        server.expect(requestTo(BASE + "/health"))
                .andRespond(withSuccess("{\"status\":\"ok\"}", MediaType.APPLICATION_JSON));
        assertThat(new LedgerHealthIndicator(restClient).health().getStatus()).isEqualTo(Status.UP);

        server.reset();
        server.expect(requestTo(BASE + "/health")).andRespond(withServerError());
        assertThat(new LedgerHealthIndicator(restClient).health().getStatus()).isEqualTo(Status.DOWN);
    }
}

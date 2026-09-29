package com.ledgerplatform.reconciliation.infrastructure.web;

import static com.ledgerplatform.reconciliation.domain.money.Direction.IN;
import static com.ledgerplatform.reconciliation.domain.money.Direction.OUT;
import static com.ledgerplatform.reconciliation.support.Fixtures.ledger;
import static org.assertj.core.api.Assertions.assertThat;
import static org.hamcrest.Matchers.hasSize;
import static org.hamcrest.Matchers.startsWith;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import com.ledgerplatform.reconciliation.application.port.LedgerUnavailableException;
import com.ledgerplatform.reconciliation.support.StubLedger;
import com.ledgerplatform.reconciliation.support.StubLedgerConfiguration;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.boot.webmvc.test.autoconfigure.AutoConfigureMockMvc;
import org.springframework.context.annotation.Import;
import org.springframework.http.MediaType;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.test.context.ActiveProfiles;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.test.web.servlet.ResultActions;
import org.springframework.test.web.servlet.request.MockMultipartHttpServletRequestBuilder;
import tools.jackson.databind.json.JsonMapper;

@SpringBootTest
@AutoConfigureMockMvc
@ActiveProfiles("test")
@Import(StubLedgerConfiguration.class)
class RunApiIT {

    private static final String PROBLEM = "urn:reconciliation:problem:";
    private static final String HEADER = "date,direction,description,reference,currency,amount\n";
    private static final String A = "0199a000-0000-7000-8000-00000000000a";
    private static final String B = "0199a000-0000-7000-8000-00000000000b";
    private static final String C = "0199a000-0000-7000-8000-00000000000c";

    @Autowired
    MockMvc mvc;

    @Autowired
    StubLedger ledger;

    @Autowired
    JdbcTemplate jdbc;

    @Autowired
    JsonMapper json;

    @BeforeEach
    void clean() {
        jdbc.execute("TRUNCATE reconciliation_items, reconciliation_runs");
        ledger.reset();
    }

    private MockMultipartHttpServletRequestBuilder upload(String csv, String currency, String from, String to) {
        MockMultipartHttpServletRequestBuilder request = multipart("/runs")
                .file(new MockMultipartFile("file", "statement.csv", "text/csv", csv.getBytes(StandardCharsets.UTF_8)));
        if (currency != null) {
            request.param("currency", currency);
        }
        if (from != null) {
            request.param("from", from);
        }
        if (to != null) {
            request.param("to", to);
        }
        return request;
    }

    private ResultActions uploadMarch(String rows) throws Exception {
        return mvc.perform(upload(HEADER + rows, "USD", "2026-03-01", "2026-03-31"));
    }

    private static void expectProblem(ResultActions result, int status, String type) throws Exception {
        result.andExpect(status().is(status))
                .andExpect(content().contentTypeCompatibleWith(MediaType.APPLICATION_PROBLEM_JSON))
                .andExpect(jsonPath("$.type").value(PROBLEM + type))
                .andExpect(jsonPath("$.status").value(status));
    }

    private Map<?, ?> body(ResultActions result) throws Exception {
        return json.readValue(result.andReturn().getResponse().getContentAsString(), Map.class);
    }

    @Test
    void reconcilesAStatementAndResolvesItsExceptions() throws Exception {
        ledger.returns(
                ledger(A, "2026-03-05T10:00:00Z", IN, "100.00"),
                ledger(B, "2026-03-07T12:00:00Z", OUT, "20.00"),
                ledger(C, "2026-03-20T09:00:00Z", IN, "15.00"));

        ResultActions created = uploadMarch("2026-03-05,CR,Salary," + A + ",USD,100.00\n"
                        + "2026-03-08,DR,ATM,,USD,20.00\n"
                        + "2026-03-21,CR,Refund,,USD,14.00\n")
                .andExpect(status().isCreated())
                .andExpect(header().string("Location", startsWith("/runs/")))
                .andExpect(jsonPath("$.currency").value("USD"))
                .andExpect(jsonPath("$.from").value("2026-03-01"))
                .andExpect(jsonPath("$.to").value("2026-03-31"))
                .andExpect(jsonPath("$.status").value("OPEN"))
                .andExpect(jsonPath("$.counts.MATCHED").value(2))
                .andExpect(jsonPath("$.counts.MISSING_IN_LEDGER").value(1))
                .andExpect(jsonPath("$.counts.MISSING_IN_BANK").value(1))
                .andExpect(jsonPath("$.unresolvedExceptions").value(2));
        String runId = (String) body(created).get("id");

        ResultActions items = mvc.perform(get("/runs/{id}/items", runId))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.items", hasSize(4)))
                .andExpect(jsonPath("$.items[0].kind").value("MATCHED"))
                .andExpect(jsonPath("$.items[0].matchMethod").value("REFERENCE"))
                .andExpect(jsonPath("$.items[0].bankLine.amount").value("100.00"))
                .andExpect(jsonPath("$.items[0].bankLine.direction").value("IN"))
                .andExpect(jsonPath("$.items[0].ledgerEntry.transferId").value(A))
                .andExpect(jsonPath("$.items[0].ledgerEntry.occurredAt").value("2026-03-05T10:00:00Z"))
                .andExpect(jsonPath("$.items[1].matchMethod").value("FUZZY"))
                .andExpect(jsonPath("$.items[2].kind").value("MISSING_IN_LEDGER"))
                .andExpect(jsonPath("$.items[3].kind").value("MISSING_IN_BANK"));
        List<?> list = (List<?>) body(items).get("items");
        String bankItem = (String) ((Map<?, ?>) list.get(2)).get("id");
        String ledgerItem = (String) ((Map<?, ?>) list.get(3)).get("id");

        mvc.perform(get("/runs/{id}/items", runId).param("unresolved", "true"))
                .andExpect(jsonPath("$.items", hasSize(2)));

        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, bankItem)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":\"MANUAL_MATCH\",\"counterpartItemId\":\"" + ledgerItem + "\"}")),
                409,
                "invalid-manual-match");

        mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, bankItem)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":\"MANUAL_MATCH\",\"counterpartItemId\":\"" + ledgerItem
                                + "\",\"note\":\"1.00 fee withheld by the bank\"}"))
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.status").value("RECONCILED"))
                .andExpect(jsonPath("$.unresolvedExceptions").value(0));

        mvc.perform(get("/runs/{id}/items", runId).param("kind", "MISSING_IN_BANK"))
                .andExpect(jsonPath("$.items[0].resolution.kind").value("MANUAL_MATCH"))
                .andExpect(jsonPath("$.items[0].resolution.counterpartItemId").value(bankItem));

        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, bankItem)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":\"IGNORE\",\"note\":\"again\"}")),
                409,
                "item-already-resolved");

        mvc.perform(get("/runs/{id}", runId)).andExpect(jsonPath("$.status").value("RECONCILED"));
        mvc.perform(get("/runs").param("status", "RECONCILED"))
                .andExpect(jsonPath("$.items", hasSize(1)))
                .andExpect(jsonPath("$.items[0].id").value(runId));
    }

    @Test
    void reportsEveryInvalidRow() throws Exception {
        ResultActions result = uploadMarch("2026-03-05,CR,,,USD,abc\n2026-04-05,XX,,,USD,1.00\n");

        expectProblem(result, 422, "invalid-statement");
        result.andExpect(jsonPath("$.errors", hasSize(3)))
                .andExpect(jsonPath("$.errors[0].row").value(1))
                .andExpect(jsonPath("$.errors[0].field").value("amount"))
                .andExpect(jsonPath("$.errors[1].field").value("date"))
                .andExpect(jsonPath("$.errors[2].field").value("direction"));
        assertThat(jdbc.queryForObject("SELECT count(*) FROM reconciliation_runs", Long.class))
                .isZero();
    }

    @Test
    void rejectsADuplicateStatementWithTheExistingRunId() throws Exception {
        String rows = "2026-03-05,CR,,,USD,1.00\n";
        String firstId =
                (String) body(uploadMarch(rows).andExpect(status().isCreated())).get("id");

        ResultActions again = uploadMarch(rows);

        expectProblem(again, 409, "duplicate-statement");
        again.andExpect(jsonPath("$.existingRunId").value(firstId));
    }

    @Test
    void sameFileForAnotherPeriodIsNotADuplicate() throws Exception {
        String csv = HEADER + "2026-03-05,CR,,,USD,1.00\n";
        mvc.perform(upload(csv, "USD", "2026-03-01", "2026-03-31")).andExpect(status().isCreated());

        mvc.perform(upload(csv, "USD", "2026-03-01", "2026-03-30")).andExpect(status().isCreated());
    }

    @Test
    void rejectsBadFormFields() throws Exception {
        expectProblem(mvc.perform(upload(HEADER, "CHF", "2026-03-01", "2026-03-31")), 422, "unsupported-currency");
        expectProblem(mvc.perform(upload(HEADER, "USD", "2026-03-31", "2026-03-01")), 422, "invalid-period");
        expectProblem(mvc.perform(upload(HEADER, "USD", "2025-01-01", "2026-03-01")), 422, "invalid-period");
        expectProblem(mvc.perform(upload(HEADER, "USD", "03/01/2026", "2026-03-31")), 422, "validation-failed");
        ResultActions missing = mvc.perform(upload(HEADER, null, "2026-03-01", "2026-03-31"));
        expectProblem(missing, 422, "validation-failed");
        missing.andExpect(jsonPath("$.errors[0].field").value("currency"));
        expectProblem(
                mvc.perform(multipart("/runs")
                        .param("currency", "USD")
                        .param("from", "2026-03-01")
                        .param("to", "2026-03-31")),
                422,
                "validation-failed");
    }

    @Test
    void rejectsStatementsLargerThan5Mb() throws Exception {
        byte[] big = new byte[5 * 1024 * 1024 + 1];
        java.util.Arrays.fill(big, (byte) 'a');

        expectProblem(
                mvc.perform(multipart("/runs")
                        .file(new MockMultipartFile("file", "big.csv", "text/csv", big))
                        .param("currency", "USD")
                        .param("from", "2026-03-01")
                        .param("to", "2026-03-31")),
                413,
                "statement-too-large");
    }

    @Test
    void ledgerOutageIsA502AndStoresNothing() throws Exception {
        ledger.failsWith(new LedgerUnavailableException("connection refused"));

        expectProblem(uploadMarch("2026-03-05,CR,,,USD,1.00\n"), 502, "ledger-unavailable");
        assertThat(jdbc.queryForObject("SELECT count(*) FROM reconciliation_runs", Long.class))
                .isZero();
    }

    @Test
    void validatesResolveRequests() throws Exception {
        String runId = (String) body(uploadMarch("2026-03-05,CR,,,USD,1.00\n")).get("id");
        String itemId = (String) ((Map<?, ?>) ((List<?>) body(mvc.perform(get("/runs/{id}/items", runId)))
                                .get("items"))
                        .get(0))
                .get("id");

        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, itemId)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":\"IGNORE\"}")),
                422,
                "validation-failed");
        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, itemId)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":\"DELETE\",\"note\":\"x\"}")),
                422,
                "validation-failed");
        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, itemId)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"note\":\"x\"}")),
                422,
                "validation-failed");
        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, itemId)
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":")),
                422,
                "validation-failed");
        expectProblem(
                mvc.perform(post("/runs/{id}/items/{item}/resolve", runId, "0199ffff-0000-7000-8000-000000000000")
                        .contentType(MediaType.APPLICATION_JSON)
                        .content("{\"resolution\":\"IGNORE\",\"note\":\"x\"}")),
                404,
                "item-not-found");
    }

    @Test
    void notFoundAndMalformedIds() throws Exception {
        expectProblem(mvc.perform(get("/runs/0199ffff-0000-7000-8000-000000000000")), 404, "run-not-found");
        expectProblem(mvc.perform(get("/runs/not-a-uuid")), 422, "validation-failed");
        expectProblem(mvc.perform(get("/runs").param("status", "LOST")), 422, "validation-failed");
        expectProblem(mvc.perform(get("/runs").param("currency", "XXX")), 422, "unsupported-currency");
        expectProblem(mvc.perform(get("/nope")), 404, "not-found");
    }

    @Test
    void healthShowsTheDatabase() throws Exception {
        mvc.perform(get("/actuator/health"))
                .andExpect(jsonPath("$.components.db.status").value("UP"));
    }
}

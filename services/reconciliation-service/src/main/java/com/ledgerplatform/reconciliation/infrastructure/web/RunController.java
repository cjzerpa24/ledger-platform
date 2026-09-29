package com.ledgerplatform.reconciliation.infrastructure.web;

import com.ledgerplatform.reconciliation.application.GetRun;
import com.ledgerplatform.reconciliation.application.ListItems;
import com.ledgerplatform.reconciliation.application.ListRuns;
import com.ledgerplatform.reconciliation.application.ResolveItem;
import com.ledgerplatform.reconciliation.application.StartReconciliation;
import com.ledgerplatform.reconciliation.domain.matching.ItemKind;
import com.ledgerplatform.reconciliation.domain.run.ReconciliationRun;
import com.ledgerplatform.reconciliation.domain.run.RunStatus;
import jakarta.validation.Valid;
import java.io.IOException;
import java.net.URI;
import java.time.LocalDate;
import java.util.UUID;
import org.springframework.format.annotation.DateTimeFormat;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.multipart.MultipartFile;

@RestController
@RequestMapping("/runs")
public class RunController {

    static final long MAX_STATEMENT_BYTES = 5L * 1024 * 1024;

    private final StartReconciliation start;
    private final ResolveItem resolve;
    private final GetRun getRun;
    private final ListRuns listRuns;
    private final ListItems listItems;

    public RunController(
            StartReconciliation start, ResolveItem resolve, GetRun getRun, ListRuns listRuns, ListItems listItems) {
        this.start = start;
        this.resolve = resolve;
        this.getRun = getRun;
        this.listRuns = listRuns;
        this.listItems = listItems;
    }

    @PostMapping(consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public ResponseEntity<RunResponse> start(
            @RequestParam("file") MultipartFile file,
            @RequestParam("currency") String currency,
            @RequestParam("from") @DateTimeFormat(iso = DateTimeFormat.ISO.DATE) LocalDate from,
            @RequestParam("to") @DateTimeFormat(iso = DateTimeFormat.ISO.DATE) LocalDate to)
            throws IOException {
        if (file.getSize() > MAX_STATEMENT_BYTES) {
            throw new StatementTooLargeException(MAX_STATEMENT_BYTES);
        }
        ReconciliationRun run = start.execute(new StartReconciliation.Command(currency, from, to, file.getBytes()));
        return ResponseEntity.created(URI.create("/runs/" + run.id())).body(RunResponse.from(run));
    }

    @GetMapping
    public ListResponse<RunResponse> list(
            @RequestParam(value = "currency", required = false) String currency,
            @RequestParam(value = "status", required = false) RunStatus status) {
        return new ListResponse<>(listRuns.execute(currency, status).stream()
                .map(RunResponse::from)
                .toList());
    }

    @GetMapping("/{id}")
    public RunResponse get(@PathVariable("id") UUID id) {
        return RunResponse.from(getRun.execute(id));
    }

    @GetMapping("/{id}/items")
    public ListResponse<ItemResponse> items(
            @PathVariable("id") UUID id,
            @RequestParam(value = "kind", required = false) ItemKind kind,
            @RequestParam(value = "unresolved", required = false) Boolean unresolved) {
        return new ListResponse<>(listItems.execute(id, kind, unresolved).stream()
                .map(ItemResponse::from)
                .toList());
    }

    @PostMapping(path = "/{id}/items/{itemId}/resolve", consumes = MediaType.APPLICATION_JSON_VALUE)
    public RunResponse resolve(
            @PathVariable("id") UUID id, @PathVariable("itemId") UUID itemId, @Valid @RequestBody ResolveRequest body) {
        return RunResponse.from(resolve.execute(
                new ResolveItem.Command(id, itemId, body.resolution(), body.counterpartItemId(), body.note())));
    }
}

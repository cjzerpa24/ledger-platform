package com.ledgerplatform.reconciliation.infrastructure.ledger;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import java.util.List;

/** The part of ledger-service's statement response this service reads. */
@JsonIgnoreProperties(ignoreUnknown = true)
record StatementResponse(String currency, List<Line> lines) {

    @JsonIgnoreProperties(ignoreUnknown = true)
    record Line(String transferId, String occurredAt, String direction, String amount, String description) {}
}

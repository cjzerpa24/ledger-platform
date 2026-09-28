package com.ledgerplatform.reconciliation.infrastructure.ledger;

import org.springframework.boot.health.contributor.Health;
import org.springframework.boot.health.contributor.HealthIndicator;
import org.springframework.web.client.RestClient;
import org.springframework.web.client.RestClientException;

/** Reports whether ledger-service answers; a run needs it. */
public final class LedgerHealthIndicator implements HealthIndicator {

    private final RestClient client;

    public LedgerHealthIndicator(RestClient client) {
        this.client = client;
    }

    @Override
    public Health health() {
        try {
            client.get().uri("/health").retrieve().toBodilessEntity();
            return Health.up().build();
        } catch (RestClientException e) {
            return Health.down().withDetail("error", e.getMessage()).build();
        }
    }
}

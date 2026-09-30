package com.ledgerplatform.reconciliation.infrastructure.config;

import com.ledgerplatform.reconciliation.application.GetRun;
import com.ledgerplatform.reconciliation.application.ListItems;
import com.ledgerplatform.reconciliation.application.ListRuns;
import com.ledgerplatform.reconciliation.application.ResolveItem;
import com.ledgerplatform.reconciliation.application.StartReconciliation;
import com.ledgerplatform.reconciliation.application.port.IdGenerator;
import com.ledgerplatform.reconciliation.application.port.LedgerStatementPort;
import com.ledgerplatform.reconciliation.application.port.StatementParser;
import com.ledgerplatform.reconciliation.domain.matching.MatchingPolicy;
import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.infrastructure.csv.BankStatementCsvParser;
import com.ledgerplatform.reconciliation.infrastructure.ledger.LedgerHealthIndicator;
import com.ledgerplatform.reconciliation.infrastructure.ledger.LedgerHttpClient;
import com.ledgerplatform.reconciliation.infrastructure.persistence.JpaRunRepository;
import com.ledgerplatform.reconciliation.infrastructure.persistence.RunJpaRepository;
import com.ledgerplatform.reconciliation.infrastructure.system.UuidV7Generator;
import java.net.http.HttpClient;
import java.time.Clock;
import java.time.Duration;
import org.springframework.boot.context.properties.EnableConfigurationProperties;
import org.springframework.boot.health.contributor.HealthIndicator;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.http.client.JdkClientHttpRequestFactory;
import org.springframework.transaction.PlatformTransactionManager;
import org.springframework.web.client.RestClient;

/** Wires the use cases to their adapters. Use cases stay plain classes without Spring annotations. */
@Configuration(proxyBeanMethods = false)
@EnableConfigurationProperties(ReconciliationProperties.class)
public class ReconciliationConfiguration {

    @Bean
    Clock clock() {
        return Clock.systemUTC();
    }

    @Bean
    IdGenerator idGenerator() {
        return new UuidV7Generator();
    }

    @Bean
    MatchingPolicy matchingPolicy(ReconciliationProperties properties) {
        return new MatchingPolicy(properties.matchWindowDays());
    }

    @Bean
    RestClient ledgerRestClient(RestClient.Builder builder, ReconciliationProperties properties) {
        Duration timeout = Duration.ofMillis(properties.ledgerTimeoutMs());
        JdkClientHttpRequestFactory requestFactory = new JdkClientHttpRequestFactory(
                HttpClient.newBuilder().connectTimeout(timeout).build());
        requestFactory.setReadTimeout(timeout);
        return builder.baseUrl(properties.ledgerBaseUrl())
                .requestFactory(requestFactory)
                .build();
    }

    @Bean
    LedgerStatementPort ledgerStatementPort(RestClient ledgerRestClient) {
        return new LedgerHttpClient(ledgerRestClient);
    }

    /** Shows up as the "ledger" component of /actuator/health. */
    @Bean
    HealthIndicator ledgerHealthIndicator(RestClient ledgerRestClient) {
        return new LedgerHealthIndicator(ledgerRestClient);
    }

    @Bean
    StatementParser statementParser() {
        return new BankStatementCsvParser();
    }

    @Bean
    RunRepository runRepository(RunJpaRepository runs, PlatformTransactionManager transactionManager) {
        return new JpaRunRepository(runs, transactionManager);
    }

    @Bean
    StartReconciliation startReconciliation(
            RunRepository runs,
            StatementParser parser,
            LedgerStatementPort ledger,
            IdGenerator ids,
            Clock clock,
            MatchingPolicy policy) {
        return new StartReconciliation(runs, parser, ledger, ids, clock, policy);
    }

    @Bean
    ResolveItem resolveItem(RunRepository runs, Clock clock) {
        return new ResolveItem(runs, clock);
    }

    @Bean
    GetRun getRun(RunRepository runs) {
        return new GetRun(runs);
    }

    @Bean
    ListRuns listRuns(RunRepository runs) {
        return new ListRuns(runs);
    }

    @Bean
    ListItems listItems(RunRepository runs) {
        return new ListItems(runs);
    }
}

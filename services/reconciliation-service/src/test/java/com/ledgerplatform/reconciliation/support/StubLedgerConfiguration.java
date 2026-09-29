package com.ledgerplatform.reconciliation.support;

import org.springframework.boot.test.context.TestConfiguration;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Primary;

@TestConfiguration(proxyBeanMethods = false)
public class StubLedgerConfiguration {

    @Bean
    @Primary
    StubLedger stubLedger() {
        return new StubLedger();
    }
}

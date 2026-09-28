package com.ledgerplatform.reconciliation.infrastructure.config;

import com.ledgerplatform.reconciliation.domain.run.RunRepository;
import com.ledgerplatform.reconciliation.infrastructure.persistence.JpaRunRepository;
import com.ledgerplatform.reconciliation.infrastructure.persistence.RunJpaRepository;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.transaction.PlatformTransactionManager;

/** Wires the use cases to their adapters. Use cases stay plain classes without Spring annotations. */
@Configuration(proxyBeanMethods = false)
public class ReconciliationConfiguration {

    @Bean
    RunRepository runRepository(RunJpaRepository runs, PlatformTransactionManager transactionManager) {
        return new JpaRunRepository(runs, transactionManager);
    }
}

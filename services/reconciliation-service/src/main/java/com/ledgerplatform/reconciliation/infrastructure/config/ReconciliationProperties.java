package com.ledgerplatform.reconciliation.infrastructure.config;

import jakarta.validation.constraints.Max;
import jakarta.validation.constraints.Min;
import jakarta.validation.constraints.NotBlank;
import org.springframework.boot.context.properties.ConfigurationProperties;
import org.springframework.validation.annotation.Validated;

/** Validated at startup: an invalid value stops the application. */
@Validated
@ConfigurationProperties("reconciliation")
public record ReconciliationProperties(
        @NotBlank String ledgerBaseUrl,
        @Min(100) @Max(60_000) int ledgerTimeoutMs,
        @Min(0) @Max(7) int matchWindowDays) {}

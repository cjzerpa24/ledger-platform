package com.ledgerplatform.reconciliation.infrastructure.persistence;

import jakarta.persistence.CascadeType;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.OneToMany;
import jakarta.persistence.OrderBy;
import jakarta.persistence.Table;
import jakarta.persistence.Version;
import java.time.Instant;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;

@Entity
@Table(name = "reconciliation_runs")
public class RunEntity {

    @Id
    UUID id;

    @Column(nullable = false)
    String currency;

    @Column(name = "period_from", nullable = false)
    LocalDate periodFrom;

    @Column(name = "period_to", nullable = false)
    LocalDate periodTo;

    @Column(name = "statement_sha256", nullable = false)
    String statementSha256;

    @Column(nullable = false)
    String status;

    @Column(name = "created_at", nullable = false)
    Instant createdAt;

    /** Null until the first insert, so Spring Data persists instead of merging. */
    @Version
    Long version;

    @OneToMany(mappedBy = "run", cascade = CascadeType.ALL, orphanRemoval = true)
    @OrderBy("position")
    List<ItemEntity> items = new ArrayList<>();

    protected RunEntity() {}
}

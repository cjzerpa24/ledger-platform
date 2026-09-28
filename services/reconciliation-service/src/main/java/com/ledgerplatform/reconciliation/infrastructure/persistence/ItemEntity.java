package com.ledgerplatform.reconciliation.infrastructure.persistence;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.FetchType;
import jakarta.persistence.Id;
import jakarta.persistence.JoinColumn;
import jakarta.persistence.ManyToOne;
import jakarta.persistence.Table;
import java.time.Instant;
import java.time.LocalDate;
import java.util.UUID;
import org.hibernate.annotations.JdbcTypeCode;
import org.hibernate.type.SqlTypes;

@Entity
@Table(name = "reconciliation_items")
public class ItemEntity {

    @Id
    UUID id;

    @ManyToOne(fetch = FetchType.LAZY, optional = false)
    @JoinColumn(name = "run_id")
    RunEntity run;

    @Column(nullable = false)
    int position;

    @Column(nullable = false)
    String kind;

    @Column(name = "match_method")
    String matchMethod;

    @Column(name = "bank_row")
    Integer bankRow;

    @Column(name = "bank_date")
    LocalDate bankDate;

    @Column(name = "bank_direction")
    String bankDirection;

    @Column(name = "bank_description")
    String bankDescription;

    @Column(name = "bank_reference")
    String bankReference;

    @Column(name = "bank_amount_minor")
    Long bankAmountMinor;

    @Column(name = "ledger_transfer_id")
    UUID ledgerTransferId;

    @Column(name = "ledger_occurred_at")
    Instant ledgerOccurredAt;

    @Column(name = "ledger_direction")
    String ledgerDirection;

    @Column(name = "ledger_amount_minor")
    Long ledgerAmountMinor;

    @Column(name = "ledger_description")
    String ledgerDescription;

    /** JSON array of candidate ledger entries (AMBIGUOUS items only). */
    @JdbcTypeCode(SqlTypes.JSON)
    String candidates;

    @Column(name = "resolution_kind")
    String resolutionKind;

    @Column(name = "resolution_note")
    String resolutionNote;

    @Column(name = "counterpart_item_id")
    UUID counterpartItemId;

    @Column(name = "resolved_at")
    Instant resolvedAt;

    protected ItemEntity() {}
}

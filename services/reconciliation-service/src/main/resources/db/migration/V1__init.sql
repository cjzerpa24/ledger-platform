CREATE TABLE reconciliation_runs (
    id               UUID        PRIMARY KEY,
    currency         TEXT        NOT NULL CHECK (char_length(currency) = 3),
    period_from      DATE        NOT NULL,
    period_to        DATE        NOT NULL,
    statement_sha256 TEXT        NOT NULL CHECK (char_length(statement_sha256) = 64),
    status           TEXT        NOT NULL,
    created_at       TIMESTAMPTZ NOT NULL,
    version          BIGINT      NOT NULL,
    CONSTRAINT uq_runs_statement UNIQUE (currency, period_from, period_to, statement_sha256)
);

CREATE INDEX idx_runs_created_at ON reconciliation_runs (created_at DESC);

CREATE TABLE reconciliation_items (
    id                  UUID        PRIMARY KEY,
    run_id              UUID        NOT NULL REFERENCES reconciliation_runs (id) ON DELETE CASCADE,
    position            INT         NOT NULL,
    kind                TEXT        NOT NULL,
    match_method        TEXT,
    bank_row            INT,
    bank_date           DATE,
    bank_direction      TEXT,
    bank_description    TEXT,
    bank_reference      TEXT,
    bank_amount_minor   BIGINT,
    ledger_transfer_id  UUID,
    ledger_occurred_at  TIMESTAMPTZ,
    ledger_direction    TEXT,
    ledger_amount_minor BIGINT,
    ledger_description  TEXT,
    candidates          JSONB,
    resolution_kind     TEXT,
    resolution_note     TEXT,
    counterpart_item_id UUID,
    resolved_at         TIMESTAMPTZ,
    CONSTRAINT uq_items_position UNIQUE (run_id, position)
);

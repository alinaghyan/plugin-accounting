CREATE TABLE IF NOT EXISTS mda_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mda_parties (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    party_code TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    is_customer INTEGER NOT NULL DEFAULT 0 CHECK (is_customer IN (0,1)),
    is_supplier INTEGER NOT NULL DEFAULT 0 CHECK (is_supplier IN (0,1)),
    is_employee INTEGER NOT NULL DEFAULT 0 CHECK (is_employee IN (0,1)),
    is_partner INTEGER NOT NULL DEFAULT 0 CHECK (is_partner IN (0,1)),
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_mda_parties_phone ON mda_parties(phone);
CREATE INDEX IF NOT EXISTS idx_mda_parties_name ON mda_parties(display_name);

CREATE TABLE IF NOT EXISTS mda_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_code TEXT NOT NULL UNIQUE,
    account_name TEXT NOT NULL,
    account_type TEXT NOT NULL CHECK (account_type IN ('asset','liability','equity','income','expense')),
    parent_id INTEGER,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    created_at TEXT NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES mda_accounts(id)
);

CREATE TABLE IF NOT EXISTS mda_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_number TEXT NOT NULL UNIQUE,
    document_type TEXT NOT NULL,
    document_status TEXT NOT NULL DEFAULT 'draft' CHECK (document_status IN ('draft','posted','voided')),
    party_id INTEGER,
    source_type TEXT,
    source_id TEXT,
    idempotency_key TEXT UNIQUE,
    currency_code TEXT NOT NULL DEFAULT 'IRR',
    amount_scale INTEGER NOT NULL DEFAULT 1,
    document_date TEXT NOT NULL,
    description TEXT,
    created_by INTEGER,
    created_at TEXT NOT NULL,
    posted_at TEXT,
    voided_at TEXT,
    reversal_document_id INTEGER,
    FOREIGN KEY (party_id) REFERENCES mda_parties(id),
    FOREIGN KEY (reversal_document_id) REFERENCES mda_documents(id)
);

CREATE INDEX IF NOT EXISTS idx_mda_documents_date ON mda_documents(document_date);
CREATE INDEX IF NOT EXISTS idx_mda_documents_party ON mda_documents(party_id);
CREATE INDEX IF NOT EXISTS idx_mda_documents_source ON mda_documents(source_type, source_id);

CREATE TABLE IF NOT EXISTS mda_journal_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL,
    entry_number INTEGER NOT NULL,
    account_id INTEGER NOT NULL,
    party_id INTEGER,
    debit_minor INTEGER NOT NULL DEFAULT 0 CHECK (debit_minor >= 0),
    credit_minor INTEGER NOT NULL DEFAULT 0 CHECK (credit_minor >= 0),
    memo TEXT,
    created_at TEXT NOT NULL,
    CHECK ((debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0 AND debit_minor = 0)),
    UNIQUE (document_id, entry_number),
    FOREIGN KEY (document_id) REFERENCES mda_documents(id),
    FOREIGN KEY (account_id) REFERENCES mda_accounts(id),
    FOREIGN KEY (party_id) REFERENCES mda_parties(id)
);

CREATE INDEX IF NOT EXISTS idx_mda_journal_account ON mda_journal_entries(account_id);
CREATE INDEX IF NOT EXISTS idx_mda_journal_party ON mda_journal_entries(party_id);

CREATE TABLE IF NOT EXISTS mda_warehouses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    warehouse_code TEXT NOT NULL UNIQUE,
    warehouse_name TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mda_inventory_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id BIGINT NOT NULL,
    variation_id BIGINT NOT NULL DEFAULT 0,
    warehouse_id INTEGER NOT NULL,
    movement_type TEXT NOT NULL,
    quantity_scaled INTEGER NOT NULL,
    quantity_scale INTEGER NOT NULL DEFAULT 1000000,
    unit_cost_minor INTEGER,
    currency_code TEXT NOT NULL DEFAULT 'IRR',
    source_type TEXT,
    source_id TEXT,
    idempotency_key TEXT UNIQUE,
    occurred_at TEXT NOT NULL,
    created_by INTEGER,
    FOREIGN KEY (warehouse_id) REFERENCES mda_warehouses(id)
);

CREATE INDEX IF NOT EXISTS idx_mda_inventory_product ON mda_inventory_movements(product_id, variation_id, warehouse_id, occurred_at);

CREATE TABLE IF NOT EXISTS mda_product_cost_state (
    product_id BIGINT NOT NULL,
    variation_id BIGINT NOT NULL DEFAULT 0,
    warehouse_id INTEGER NOT NULL,
    quantity_scaled INTEGER NOT NULL DEFAULT 0,
    quantity_scale INTEGER NOT NULL DEFAULT 1000000,
    average_cost_minor INTEGER NOT NULL DEFAULT 0,
    currency_code TEXT NOT NULL DEFAULT 'IRR',
    updated_at TEXT NOT NULL,
    PRIMARY KEY (product_id, variation_id, warehouse_id),
    FOREIGN KEY (warehouse_id) REFERENCES mda_warehouses(id)
);

CREATE TABLE IF NOT EXISTS mda_integration_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_key TEXT NOT NULL UNIQUE,
    event_type TEXT NOT NULL,
    aggregate_type TEXT NOT NULL,
    aggregate_id TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','processing','completed','failed')),
    attempts INTEGER NOT NULL DEFAULT 0,
    available_at TEXT NOT NULL,
    last_error TEXT,
    created_at TEXT NOT NULL,
    processed_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_mda_outbox_queue ON mda_integration_outbox(status, available_at);

CREATE TABLE IF NOT EXISTS mda_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    object_type TEXT NOT NULL,
    object_id TEXT,
    before_json TEXT,
    after_json TEXT,
    ip_hash TEXT,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_mda_audit_object ON mda_audit_logs(object_type, object_id, created_at);

CREATE TABLE IF NOT EXISTS mda_financial_periods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_name TEXT NOT NULL,
    starts_on TEXT NOT NULL,
    ends_on TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
    closed_by INTEGER,
    closed_at TEXT
);

CREATE TABLE IF NOT EXISTS mda_purchase_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier_party_id INTEGER NOT NULL,
    product_id BIGINT NOT NULL,
    variation_id BIGINT NOT NULL DEFAULT 0,
    available_quantity_scaled INTEGER,
    quantity_scale INTEGER NOT NULL DEFAULT 1000000,
    last_confirmed_at TEXT,
    expected_lead_days INTEGER,
    source_status TEXT NOT NULL DEFAULT 'unconfirmed' CHECK (source_status IN ('unconfirmed','available','unavailable','preorder')),
    notes TEXT,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (supplier_party_id) REFERENCES mda_parties(id)
);
CREATE INDEX IF NOT EXISTS idx_mda_purchase_sources_product ON mda_purchase_sources(product_id, variation_id, source_status);

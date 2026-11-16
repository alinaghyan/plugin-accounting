-- Multi-stage supplier settlements and immutable purchase return documents.
ALTER TABLE mda_purchase_payments ADD COLUMN accounting_document_id INTEGER REFERENCES mda_documents(id) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS mda_purchase_returns (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 return_number TEXT NOT NULL UNIQUE,
 purchase_id INTEGER NOT NULL,
 supplier_party_id INTEGER NOT NULL,
 warehouse_id INTEGER NOT NULL,
 document_date TEXT NOT NULL,
 currency_code TEXT NOT NULL,
 total_minor INTEGER NOT NULL CHECK (total_minor > 0),
 status TEXT NOT NULL DEFAULT 'posted' CHECK (status='posted'),
 idempotency_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 accounting_document_id INTEGER NOT NULL,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 FOREIGN KEY (purchase_id) REFERENCES mda_purchase_documents(id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_party_id) REFERENCES mda_parties(id) ON DELETE RESTRICT,
 FOREIGN KEY (warehouse_id) REFERENCES mda_warehouses(id) ON DELETE RESTRICT,
 FOREIGN KEY (accounting_document_id) REFERENCES mda_documents(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_purchase_returns_purchase ON mda_purchase_returns(purchase_id,created_at);

CREATE TABLE IF NOT EXISTS mda_purchase_return_lines (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 return_id INTEGER NOT NULL,
 purchase_line_id INTEGER NOT NULL,
 product_id BIGINT NOT NULL,
 variation_id BIGINT NOT NULL DEFAULT 0,
 quantity_scaled INTEGER NOT NULL CHECK (quantity_scaled > 0),
 quantity_scale INTEGER NOT NULL CHECK (quantity_scale > 0),
 unit_cost_minor INTEGER NOT NULL CHECK (unit_cost_minor >= 0),
 line_total_minor INTEGER NOT NULL CHECK (line_total_minor > 0),
 created_at TEXT NOT NULL,
 UNIQUE (return_id,purchase_line_id),
 FOREIGN KEY (return_id) REFERENCES mda_purchase_returns(id) ON DELETE RESTRICT,
 FOREIGN KEY (purchase_line_id) REFERENCES mda_purchase_lines(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_purchase_return_lines_source ON mda_purchase_return_lines(purchase_line_id);

CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_returns_no_update
BEFORE UPDATE ON mda_purchase_returns
BEGIN SELECT RAISE(ABORT,'Posted purchase returns are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_returns_no_delete
BEFORE DELETE ON mda_purchase_returns
BEGIN SELECT RAISE(ABORT,'Purchase returns cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_return_lines_no_update
BEFORE UPDATE ON mda_purchase_return_lines
BEGIN SELECT RAISE(ABORT,'Purchase return lines are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_return_lines_no_delete
BEFORE DELETE ON mda_purchase_return_lines
BEGIN SELECT RAISE(ABORT,'Purchase return lines cannot be deleted.'); END;

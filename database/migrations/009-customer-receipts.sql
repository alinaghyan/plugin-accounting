-- Append-only customer receipts against posted sales; no WooCommerce payment inference.
CREATE TABLE IF NOT EXISTS mda_sales_receipts (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 sales_document_id INTEGER NOT NULL,
 party_id INTEGER NOT NULL,
 amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
 cash_account_id INTEGER NOT NULL,
 payment_method TEXT NOT NULL CHECK (payment_method IN ('cash','bank','pos','transfer')),
 payment_date TEXT NOT NULL,
 idempotency_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 accounting_document_id INTEGER NOT NULL,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 FOREIGN KEY (sales_document_id) REFERENCES mda_sales_documents(id) ON DELETE RESTRICT,
 FOREIGN KEY (party_id) REFERENCES mda_parties(id) ON DELETE RESTRICT,
 FOREIGN KEY (cash_account_id) REFERENCES mda_accounts(id) ON DELETE RESTRICT,
 FOREIGN KEY (accounting_document_id) REFERENCES mda_documents(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_sales_receipts_sale ON mda_sales_receipts(sales_document_id,payment_date);
CREATE TRIGGER IF NOT EXISTS trg_mda_sales_receipts_no_update
BEFORE UPDATE ON mda_sales_receipts
BEGIN SELECT RAISE(ABORT,'Posted customer receipts are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_sales_receipts_no_delete
BEFORE DELETE ON mda_sales_receipts
BEGIN SELECT RAISE(ABORT,'Posted customer receipts cannot be deleted.'); END;

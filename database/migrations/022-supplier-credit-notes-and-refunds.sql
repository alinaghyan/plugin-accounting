-- 022: supplier credit-note confirmation and immutable supplier refunds.
CREATE TABLE IF NOT EXISTS mda_supplier_credit_notes (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 return_id INTEGER NOT NULL UNIQUE,
 purchase_id INTEGER NOT NULL,
 supplier_party_id INTEGER NOT NULL,
 credit_note_number TEXT NOT NULL,
 amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
 currency_code TEXT NOT NULL,
 document_date TEXT NOT NULL,
 idempotency_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 FOREIGN KEY (return_id) REFERENCES mda_purchase_returns(id) ON DELETE RESTRICT,
 FOREIGN KEY (purchase_id) REFERENCES mda_purchase_documents(id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_party_id) REFERENCES mda_parties(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_supplier_credit_notes_supplier ON mda_supplier_credit_notes(supplier_party_id,document_date);
CREATE TABLE IF NOT EXISTS mda_purchase_refunds (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 credit_note_id INTEGER NOT NULL,
 return_id INTEGER NOT NULL,
 purchase_id INTEGER NOT NULL,
 supplier_party_id INTEGER NOT NULL,
 amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
 cash_account_id INTEGER NOT NULL,
 payment_method TEXT NOT NULL CHECK (payment_method IN ('cash','bank','pos','transfer')),
 payment_date TEXT NOT NULL,
 idempotency_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 accounting_document_id INTEGER NOT NULL,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 FOREIGN KEY (credit_note_id) REFERENCES mda_supplier_credit_notes(id) ON DELETE RESTRICT,
 FOREIGN KEY (return_id) REFERENCES mda_purchase_returns(id) ON DELETE RESTRICT,
 FOREIGN KEY (purchase_id) REFERENCES mda_purchase_documents(id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_party_id) REFERENCES mda_parties(id) ON DELETE RESTRICT,
 FOREIGN KEY (cash_account_id) REFERENCES mda_accounts(id) ON DELETE RESTRICT,
 FOREIGN KEY (accounting_document_id) REFERENCES mda_documents(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_purchase_refunds_credit_note ON mda_purchase_refunds(credit_note_id,payment_date);
CREATE TRIGGER IF NOT EXISTS trg_mda_supplier_credit_notes_no_update
BEFORE UPDATE ON mda_supplier_credit_notes BEGIN SELECT RAISE(ABORT,'Supplier credit notes are append-only.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_supplier_credit_notes_no_delete
BEFORE DELETE ON mda_supplier_credit_notes BEGIN SELECT RAISE(ABORT,'Supplier credit notes cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_refunds_no_update
BEFORE UPDATE ON mda_purchase_refunds BEGIN SELECT RAISE(ABORT,'Supplier refunds are append-only.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_refunds_no_delete
BEFORE DELETE ON mda_purchase_refunds BEGIN SELECT RAISE(ABORT,'Supplier refunds cannot be deleted.'); END;

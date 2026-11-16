-- Manual bank statement imports and append-only reconciliation events.
CREATE TABLE IF NOT EXISTS mda_bank_statement_imports (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 bank_account_id INTEGER NOT NULL,
 currency_code TEXT NOT NULL CHECK (currency_code IN ('IRR','IRT')),
 statement_reference TEXT NOT NULL,
 period_start TEXT NOT NULL,
 period_end TEXT NOT NULL,
 opening_balance_minor INTEGER NOT NULL,
 closing_balance_minor INTEGER NOT NULL,
 line_count INTEGER NOT NULL CHECK (line_count > 0),
 idempotency_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 UNIQUE(bank_account_id,currency_code,statement_reference),
 FOREIGN KEY (bank_account_id) REFERENCES mda_accounts(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_bank_import_account_period ON mda_bank_statement_imports(bank_account_id,currency_code,period_start,period_end);

CREATE TABLE IF NOT EXISTS mda_bank_statement_lines (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 import_id INTEGER NOT NULL,
 line_number INTEGER NOT NULL,
 transaction_date TEXT NOT NULL,
 direction TEXT NOT NULL CHECK (direction IN ('credit','debit')),
 amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
 reference_number TEXT,
 description TEXT NOT NULL,
 payload_hash TEXT NOT NULL,
 created_at TEXT NOT NULL,
 UNIQUE(import_id,line_number),
 FOREIGN KEY (import_id) REFERENCES mda_bank_statement_imports(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_bank_statement_lines_date ON mda_bank_statement_lines(import_id,transaction_date,direction,amount_minor);

CREATE TABLE IF NOT EXISTS mda_bank_reconciliation_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 statement_line_id INTEGER NOT NULL,
 action TEXT NOT NULL CHECK (action IN ('matched','unmatched','ignored','unignored')),
 journal_entry_id INTEGER,
 previous_event_id INTEGER,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 FOREIGN KEY (statement_line_id) REFERENCES mda_bank_statement_lines(id) ON DELETE RESTRICT,
 FOREIGN KEY (journal_entry_id) REFERENCES mda_journal_entries(id) ON DELETE RESTRICT,
 FOREIGN KEY (previous_event_id) REFERENCES mda_bank_reconciliation_events(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_bank_reconciliation_line ON mda_bank_reconciliation_events(statement_line_id,id);
CREATE INDEX IF NOT EXISTS idx_mda_bank_reconciliation_journal ON mda_bank_reconciliation_events(journal_entry_id,id);

CREATE TRIGGER IF NOT EXISTS trg_mda_bank_imports_no_update
BEFORE UPDATE ON mda_bank_statement_imports
BEGIN SELECT RAISE(ABORT,'Imported bank statements are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_bank_imports_no_delete
BEFORE DELETE ON mda_bank_statement_imports
BEGIN SELECT RAISE(ABORT,'Imported bank statements cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_bank_lines_no_update
BEFORE UPDATE ON mda_bank_statement_lines
BEGIN SELECT RAISE(ABORT,'Imported bank statement lines are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_bank_lines_no_delete
BEFORE DELETE ON mda_bank_statement_lines
BEGIN SELECT RAISE(ABORT,'Imported bank statement lines cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_bank_reconciliation_no_update
BEFORE UPDATE ON mda_bank_reconciliation_events
BEGIN SELECT RAISE(ABORT,'Bank reconciliation history is append-only.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_bank_reconciliation_no_delete
BEFORE DELETE ON mda_bank_reconciliation_events
BEGIN SELECT RAISE(ABORT,'Bank reconciliation history cannot be deleted.'); END;

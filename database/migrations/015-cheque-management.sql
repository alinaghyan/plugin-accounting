-- Dedicated cheque accounts and append-only cheque lifecycle.
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '1130','اسناد دریافتنی - چک','asset',id,1,CURRENT_TIMESTAMP,'debit',0,1,0,'چک‌های دریافتی وصول‌نشده',45 FROM mda_accounts WHERE account_code='1000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '2130','اسناد پرداختنی - چک','liability',id,1,CURRENT_TIMESTAMP,'credit',0,1,0,'چک‌های صادرشده وصول‌نشده',85 FROM mda_accounts WHERE account_code='2000';

CREATE TABLE IF NOT EXISTS mda_cheques (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 cheque_type TEXT NOT NULL CHECK (cheque_type IN ('received','issued')),
 party_id INTEGER NOT NULL,
 bank_account_id INTEGER NOT NULL,
 cheque_number TEXT NOT NULL,
 amount_minor INTEGER NOT NULL CHECK (amount_minor>0),
 currency_code TEXT NOT NULL CHECK (currency_code IN ('IRR','IRT')),
 issue_date TEXT NOT NULL,
 due_date TEXT NOT NULL,
 description TEXT,
 idempotency_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 UNIQUE(cheque_type,bank_account_id,cheque_number),
 FOREIGN KEY (party_id) REFERENCES mda_parties(id) ON DELETE RESTRICT,
 FOREIGN KEY (bank_account_id) REFERENCES mda_accounts(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_cheques_due ON mda_cheques(due_date,cheque_type);
CREATE INDEX IF NOT EXISTS idx_mda_cheques_party ON mda_cheques(party_id,cheque_type);

CREATE TABLE IF NOT EXISTS mda_cheque_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 cheque_id INTEGER NOT NULL,
 action TEXT NOT NULL CHECK (action IN ('registered','deposit','clear','bounce','void')),
 status_after TEXT NOT NULL CHECK (status_after IN ('registered','deposited','cleared','bounced','voided')),
 accounting_document_id INTEGER,
 previous_event_id INTEGER,
 idempotency_key TEXT NOT NULL UNIQUE,
 note TEXT,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 FOREIGN KEY (cheque_id) REFERENCES mda_cheques(id) ON DELETE RESTRICT,
 FOREIGN KEY (accounting_document_id) REFERENCES mda_documents(id) ON DELETE RESTRICT,
 FOREIGN KEY (previous_event_id) REFERENCES mda_cheque_events(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_cheque_events_latest ON mda_cheque_events(cheque_id,id);

CREATE TRIGGER IF NOT EXISTS trg_mda_cheques_no_update BEFORE UPDATE ON mda_cheques
BEGIN SELECT RAISE(ABORT,'Cheque master records are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_cheques_no_delete BEFORE DELETE ON mda_cheques
BEGIN SELECT RAISE(ABORT,'Cheque master records cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_cheque_events_no_update BEFORE UPDATE ON mda_cheque_events
BEGIN SELECT RAISE(ABORT,'Cheque event history is append-only.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_cheque_events_no_delete BEFORE DELETE ON mda_cheque_events
BEGIN SELECT RAISE(ABORT,'Cheque event history cannot be deleted.'); END;

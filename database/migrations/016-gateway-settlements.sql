-- Generic gateway clearing and immutable settlement reconciliation.
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '1140','مطالبات درگاه‌های پرداخت','asset',id,1,CURRENT_TIMESTAMP,'debit',0,1,0,'حساب واسط تسویه درگاه‌ها',45 FROM mda_accounts WHERE account_code='1000';

CREATE TABLE IF NOT EXISTS mda_gateway_accounts (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 gateway_key TEXT NOT NULL UNIQUE,
 gateway_name TEXT NOT NULL,
 account_id INTEGER NOT NULL UNIQUE,
 created_at TEXT NOT NULL,
 FOREIGN KEY(account_id) REFERENCES mda_accounts(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS mda_gateway_payments (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 gateway_key TEXT NOT NULL,
 order_id BIGINT NOT NULL,
 sales_document_id INTEGER NOT NULL,
 gross_minor INTEGER NOT NULL CHECK(gross_minor>0),
 currency_code TEXT NOT NULL CHECK(currency_code IN ('IRR','IRT')),
 reference_number TEXT,
 captured_at TEXT NOT NULL,
 idempotency_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 accounting_document_id INTEGER NOT NULL,
 created_at TEXT NOT NULL,
 UNIQUE(gateway_key,order_id),
 FOREIGN KEY(sales_document_id) REFERENCES mda_sales_documents(id) ON DELETE RESTRICT,
 FOREIGN KEY(accounting_document_id) REFERENCES mda_documents(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS mda_gateway_settlements (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 gateway_key TEXT NOT NULL,
 settlement_reference TEXT NOT NULL,
 bank_account_id INTEGER NOT NULL,
 currency_code TEXT NOT NULL CHECK(currency_code IN ('IRR','IRT')),
 settlement_date TEXT NOT NULL,
 row_count INTEGER NOT NULL CHECK(row_count>0),
 idempotency_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 created_at TEXT NOT NULL,
 UNIQUE(gateway_key,settlement_reference),
 FOREIGN KEY(bank_account_id) REFERENCES mda_accounts(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS mda_gateway_settlement_lines (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 settlement_id INTEGER NOT NULL,
 line_number INTEGER NOT NULL,
 payment_id INTEGER NOT NULL,
 order_id BIGINT NOT NULL,
 gross_minor INTEGER NOT NULL CHECK(gross_minor>0),
 fee_minor INTEGER NOT NULL CHECK(fee_minor>=0),
 net_minor INTEGER NOT NULL CHECK(net_minor>0),
 reference_number TEXT,
 payload_hash TEXT NOT NULL,
 created_at TEXT NOT NULL,
 UNIQUE(settlement_id,line_number),
 CHECK(gross_minor=fee_minor+net_minor),
 FOREIGN KEY(settlement_id) REFERENCES mda_gateway_settlements(id) ON DELETE RESTRICT,
 FOREIGN KEY(payment_id) REFERENCES mda_gateway_payments(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS mda_gateway_settlement_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 settlement_id INTEGER NOT NULL,
 action TEXT NOT NULL CHECK(action IN ('posted','reversed')),
 accounting_document_id INTEGER,
 idempotency_key TEXT NOT NULL UNIQUE,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 FOREIGN KEY(settlement_id) REFERENCES mda_gateway_settlements(id) ON DELETE RESTRICT,
 FOREIGN KEY(accounting_document_id) REFERENCES mda_documents(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_gateway_payment_order ON mda_gateway_payments(order_id,gateway_key);
CREATE INDEX IF NOT EXISTS idx_mda_gateway_settlement_date ON mda_gateway_settlements(settlement_date,gateway_key);
CREATE INDEX IF NOT EXISTS idx_mda_gateway_lines_payment ON mda_gateway_settlement_lines(payment_id,settlement_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_mda_gateway_settlement_payment ON mda_gateway_settlement_lines(settlement_id,payment_id);
CREATE TRIGGER IF NOT EXISTS trg_mda_gateway_payments_no_update BEFORE UPDATE ON mda_gateway_payments BEGIN SELECT RAISE(ABORT,'Gateway payments are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_gateway_payments_no_delete BEFORE DELETE ON mda_gateway_payments BEGIN SELECT RAISE(ABORT,'Gateway payments cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_gateway_settlements_no_update BEFORE UPDATE ON mda_gateway_settlements BEGIN SELECT RAISE(ABORT,'Gateway settlements are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_gateway_settlements_no_delete BEFORE DELETE ON mda_gateway_settlements BEGIN SELECT RAISE(ABORT,'Gateway settlements cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_gateway_lines_no_update BEFORE UPDATE ON mda_gateway_settlement_lines BEGIN SELECT RAISE(ABORT,'Gateway settlement lines are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_gateway_lines_no_delete BEFORE DELETE ON mda_gateway_settlement_lines BEGIN SELECT RAISE(ABORT,'Gateway settlement lines cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_gateway_events_no_update BEFORE UPDATE ON mda_gateway_settlement_events BEGIN SELECT RAISE(ABORT,'Gateway settlement events are append-only.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_gateway_events_no_delete BEFORE DELETE ON mda_gateway_settlement_events BEGIN SELECT RAISE(ABORT,'Gateway settlement events cannot be deleted.'); END;

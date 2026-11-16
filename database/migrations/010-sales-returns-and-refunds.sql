-- Immutable customer returns and actual refund payments.
ALTER TABLE mda_sales_lines ADD COLUMN tax_minor INTEGER NOT NULL DEFAULT 0 CHECK (tax_minor >= 0);
ALTER TABLE mda_sales_documents ADD COLUMN tax_snapshot_complete INTEGER NOT NULL DEFAULT 0 CHECK (tax_snapshot_complete IN (0,1));

INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '4110','برگشت از فروش','income',id,1,CURRENT_TIMESTAMP,'debit',0,1,0,'کاهنده درآمد فروش کالا بابت مرجوعی مشتری',111 FROM mda_accounts WHERE account_code='4100';

CREATE TABLE IF NOT EXISTS mda_sales_returns (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 sales_document_id INTEGER NOT NULL,
 order_id BIGINT NOT NULL,
 party_id INTEGER NOT NULL,
 warehouse_id INTEGER NOT NULL,
 return_number TEXT NOT NULL UNIQUE,
 document_date TEXT NOT NULL,
 currency_code TEXT NOT NULL,
 product_total_minor INTEGER NOT NULL CHECK (product_total_minor >= 0),
 tax_total_minor INTEGER NOT NULL CHECK (tax_total_minor >= 0),
 total_minor INTEGER NOT NULL CHECK (total_minor > 0),
 cogs_reversed_minor INTEGER NOT NULL CHECK (cogs_reversed_minor >= 0),
 idempotency_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 accounting_document_id INTEGER NOT NULL,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 FOREIGN KEY (sales_document_id) REFERENCES mda_sales_documents(id) ON DELETE RESTRICT,
 FOREIGN KEY (party_id) REFERENCES mda_parties(id) ON DELETE RESTRICT,
 FOREIGN KEY (warehouse_id) REFERENCES mda_warehouses(id) ON DELETE RESTRICT,
 FOREIGN KEY (accounting_document_id) REFERENCES mda_documents(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_sales_returns_sale ON mda_sales_returns(sales_document_id,document_date);
CREATE INDEX IF NOT EXISTS idx_mda_sales_returns_party ON mda_sales_returns(party_id,document_date);

CREATE TABLE IF NOT EXISTS mda_sales_return_lines (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 sales_return_id INTEGER NOT NULL,
 sales_line_id INTEGER NOT NULL,
 product_id BIGINT NOT NULL,
 variation_id BIGINT NOT NULL DEFAULT 0,
 quantity_scaled INTEGER NOT NULL CHECK (quantity_scaled > 0),
 quantity_scale INTEGER NOT NULL CHECK (quantity_scale > 0),
 revenue_minor INTEGER NOT NULL CHECK (revenue_minor >= 0),
 tax_minor INTEGER NOT NULL CHECK (tax_minor >= 0),
 cogs_minor INTEGER NOT NULL CHECK (cogs_minor >= 0),
 unit_cost_minor INTEGER NOT NULL CHECK (unit_cost_minor >= 0),
 created_at TEXT NOT NULL,
 FOREIGN KEY (sales_return_id) REFERENCES mda_sales_returns(id) ON DELETE RESTRICT,
 FOREIGN KEY (sales_line_id) REFERENCES mda_sales_lines(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_sales_return_lines_sale_line ON mda_sales_return_lines(sales_line_id,sales_return_id);

CREATE TABLE IF NOT EXISTS mda_sales_refunds (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 sales_return_id INTEGER NOT NULL,
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
 FOREIGN KEY (sales_return_id) REFERENCES mda_sales_returns(id) ON DELETE RESTRICT,
 FOREIGN KEY (sales_document_id) REFERENCES mda_sales_documents(id) ON DELETE RESTRICT,
 FOREIGN KEY (party_id) REFERENCES mda_parties(id) ON DELETE RESTRICT,
 FOREIGN KEY (cash_account_id) REFERENCES mda_accounts(id) ON DELETE RESTRICT,
 FOREIGN KEY (accounting_document_id) REFERENCES mda_documents(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_sales_refunds_return ON mda_sales_refunds(sales_return_id,payment_date);

CREATE TRIGGER IF NOT EXISTS trg_mda_sales_returns_no_update
BEFORE UPDATE ON mda_sales_returns
BEGIN SELECT RAISE(ABORT,'Posted sales returns are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_sales_returns_no_delete
BEFORE DELETE ON mda_sales_returns
BEGIN SELECT RAISE(ABORT,'Posted sales returns cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_sales_return_lines_no_update
BEFORE UPDATE ON mda_sales_return_lines
BEGIN SELECT RAISE(ABORT,'Posted sales return lines are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_sales_return_lines_no_delete
BEFORE DELETE ON mda_sales_return_lines
BEGIN SELECT RAISE(ABORT,'Posted sales return lines cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_sales_refunds_no_update
BEFORE UPDATE ON mda_sales_refunds
BEGIN SELECT RAISE(ABORT,'Posted sales refunds are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_sales_refunds_no_delete
BEFORE DELETE ON mda_sales_refunds
BEGIN SELECT RAISE(ABORT,'Posted sales refunds cannot be deleted.'); END;

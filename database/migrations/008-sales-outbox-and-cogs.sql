-- Sales posting, durable WooCommerce outbox metadata and immutable COGS snapshots.
ALTER TABLE mda_integration_outbox ADD COLUMN locked_at TEXT;
ALTER TABLE mda_integration_outbox ADD COLUMN last_attempt_at TEXT;
ALTER TABLE mda_integration_outbox ADD COLUMN max_attempts INTEGER NOT NULL DEFAULT 12 CHECK (max_attempts > 0);
CREATE INDEX IF NOT EXISTS idx_mda_outbox_recovery ON mda_integration_outbox(status,locked_at,available_at);

INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '2200','مالیات و عوارض فروش پرداختنی','liability',id,1,CURRENT_TIMESTAMP,'credit',0,1,0,'مالیات و عوارض وصول‌شده از فروش',85 FROM mda_accounts WHERE account_code='2000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '4200','درآمد خدمات ارسال','income',id,1,CURRENT_TIMESTAMP,'credit',0,1,1,'درآمد حمل و ارسال سفارش',112 FROM mda_accounts WHERE account_code='4000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '4300','سایر درآمدهای سفارش','income',id,1,CURRENT_TIMESTAMP,'credit',0,1,1,'کارمزدها و سایر اقلام درآمدی سفارش',114 FROM mda_accounts WHERE account_code='4000';

CREATE TABLE IF NOT EXISTS mda_sales_documents (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 source_type TEXT NOT NULL CHECK (source_type IN ('woocommerce','phone','in_person')),
 source_id TEXT NOT NULL,
 order_id BIGINT NOT NULL UNIQUE,
 party_id INTEGER NOT NULL,
 warehouse_id INTEGER NOT NULL,
 document_number TEXT NOT NULL UNIQUE,
 document_date TEXT NOT NULL,
 currency_code TEXT NOT NULL,
 product_total_minor INTEGER NOT NULL CHECK (product_total_minor >= 0),
 shipping_total_minor INTEGER NOT NULL DEFAULT 0 CHECK (shipping_total_minor >= 0),
 fee_total_minor INTEGER NOT NULL DEFAULT 0 CHECK (fee_total_minor >= 0),
 tax_total_minor INTEGER NOT NULL DEFAULT 0 CHECK (tax_total_minor >= 0),
 total_minor INTEGER NOT NULL CHECK (total_minor >= 0),
 cogs_total_minor INTEGER NOT NULL CHECK (cogs_total_minor >= 0),
 idempotency_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 accounting_document_id INTEGER NOT NULL,
 status TEXT NOT NULL DEFAULT 'posted' CHECK (status='posted'),
 created_at TEXT NOT NULL,
 FOREIGN KEY (party_id) REFERENCES mda_parties(id) ON DELETE RESTRICT,
 FOREIGN KEY (warehouse_id) REFERENCES mda_warehouses(id) ON DELETE RESTRICT,
 FOREIGN KEY (accounting_document_id) REFERENCES mda_documents(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_sales_date ON mda_sales_documents(document_date,source_type);
CREATE INDEX IF NOT EXISTS idx_mda_sales_party ON mda_sales_documents(party_id,document_date);

CREATE TABLE IF NOT EXISTS mda_sales_lines (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 sales_document_id INTEGER NOT NULL,
 order_item_id BIGINT NOT NULL,
 product_id BIGINT NOT NULL,
 variation_id BIGINT NOT NULL DEFAULT 0,
 product_name_snapshot TEXT NOT NULL,
 sku_snapshot TEXT,
 quantity_scaled INTEGER NOT NULL CHECK (quantity_scaled > 0),
 quantity_scale INTEGER NOT NULL CHECK (quantity_scale > 0),
 revenue_minor INTEGER NOT NULL CHECK (revenue_minor >= 0),
 unit_cost_minor INTEGER NOT NULL CHECK (unit_cost_minor >= 0),
 cogs_minor INTEGER NOT NULL CHECK (cogs_minor >= 0),
 currency_code TEXT NOT NULL,
 created_at TEXT NOT NULL,
 UNIQUE (sales_document_id,order_item_id),
 FOREIGN KEY (sales_document_id) REFERENCES mda_sales_documents(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_sales_lines_product ON mda_sales_lines(product_id,variation_id,sales_document_id);

CREATE TABLE IF NOT EXISTS mda_phone_sale_requests (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 request_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 order_id BIGINT,
 status TEXT NOT NULL DEFAULT 'creating' CHECK (status IN ('creating','created','failed')),
 last_error TEXT,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);

CREATE TRIGGER IF NOT EXISTS trg_mda_sales_documents_no_update
BEFORE UPDATE ON mda_sales_documents
BEGIN SELECT RAISE(ABORT,'Posted sales documents are immutable; use a return/reversal document.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_sales_documents_no_delete
BEFORE DELETE ON mda_sales_documents
BEGIN SELECT RAISE(ABORT,'Posted sales documents cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_sales_lines_no_update
BEFORE UPDATE ON mda_sales_lines
BEGIN SELECT RAISE(ABORT,'Posted sales lines are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_sales_lines_no_delete
BEFORE DELETE ON mda_sales_lines
BEGIN SELECT RAISE(ABORT,'Posted sales lines cannot be deleted.'); END;

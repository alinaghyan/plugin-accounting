-- Purchasing workflow, supplier settlement, purchase-price history and weighted-average audit.
ALTER TABLE mda_purchase_documents ADD COLUMN paid_minor INTEGER NOT NULL DEFAULT 0 CHECK (paid_minor >= 0);
ALTER TABLE mda_purchase_documents ADD COLUMN cash_account_id INTEGER;
ALTER TABLE mda_purchase_documents ADD COLUMN cost_method TEXT NOT NULL DEFAULT 'perpetual_weighted_average' CHECK (cost_method='perpetual_weighted_average');
CREATE TABLE IF NOT EXISTS mda_purchase_ancillary_costs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 purchase_id INTEGER NOT NULL,
 cost_code TEXT NOT NULL,
 description TEXT NOT NULL,
 amount_minor INTEGER NOT NULL CHECK (amount_minor >= 0),
 allocation_method TEXT NOT NULL DEFAULT 'by_value' CHECK (allocation_method='by_value'),
 created_at TEXT NOT NULL,
 FOREIGN KEY (purchase_id) REFERENCES mda_purchase_documents(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS mda_purchase_payments (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 purchase_id INTEGER NOT NULL,
 amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
 cash_account_id INTEGER NOT NULL,
 payment_method TEXT NOT NULL DEFAULT 'cash' CHECK (payment_method IN ('cash','bank','pos','transfer')),
 idempotency_key TEXT NOT NULL UNIQUE,
 paid_at TEXT NOT NULL,
 created_at TEXT NOT NULL,
 FOREIGN KEY (purchase_id) REFERENCES mda_purchase_documents(id) ON DELETE RESTRICT,
 FOREIGN KEY (cash_account_id) REFERENCES mda_accounts(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_purchase_payments_purchase ON mda_purchase_payments(purchase_id,paid_at);
CREATE TABLE IF NOT EXISTS mda_purchase_price_history (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 purchase_id INTEGER NOT NULL,
 purchase_line_id INTEGER NOT NULL,
 product_id BIGINT NOT NULL,
 variation_id BIGINT NOT NULL DEFAULT 0,
 supplier_party_id INTEGER NOT NULL,
 quantity_scaled INTEGER NOT NULL,
 quantity_scale INTEGER NOT NULL,
 unit_cost_minor INTEGER NOT NULL,
 landed_unit_cost_minor INTEGER NOT NULL,
 currency_code TEXT NOT NULL,
 recorded_at TEXT NOT NULL,
 UNIQUE (purchase_line_id),
 FOREIGN KEY (purchase_id) REFERENCES mda_purchase_documents(id) ON DELETE RESTRICT,
 FOREIGN KEY (purchase_line_id) REFERENCES mda_purchase_lines(id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_party_id) REFERENCES mda_parties(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_purchase_price_product ON mda_purchase_price_history(product_id,variation_id,recorded_at DESC);
CREATE TABLE IF NOT EXISTS mda_product_cost_history (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 purchase_id INTEGER NOT NULL,
 purchase_line_id INTEGER NOT NULL,
 product_id BIGINT NOT NULL,
 variation_id BIGINT NOT NULL DEFAULT 0,
 warehouse_id INTEGER NOT NULL,
 quantity_before_scaled INTEGER NOT NULL,
 quantity_after_scaled INTEGER NOT NULL,
 average_cost_before_minor INTEGER NOT NULL,
 average_cost_after_minor INTEGER NOT NULL,
 currency_code TEXT NOT NULL,
 recorded_at TEXT NOT NULL,
 UNIQUE (purchase_line_id),
 FOREIGN KEY (purchase_id) REFERENCES mda_purchase_documents(id) ON DELETE RESTRICT,
 FOREIGN KEY (purchase_line_id) REFERENCES mda_purchase_lines(id) ON DELETE RESTRICT,
 FOREIGN KEY (warehouse_id) REFERENCES mda_warehouses(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_cost_history_product ON mda_product_cost_history(product_id,variation_id,warehouse_id,recorded_at DESC);
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_ancillary_posted_no_insert
BEFORE INSERT ON mda_purchase_ancillary_costs
WHEN (SELECT document_status FROM mda_purchase_documents WHERE id=NEW.purchase_id) IN ('posted','voided')
BEGIN SELECT RAISE(ABORT,'Cannot add costs to a posted purchase.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_ancillary_posted_no_update
BEFORE UPDATE ON mda_purchase_ancillary_costs
WHEN (SELECT document_status FROM mda_purchase_documents WHERE id=OLD.purchase_id) IN ('posted','voided')
BEGIN SELECT RAISE(ABORT,'Cannot edit costs of a posted purchase.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_ancillary_posted_no_delete
BEFORE DELETE ON mda_purchase_ancillary_costs
WHEN (SELECT document_status FROM mda_purchase_documents WHERE id=OLD.purchase_id) IN ('posted','voided')
BEGIN SELECT RAISE(ABORT,'Cannot delete costs of a posted purchase.'); END;

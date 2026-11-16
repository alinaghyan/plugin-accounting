-- 017: general-ledger immutability, closed-period guard, on-demand (supplier-sourced) products and WooCommerce order adjustments.

-- General ledger: posted documents can only move to "voided" (with voided_at / reversal link); nothing is ever deleted.
CREATE TRIGGER IF NOT EXISTS trg_mda_documents_no_delete
BEFORE DELETE ON mda_documents
BEGIN
  SELECT RAISE(ABORT, 'mda_documents is append-only');
END;

CREATE TRIGGER IF NOT EXISTS trg_mda_documents_guard_update
BEFORE UPDATE ON mda_documents
WHEN OLD.document_status <> 'draft' AND (
  NEW.document_number IS NOT OLD.document_number OR
  NEW.document_type IS NOT OLD.document_type OR
  NEW.party_id IS NOT OLD.party_id OR
  NEW.source_type IS NOT OLD.source_type OR
  NEW.source_id IS NOT OLD.source_id OR
  NEW.idempotency_key IS NOT OLD.idempotency_key OR
  NEW.payload_hash IS NOT OLD.payload_hash OR
  NEW.currency_code IS NOT OLD.currency_code OR
  NEW.amount_scale IS NOT OLD.amount_scale OR
  NEW.document_date IS NOT OLD.document_date OR
  NEW.created_at IS NOT OLD.created_at OR
  NEW.posted_at IS NOT OLD.posted_at OR
  OLD.document_status = 'voided' OR
  NEW.document_status NOT IN (OLD.document_status, 'voided')
)
BEGIN
  SELECT RAISE(ABORT, 'posted accounting documents are immutable; post a reversal instead');
END;

CREATE TRIGGER IF NOT EXISTS trg_mda_journal_entries_no_update
BEFORE UPDATE ON mda_journal_entries
BEGIN
  SELECT RAISE(ABORT, 'mda_journal_entries is append-only');
END;

CREATE TRIGGER IF NOT EXISTS trg_mda_journal_entries_no_delete
BEFORE DELETE ON mda_journal_entries
BEGIN
  SELECT RAISE(ABORT, 'mda_journal_entries is append-only');
END;

CREATE TRIGGER IF NOT EXISTS trg_mda_journal_entries_no_insert_voided
BEFORE INSERT ON mda_journal_entries
WHEN (SELECT document_status FROM mda_documents WHERE id = NEW.document_id) = 'voided'
BEGIN
  SELECT RAISE(ABORT, 'cannot add lines to a voided document');
END;

-- Closed fiscal periods are enforced in the database for every writer, not only in PHP services.
CREATE TRIGGER IF NOT EXISTS trg_mda_documents_closed_period
BEFORE INSERT ON mda_documents
WHEN EXISTS (SELECT 1 FROM mda_financial_periods WHERE status = 'closed' AND NEW.document_date BETWEEN starts_on AND ends_on)
BEGIN
  SELECT RAISE(ABORT, 'document date falls in a closed fiscal period');
END;

CREATE INDEX IF NOT EXISTS idx_mda_documents_date ON mda_documents(document_date);
CREATE INDEX IF NOT EXISTS idx_mda_documents_source ON mda_documents(source_type, source_id);
CREATE INDEX IF NOT EXISTS idx_mda_journal_document ON mda_journal_entries(document_id);

-- Supply mode per product/variation: "stock" (default) or "on_demand" (bought from a supplier after the sale).
CREATE TABLE IF NOT EXISTS mda_product_settings (
 product_id BIGINT NOT NULL,
 variation_id BIGINT NOT NULL DEFAULT 0,
 supply_mode TEXT NOT NULL DEFAULT 'stock' CHECK (supply_mode IN ('stock','on_demand')),
 lead_time_days INTEGER CHECK (lead_time_days IS NULL OR lead_time_days >= 0),
 preferred_supplier_party_id INTEGER,
 updated_at TEXT NOT NULL,
 PRIMARY KEY (product_id, variation_id),
 FOREIGN KEY (preferred_supplier_party_id) REFERENCES mda_parties(id)
);

-- Sold quantity that was not in stock: revenue is recognised at sale, COGS when the goods are bought.
CREATE TABLE IF NOT EXISTS mda_backorder_lines (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 sales_document_id INTEGER NOT NULL,
 sales_line_id INTEGER NOT NULL UNIQUE,
 order_id BIGINT NOT NULL,
 product_id BIGINT NOT NULL,
 variation_id BIGINT NOT NULL DEFAULT 0,
 warehouse_id INTEGER NOT NULL,
 quantity_scaled INTEGER NOT NULL CHECK (quantity_scaled > 0),
 quantity_scale INTEGER NOT NULL CHECK (quantity_scale > 0),
 created_at TEXT NOT NULL,
 FOREIGN KEY (sales_document_id) REFERENCES mda_sales_documents(id),
 FOREIGN KEY (sales_line_id) REFERENCES mda_sales_lines(id),
 FOREIGN KEY (warehouse_id) REFERENCES mda_warehouses(id)
);
CREATE INDEX IF NOT EXISTS idx_mda_backorder_open ON mda_backorder_lines(product_id, variation_id, warehouse_id, id);

CREATE TABLE IF NOT EXISTS mda_backorder_fulfilments (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 backorder_line_id INTEGER NOT NULL,
 source_type TEXT NOT NULL,
 source_id TEXT NOT NULL,
 quantity_scaled INTEGER NOT NULL CHECK (quantity_scaled > 0),
 unit_cost_minor INTEGER NOT NULL CHECK (unit_cost_minor >= 0),
 cogs_minor INTEGER NOT NULL CHECK (cogs_minor >= 0),
 accounting_document_id INTEGER,
 idempotency_key TEXT NOT NULL UNIQUE,
 created_at TEXT NOT NULL,
 FOREIGN KEY (backorder_line_id) REFERENCES mda_backorder_lines(id),
 FOREIGN KEY (accounting_document_id) REFERENCES mda_documents(id)
);
CREATE INDEX IF NOT EXISTS idx_mda_backorder_fulfil_line ON mda_backorder_fulfilments(backorder_line_id);

CREATE TRIGGER IF NOT EXISTS trg_mda_backorder_lines_no_update BEFORE UPDATE ON mda_backorder_lines BEGIN SELECT RAISE(ABORT, 'mda_backorder_lines is append-only'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_backorder_lines_no_delete BEFORE DELETE ON mda_backorder_lines BEGIN SELECT RAISE(ABORT, 'mda_backorder_lines is append-only'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_backorder_fulfil_no_update BEFORE UPDATE ON mda_backorder_fulfilments BEGIN SELECT RAISE(ABORT, 'mda_backorder_fulfilments is append-only'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_backorder_fulfil_no_delete BEFORE DELETE ON mda_backorder_fulfilments BEGIN SELECT RAISE(ABORT, 'mda_backorder_fulfilments is append-only'); END;

CREATE VIEW IF NOT EXISTS mda_backorder_open AS
SELECT b.*, b.quantity_scaled - COALESCE((SELECT SUM(f.quantity_scaled) FROM mda_backorder_fulfilments f WHERE f.backorder_line_id = b.id), 0) AS open_scaled
FROM mda_backorder_lines b;

-- Cancelled / refunded WooCommerce orders that were processed into accounting adjustments.
CREATE TABLE IF NOT EXISTS mda_order_adjustments (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_id BIGINT NOT NULL,
 adjustment_type TEXT NOT NULL CHECK (adjustment_type IN ('cancel','refund')),
 woo_refund_id BIGINT NOT NULL DEFAULT 0,
 sales_return_id INTEGER,
 status TEXT NOT NULL CHECK (status IN ('applied','skipped','needs_review')),
 message TEXT,
 created_at TEXT NOT NULL,
 UNIQUE (order_id, adjustment_type, woo_refund_id)
);

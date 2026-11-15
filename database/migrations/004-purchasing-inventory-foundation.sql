-- Inventory and purchasing foundation. Existing movement history is preserved.
INSERT OR IGNORE INTO mda_warehouses (warehouse_code,warehouse_name,is_active,created_at)
VALUES ('MAIN','انبار اصلی',1,CURRENT_TIMESTAMP);

CREATE TABLE IF NOT EXISTS mda_purchase_documents (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 document_number TEXT NOT NULL UNIQUE,
 supplier_party_id INTEGER NOT NULL,
 warehouse_id INTEGER NOT NULL,
 document_status TEXT NOT NULL DEFAULT 'draft' CHECK (document_status IN ('draft','posted','voided')),
 document_date TEXT NOT NULL,
 currency_code TEXT NOT NULL DEFAULT 'IRR',
 amount_scale INTEGER NOT NULL DEFAULT 1 CHECK (amount_scale > 0),
 subtotal_minor INTEGER NOT NULL DEFAULT 0 CHECK (subtotal_minor >= 0),
 ancillary_cost_minor INTEGER NOT NULL DEFAULT 0 CHECK (ancillary_cost_minor >= 0),
 total_minor INTEGER NOT NULL DEFAULT 0 CHECK (total_minor >= 0),
 idempotency_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 accounting_document_id INTEGER,
 notes TEXT,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 posted_at TEXT,
 voided_at TEXT,
 reversal_purchase_id INTEGER,
 FOREIGN KEY (supplier_party_id) REFERENCES mda_parties(id) ON DELETE RESTRICT,
 FOREIGN KEY (warehouse_id) REFERENCES mda_warehouses(id) ON DELETE RESTRICT,
 FOREIGN KEY (accounting_document_id) REFERENCES mda_documents(id) ON DELETE RESTRICT,
 FOREIGN KEY (reversal_purchase_id) REFERENCES mda_purchase_documents(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_purchase_supplier_date ON mda_purchase_documents(supplier_party_id,document_date);
CREATE INDEX IF NOT EXISTS idx_mda_purchase_status_date ON mda_purchase_documents(document_status,document_date);

CREATE TABLE IF NOT EXISTS mda_purchase_lines (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 purchase_id INTEGER NOT NULL,
 line_number INTEGER NOT NULL,
 product_id BIGINT NOT NULL,
 variation_id BIGINT NOT NULL DEFAULT 0,
 product_name_snapshot TEXT NOT NULL,
 sku_snapshot TEXT,
 quantity_scaled INTEGER NOT NULL CHECK (quantity_scaled > 0),
 quantity_scale INTEGER NOT NULL DEFAULT 1000000 CHECK (quantity_scale > 0),
 unit_cost_minor INTEGER NOT NULL CHECK (unit_cost_minor >= 0),
 line_total_minor INTEGER NOT NULL CHECK (line_total_minor >= 0),
 allocated_cost_minor INTEGER NOT NULL DEFAULT 0 CHECK (allocated_cost_minor >= 0),
 created_at TEXT NOT NULL,
 UNIQUE (purchase_id,line_number),
 FOREIGN KEY (purchase_id) REFERENCES mda_purchase_documents(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_purchase_lines_product ON mda_purchase_lines(product_id,variation_id,purchase_id);

CREATE INDEX IF NOT EXISTS idx_mda_inventory_warehouse_time ON mda_inventory_movements(warehouse_id,occurred_at,id);
CREATE INDEX IF NOT EXISTS idx_mda_inventory_source ON mda_inventory_movements(source_type,source_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_mda_inventory_idempotency ON mda_inventory_movements(idempotency_key) WHERE idempotency_key IS NOT NULL;

CREATE TRIGGER IF NOT EXISTS trg_mda_inventory_movements_no_update
BEFORE UPDATE ON mda_inventory_movements
BEGIN SELECT RAISE(ABORT,'Inventory movements are immutable; post a reversal instead.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_inventory_movements_no_delete
BEFORE DELETE ON mda_inventory_movements
BEGIN SELECT RAISE(ABORT,'Inventory movements cannot be deleted.'); END;

CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_posted_no_update
BEFORE UPDATE ON mda_purchase_documents
WHEN OLD.document_status IN ('posted','voided')
BEGIN SELECT RAISE(ABORT,'Posted purchase documents are immutable; create a correction document.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_posted_no_delete
BEFORE DELETE ON mda_purchase_documents
WHEN OLD.document_status IN ('posted','voided')
BEGIN SELECT RAISE(ABORT,'Posted purchase documents cannot be deleted.'); END;

CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_lines_posted_no_insert
BEFORE INSERT ON mda_purchase_lines
WHEN (SELECT document_status FROM mda_purchase_documents WHERE id=NEW.purchase_id) IN ('posted','voided')
BEGIN SELECT RAISE(ABORT,'Cannot change lines of a posted purchase.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_lines_posted_no_update
BEFORE UPDATE ON mda_purchase_lines
WHEN (SELECT document_status FROM mda_purchase_documents WHERE id=OLD.purchase_id) IN ('posted','voided')
BEGIN SELECT RAISE(ABORT,'Cannot change lines of a posted purchase.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_lines_posted_no_delete
BEFORE DELETE ON mda_purchase_lines
WHEN (SELECT document_status FROM mda_purchase_documents WHERE id=OLD.purchase_id) IN ('posted','voided')
BEGIN SELECT RAISE(ABORT,'Cannot delete lines of a posted purchase.'); END;

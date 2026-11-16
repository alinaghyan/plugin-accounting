-- 020: multi-warehouse transfers, warehouse notes, internal supplier details per product.
ALTER TABLE mda_warehouses ADD COLUMN address TEXT;
ALTER TABLE mda_product_settings ADD COLUMN supplier_sku TEXT;
ALTER TABLE mda_product_settings ADD COLUMN supplier_note TEXT;

-- Stock moved between warehouses at the source warehouse's average cost (no GL entry: total value is unchanged).
CREATE TABLE IF NOT EXISTS mda_warehouse_transfers (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 product_id BIGINT NOT NULL,
 variation_id BIGINT NOT NULL DEFAULT 0,
 from_warehouse_id INTEGER NOT NULL,
 to_warehouse_id INTEGER NOT NULL,
 quantity_scaled INTEGER NOT NULL CHECK (quantity_scaled > 0),
 quantity_scale INTEGER NOT NULL DEFAULT 1000000 CHECK (quantity_scale > 0),
 unit_cost_minor INTEGER NOT NULL CHECK (unit_cost_minor >= 0),
 currency_code TEXT NOT NULL,
 transfer_date TEXT NOT NULL,
 note TEXT,
 idempotency_key TEXT NOT NULL UNIQUE,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 CHECK (from_warehouse_id <> to_warehouse_id),
 FOREIGN KEY (from_warehouse_id) REFERENCES mda_warehouses(id) ON DELETE RESTRICT,
 FOREIGN KEY (to_warehouse_id) REFERENCES mda_warehouses(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_transfers_product ON mda_warehouse_transfers(product_id, variation_id, id);
CREATE TRIGGER IF NOT EXISTS trg_mda_transfers_no_update BEFORE UPDATE ON mda_warehouse_transfers BEGIN SELECT RAISE(ABORT, 'mda_warehouse_transfers is append-only'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_transfers_no_delete BEFORE DELETE ON mda_warehouse_transfers BEGIN SELECT RAISE(ABORT, 'mda_warehouse_transfers is append-only'); END;

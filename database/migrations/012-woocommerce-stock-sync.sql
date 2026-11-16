-- Auditable SQLite-to-WooCommerce stock synchronization batches.
CREATE TABLE IF NOT EXISTS mda_stock_sync_runs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 warehouse_id INTEGER NOT NULL,
 started_at TEXT NOT NULL,
 finished_at TEXT NOT NULL,
 scanned_count INTEGER NOT NULL DEFAULT 0 CHECK (scanned_count >= 0),
 synced_count INTEGER NOT NULL DEFAULT 0 CHECK (synced_count >= 0),
 skipped_count INTEGER NOT NULL DEFAULT 0 CHECK (skipped_count >= 0),
 failed_count INTEGER NOT NULL DEFAULT 0 CHECK (failed_count >= 0),
 pending_events_count INTEGER NOT NULL DEFAULT 0 CHECK (pending_events_count >= 0),
 next_product_id BIGINT NOT NULL DEFAULT 0,
 next_variation_id BIGINT NOT NULL DEFAULT 0,
 status TEXT NOT NULL CHECK (status IN ('completed','partial','blocked','failed')),
 message TEXT,
 created_at TEXT NOT NULL,
 FOREIGN KEY (warehouse_id) REFERENCES mda_warehouses(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_stock_sync_runs_created ON mda_stock_sync_runs(created_at DESC);

CREATE TABLE IF NOT EXISTS mda_stock_sync_items (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 run_id INTEGER NOT NULL,
 warehouse_id INTEGER NOT NULL,
 product_id BIGINT NOT NULL,
 variation_id BIGINT NOT NULL DEFAULT 0,
 quantity_scaled INTEGER NOT NULL,
 quantity_scale INTEGER NOT NULL,
 sqlite_quantity TEXT NOT NULL,
 woo_quantity_before TEXT,
 woo_quantity_after TEXT,
 status TEXT NOT NULL CHECK (status IN ('synced','skipped','failed')),
 message TEXT,
 created_at TEXT NOT NULL,
 FOREIGN KEY (run_id) REFERENCES mda_stock_sync_runs(id) ON DELETE RESTRICT,
 FOREIGN KEY (warehouse_id) REFERENCES mda_warehouses(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_stock_sync_items_run ON mda_stock_sync_items(run_id,id);

CREATE TRIGGER IF NOT EXISTS trg_mda_stock_sync_runs_no_update
BEFORE UPDATE ON mda_stock_sync_runs
BEGIN SELECT RAISE(ABORT,'Stock sync run history is immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_stock_sync_runs_no_delete
BEFORE DELETE ON mda_stock_sync_runs
BEGIN SELECT RAISE(ABORT,'Stock sync run history cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_stock_sync_items_no_update
BEFORE UPDATE ON mda_stock_sync_items
BEGIN SELECT RAISE(ABORT,'Stock sync item history is immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_stock_sync_items_no_delete
BEFORE DELETE ON mda_stock_sync_items
BEGIN SELECT RAISE(ABORT,'Stock sync item history cannot be deleted.'); END;

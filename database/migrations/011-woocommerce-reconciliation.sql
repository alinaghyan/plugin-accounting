-- Audit trail for WooCommerce-to-SQLite reconciliation batches.
CREATE TABLE IF NOT EXISTS mda_reconciliation_runs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 started_at TEXT NOT NULL,
 finished_at TEXT NOT NULL,
 window_start TEXT NOT NULL,
 page_number INTEGER NOT NULL CHECK (page_number > 0),
 total_pages INTEGER NOT NULL CHECK (total_pages >= 0),
 scanned_count INTEGER NOT NULL DEFAULT 0 CHECK (scanned_count >= 0),
 already_posted_count INTEGER NOT NULL DEFAULT 0 CHECK (already_posted_count >= 0),
 queued_count INTEGER NOT NULL DEFAULT 0 CHECK (queued_count >= 0),
 pending_count INTEGER NOT NULL DEFAULT 0 CHECK (pending_count >= 0),
 failed_count INTEGER NOT NULL DEFAULT 0 CHECK (failed_count >= 0),
 skipped_count INTEGER NOT NULL DEFAULT 0 CHECK (skipped_count >= 0),
 errors_json TEXT NOT NULL DEFAULT '[]',
 status TEXT NOT NULL CHECK (status IN ('completed','partial','failed')),
 created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_mda_reconciliation_runs_created ON mda_reconciliation_runs(created_at DESC);
CREATE TRIGGER IF NOT EXISTS trg_mda_reconciliation_runs_no_update
BEFORE UPDATE ON mda_reconciliation_runs
BEGIN SELECT RAISE(ABORT,'Reconciliation run history is immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_reconciliation_runs_no_delete
BEFORE DELETE ON mda_reconciliation_runs
BEGIN SELECT RAISE(ABORT,'Reconciliation run history cannot be deleted.'); END;

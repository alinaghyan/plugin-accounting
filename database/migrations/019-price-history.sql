-- 019: append-only history of product price changes made through the price manager or product screen.
CREATE TABLE IF NOT EXISTS mda_price_history (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 product_id BIGINT NOT NULL,
 field_key TEXT NOT NULL,
 old_value TEXT,
 new_value TEXT,
 source TEXT NOT NULL CHECK (source IN ('single','grid','bulk','csv','purchase','product_edit')),
 user_id INTEGER,
 created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_mda_price_history_product ON mda_price_history(product_id, id);
CREATE TRIGGER IF NOT EXISTS trg_mda_price_history_no_update BEFORE UPDATE ON mda_price_history BEGIN SELECT RAISE(ABORT, 'mda_price_history is append-only'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_price_history_no_delete BEFORE DELETE ON mda_price_history BEGIN SELECT RAISE(ABORT, 'mda_price_history is append-only'); END;

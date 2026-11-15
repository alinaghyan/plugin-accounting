-- Protect immutable purchase evidence and normalize append-only cost history.
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_price_history_no_update
BEFORE UPDATE ON mda_purchase_price_history
BEGIN SELECT RAISE(ABORT,'Purchase price history is immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_price_history_no_delete
BEFORE DELETE ON mda_purchase_price_history
BEGIN SELECT RAISE(ABORT,'Purchase price history cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_product_cost_history_no_update
BEFORE UPDATE ON mda_product_cost_history
BEGIN SELECT RAISE(ABORT,'Product cost history is immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_product_cost_history_no_delete
BEFORE DELETE ON mda_product_cost_history
BEGIN SELECT RAISE(ABORT,'Product cost history cannot be deleted.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_payments_no_update
BEFORE UPDATE ON mda_purchase_payments
BEGIN SELECT RAISE(ABORT,'Purchase payment history is immutable; use a reversal.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_payments_no_delete
BEFORE DELETE ON mda_purchase_payments
BEGIN SELECT RAISE(ABORT,'Purchase payment history cannot be deleted.'); END;

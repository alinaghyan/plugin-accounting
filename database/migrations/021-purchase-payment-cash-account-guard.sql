-- 021: purchase payments may only use active, manually-postable cash/bank accounts.
CREATE TRIGGER IF NOT EXISTS trg_mda_purchase_payments_cash_account_insert
BEFORE INSERT ON mda_purchase_payments
WHEN NOT EXISTS (
 SELECT 1 FROM mda_accounts
 WHERE id=NEW.cash_account_id
   AND account_code GLOB '11*'
   AND account_type='asset'
   AND is_control=0
   AND allow_manual_entry=1
   AND is_active=1
   AND archived_at IS NULL
)
BEGIN
 SELECT RAISE(ABORT,'Purchase payments must use an active cash/bank account.');
END;

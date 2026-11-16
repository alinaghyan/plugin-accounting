-- General cash/bank receipts, payments, transfers and operating expense categories.
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '4400','سایر درآمدهای متفرقه','income',id,1,CURRENT_TIMESTAMP,'credit',0,1,1,'درآمدهای غیرمرتبط با فروش کالا و ارسال',118 FROM mda_accounts WHERE account_code='4000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '5210','هزینه اجاره','expense',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'هزینه اجاره محل کسب‌وکار',141 FROM mda_accounts WHERE account_code='5200';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '5220','هزینه آب، برق و گاز','expense',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'هزینه خدمات و انرژی',142 FROM mda_accounts WHERE account_code='5200';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '5230','هزینه تبلیغات و بازاریابی','expense',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'هزینه تبلیغات و بازاریابی',143 FROM mda_accounts WHERE account_code='5200';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '5240','کارمزد بانکی و درگاه','expense',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'کارمزد خدمات بانکی و پرداخت',144 FROM mda_accounts WHERE account_code='5200';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '5250','هزینه حقوق و دستمزد','expense',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'هزینه حقوق و دستمزد',145 FROM mda_accounts WHERE account_code='5200';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '5260','هزینه حمل و نقل','expense',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'هزینه حمل و نقل عملیاتی',146 FROM mda_accounts WHERE account_code='5200';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '5270','سایر هزینه‌های عملیاتی','expense',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'سایر هزینه‌های جاری',147 FROM mda_accounts WHERE account_code='5200';

CREATE TABLE IF NOT EXISTS mda_cash_transactions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 transaction_type TEXT NOT NULL CHECK (transaction_type IN ('receipt','payment','transfer')),
 cash_account_id INTEGER NOT NULL,
 contra_account_id INTEGER,
 destination_cash_account_id INTEGER,
 amount_minor INTEGER NOT NULL CHECK (amount_minor > 0),
 currency_code TEXT NOT NULL CHECK (currency_code IN ('IRR','IRT')),
 payment_method TEXT NOT NULL CHECK (payment_method IN ('cash','bank','pos','transfer','cheque')),
 transaction_date TEXT NOT NULL,
 reference_number TEXT,
 description TEXT NOT NULL,
 idempotency_key TEXT NOT NULL UNIQUE,
 payload_hash TEXT NOT NULL,
 accounting_document_id INTEGER NOT NULL,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 FOREIGN KEY (cash_account_id) REFERENCES mda_accounts(id) ON DELETE RESTRICT,
 FOREIGN KEY (contra_account_id) REFERENCES mda_accounts(id) ON DELETE RESTRICT,
 FOREIGN KEY (destination_cash_account_id) REFERENCES mda_accounts(id) ON DELETE RESTRICT,
 FOREIGN KEY (accounting_document_id) REFERENCES mda_documents(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_cash_transactions_date ON mda_cash_transactions(transaction_date,transaction_type);
CREATE INDEX IF NOT EXISTS idx_mda_cash_transactions_cash ON mda_cash_transactions(cash_account_id,transaction_date);

CREATE TRIGGER IF NOT EXISTS trg_mda_cash_transactions_no_update
BEFORE UPDATE ON mda_cash_transactions
BEGIN SELECT RAISE(ABORT,'Posted cash transactions are immutable.'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_cash_transactions_no_delete
BEFORE DELETE ON mda_cash_transactions
BEGIN SELECT RAISE(ABORT,'Posted cash transactions cannot be deleted.'); END;

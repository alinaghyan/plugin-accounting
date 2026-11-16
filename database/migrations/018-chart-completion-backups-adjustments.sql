-- 018: complete chart of accounts (equity, opening balance, VAT input, fixed assets), backups, settings.
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '1400','مالیات بر ارزش افزوده خرید','asset',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'اعتبار مالیاتی خرید (قابل کسر از مالیات فروش)',90 FROM mda_accounts WHERE account_code='1000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '1500','پیش‌پرداخت‌ها و علی‌الحساب‌ها','asset',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'پیش‌پرداخت به تأمین‌کنندگان و سایر',95 FROM mda_accounts WHERE account_code='1000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '1600','دارایی‌های ثابت','asset',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'تجهیزات، اثاثیه و وسایل نقلیه',97 FROM mda_accounts WHERE account_code='1000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '1690','استهلاک انباشته دارایی‌های ثابت','asset',id,1,CURRENT_TIMESTAMP,'credit',0,1,1,'کاهنده دارایی ثابت',98 FROM mda_accounts WHERE account_code='1000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '2300','پیش‌دریافت از مشتریان','liability',id,1,CURRENT_TIMESTAMP,'credit',0,1,1,'وجوه دریافتی پیش از تحویل کالا',230 FROM mda_accounts WHERE account_code='2000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '2400','وام‌ها و تسهیلات','liability',id,1,CURRENT_TIMESTAMP,'credit',0,1,1,'تسهیلات بانکی و قرض',240 FROM mda_accounts WHERE account_code='2000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '2500','سایر بدهی‌ها','liability',id,1,CURRENT_TIMESTAMP,'credit',0,1,1,'حقوق پرداختنی، بیمه و سایر',250 FROM mda_accounts WHERE account_code='2000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '3100','سرمایه','equity',id,1,CURRENT_TIMESTAMP,'credit',0,1,1,'سرمایه مالک',310 FROM mda_accounts WHERE account_code='3000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '3150','برداشت مالک','equity',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'برداشت شخصی مالک',315 FROM mda_accounts WHERE account_code='3000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '3200','سود و زیان انباشته','equity',id,1,CURRENT_TIMESTAMP,'credit',0,1,1,'نتیجه سال‌های قبل',320 FROM mda_accounts WHERE account_code='3000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '3900','تراز افتتاحیه','equity',id,1,CURRENT_TIMESTAMP,'credit',0,1,1,'حساب واسط ثبت مانده‌های اول دوره',390 FROM mda_accounts WHERE account_code='3000';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '5280','کسری و اضافی انبار','expense',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'تعدیلات انبارگردانی',580 FROM mda_accounts WHERE account_code='5200';
INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order)
SELECT '5290','هزینه استهلاک','expense',id,1,CURRENT_TIMESTAMP,'debit',0,1,1,'استهلاک دارایی‌های ثابت',590 FROM mda_accounts WHERE account_code='5200';

CREATE TABLE IF NOT EXISTS mda_backups (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 file_name TEXT NOT NULL UNIQUE,
 size_bytes INTEGER NOT NULL,
 sha256 TEXT NOT NULL,
 trigger_type TEXT NOT NULL CHECK (trigger_type IN ('manual','scheduled','pre_restore')),
 created_by INTEGER,
 created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mda_inventory_adjustments (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 adjustment_type TEXT NOT NULL CHECK (adjustment_type IN ('opening','count')),
 product_id BIGINT NOT NULL,
 variation_id BIGINT NOT NULL DEFAULT 0,
 warehouse_id INTEGER NOT NULL,
 quantity_before_scaled INTEGER NOT NULL,
 quantity_after_scaled INTEGER NOT NULL,
 quantity_scale INTEGER NOT NULL,
 unit_cost_minor INTEGER NOT NULL,
 value_minor INTEGER NOT NULL,
 accounting_document_id INTEGER,
 reason TEXT,
 idempotency_key TEXT NOT NULL UNIQUE,
 created_by INTEGER,
 created_at TEXT NOT NULL,
 FOREIGN KEY (warehouse_id) REFERENCES mda_warehouses(id),
 FOREIGN KEY (accounting_document_id) REFERENCES mda_documents(id)
);
CREATE TRIGGER IF NOT EXISTS trg_mda_inventory_adjustments_no_update BEFORE UPDATE ON mda_inventory_adjustments BEGIN SELECT RAISE(ABORT, 'mda_inventory_adjustments is append-only'); END;
CREATE TRIGGER IF NOT EXISTS trg_mda_inventory_adjustments_no_delete BEFORE DELETE ON mda_inventory_adjustments BEGIN SELECT RAISE(ABORT, 'mda_inventory_adjustments is append-only'); END;

CREATE UNIQUE INDEX IF NOT EXISTS idx_mda_financial_periods_range ON mda_financial_periods(starts_on, ends_on);

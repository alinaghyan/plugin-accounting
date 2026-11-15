ALTER TABLE mda_parties ADD COLUMN party_kind TEXT NOT NULL DEFAULT 'person' CHECK (party_kind IN ('person','company'));
ALTER TABLE mda_parties ADD COLUMN company_name TEXT;
ALTER TABLE mda_parties ADD COLUMN economic_code TEXT;
ALTER TABLE mda_parties ADD COLUMN address TEXT;
ALTER TABLE mda_parties ADD COLUMN postal_code TEXT;
ALTER TABLE mda_parties ADD COLUMN notes TEXT;
ALTER TABLE mda_parties ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1));
ALTER TABLE mda_parties ADD COLUMN archived_at TEXT;

CREATE TABLE IF NOT EXISTS mda_party_roles (
 party_id INTEGER NOT NULL,
 role_key TEXT NOT NULL CHECK (role_key IN ('customer','supplier','employee','partner','other')),
 created_at TEXT NOT NULL,
 PRIMARY KEY (party_id,role_key),
 FOREIGN KEY (party_id) REFERENCES mda_parties(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_mda_party_roles_role ON mda_party_roles(role_key,party_id);
INSERT OR IGNORE INTO mda_party_roles SELECT id,'customer',created_at FROM mda_parties WHERE is_customer=1;
INSERT OR IGNORE INTO mda_party_roles SELECT id,'supplier',created_at FROM mda_parties WHERE is_supplier=1;
INSERT OR IGNORE INTO mda_party_roles SELECT id,'employee',created_at FROM mda_parties WHERE is_employee=1;
INSERT OR IGNORE INTO mda_party_roles SELECT id,'partner',created_at FROM mda_parties WHERE is_partner=1;

ALTER TABLE mda_accounts ADD COLUMN normal_balance TEXT NOT NULL DEFAULT 'debit' CHECK (normal_balance IN ('debit','credit'));
ALTER TABLE mda_accounts ADD COLUMN is_control INTEGER NOT NULL DEFAULT 0 CHECK (is_control IN (0,1));
ALTER TABLE mda_accounts ADD COLUMN is_system INTEGER NOT NULL DEFAULT 0 CHECK (is_system IN (0,1));
ALTER TABLE mda_accounts ADD COLUMN allow_manual_entry INTEGER NOT NULL DEFAULT 1 CHECK (allow_manual_entry IN (0,1));
ALTER TABLE mda_accounts ADD COLUMN description TEXT;
ALTER TABLE mda_accounts ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0;
ALTER TABLE mda_accounts ADD COLUMN archived_at TEXT;
UPDATE mda_accounts SET normal_balance=CASE WHEN account_type IN ('liability','equity','income') THEN 'credit' ELSE 'debit' END;
CREATE INDEX IF NOT EXISTS idx_mda_accounts_parent ON mda_accounts(parent_id,account_code);
CREATE INDEX IF NOT EXISTS idx_mda_parties_active_name ON mda_parties(is_active,display_name);

INSERT OR IGNORE INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order) VALUES
('1000','دارایی‌ها','asset',NULL,1,CURRENT_TIMESTAMP,'debit',0,1,0,'گروه اصلی دارایی‌ها',10),
('1100','صندوق و بانک','asset',1,1,CURRENT_TIMESTAMP,'debit',0,1,0,'گروه نقد و بانک',20),
('1110','صندوق','asset',2,1,CURRENT_TIMESTAMP,'debit',0,1,1,'وجه نقد',30),
('1120','حساب‌های بانکی','asset',2,1,CURRENT_TIMESTAMP,'debit',0,1,0,'حساب‌های بانکی فروشگاه',40),
('1200','حساب‌های دریافتنی','asset',1,1,CURRENT_TIMESTAMP,'debit',1,1,0,'مطالبات از مشتریان',50),
('1300','موجودی کالا','asset',1,1,CURRENT_TIMESTAMP,'debit',0,1,0,'ارزش موجودی کالا',60),
('2000','بدهی‌ها','liability',NULL,1,CURRENT_TIMESTAMP,'credit',0,1,0,'گروه اصلی بدهی‌ها',70),
('2100','حساب‌های پرداختنی','liability',7,1,CURRENT_TIMESTAMP,'credit',1,1,0,'بدهی به تأمین‌کنندگان',80),
('3000','حقوق مالکانه','equity',NULL,1,CURRENT_TIMESTAMP,'credit',0,1,0,'گروه حقوق مالکانه',90),
('4000','درآمدها','income',NULL,1,CURRENT_TIMESTAMP,'credit',0,1,0,'گروه درآمدها',100),
('4100','فروش کالا','income',10,1,CURRENT_TIMESTAMP,'credit',0,1,1,'درآمد فروش کالا',110),
('5000','بهای تمام‌شده و هزینه‌ها','expense',NULL,1,CURRENT_TIMESTAMP,'debit',0,1,0,'گروه هزینه‌ها و بهای تمام‌شده',120),
('5100','بهای تمام‌شده کالای فروش‌رفته','expense',12,1,CURRENT_TIMESTAMP,'debit',0,1,1,'بهای تمام‌شده کالای فروش‌رفته',130),
('5200','هزینه‌های عملیاتی','expense',12,1,CURRENT_TIMESTAMP,'debit',0,1,0,'هزینه‌های عملیاتی',140);
CREATE TABLE IF NOT EXISTS mda_party_control_accounts (
 role_key TEXT PRIMARY KEY CHECK (role_key IN ('customer','supplier','employee','partner','other')),
 account_id INTEGER NOT NULL UNIQUE,
 created_at TEXT NOT NULL,
 FOREIGN KEY (account_id) REFERENCES mda_accounts(id) ON DELETE RESTRICT
);
INSERT OR IGNORE INTO mda_party_control_accounts (role_key,account_id,created_at)
SELECT 'customer',id,CURRENT_TIMESTAMP FROM mda_accounts WHERE account_code='1200' AND is_control=1;
INSERT OR IGNORE INTO mda_party_control_accounts (role_key,account_id,created_at)
SELECT 'supplier',id,CURRENT_TIMESTAMP FROM mda_accounts WHERE account_code='2100' AND is_control=1;
CREATE INDEX IF NOT EXISTS idx_mda_journal_party_account ON mda_journal_entries(party_id,account_id,created_at);
CREATE INDEX IF NOT EXISTS idx_mda_journal_document_account ON mda_journal_entries(document_id,account_id);
<?php
declare(strict_types=1);
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"SKIP: pdo_sqlite is not installed.\n");exit(2);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA foreign_keys=ON');
foreach(array('001_initial.sql','002_parties-and-chart-of-accounts.sql') as $migration){$sql=file_get_contents(dirname(__DIR__).'/database/migrations/'.$migration);if(false===$sql)throw new RuntimeException('Migration not found: '.$migration);$pdo->exec($sql);}
$tables=$pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach(array('mda_settings','mda_parties','mda_party_roles','mda_accounts','mda_documents','mda_journal_entries','mda_inventory_movements','mda_integration_outbox','mda_audit_logs') as $table)if(!in_array($table,$tables,true))throw new RuntimeException('Missing table: '.$table);
$now=gmdate('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO mda_parties (party_code,display_name,created_at,updated_at) VALUES ('PTY-000001','Test party',:now,:now)")->execute(array(':now'=>$now));
$pdo->prepare("INSERT INTO mda_party_roles (party_id,role_key,created_at) VALUES (1,'customer',:now),(1,'supplier',:now)")->execute(array(':now'=>$now));
$roles=$pdo->query("SELECT COUNT(*) FROM mda_party_roles WHERE party_id=1")->fetchColumn();if(2!==(int)$roles)throw new RuntimeException('Multi-role party test failed.');
$accounts=$pdo->query("SELECT COUNT(*) FROM mda_accounts WHERE is_system=1")->fetchColumn();if((int)$accounts<10)throw new RuntimeException('Default chart seed failed.');
$pdo->exec("INSERT INTO mda_accounts (account_code,account_name,account_type,created_at) VALUES ('9998','Smoke cash','asset','$now'),('9999','Smoke sales','income','$now')");
$pdo->exec("INSERT INTO mda_documents (document_number,document_type,document_status,idempotency_key,payload_hash,currency_code,document_date,created_at,posted_at) VALUES ('SMOKE-1','sale','posted','smoke-key-0001','hash','IRR','2026-01-01','$now','$now')");
$pdo->exec("INSERT INTO mda_journal_entries (document_id,entry_number,account_id,debit_minor,credit_minor,created_at) VALUES (1,1,(SELECT id FROM mda_accounts WHERE account_code='9998'),50000,0,'$now'),(1,2,(SELECT id FROM mda_accounts WHERE account_code='9999'),0,50000,'$now')");
$total=(int)$pdo->query('SELECT SUM(debit_minor) FROM mda_journal_entries')->fetchColumn();if(50000!==$total)throw new RuntimeException('Balanced journal smoke test failed.');
echo "OK: migrations, multi-role parties, chart seed and balanced journal passed.\n";

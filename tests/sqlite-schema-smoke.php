<?php
declare(strict_types=1);
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"SKIP: pdo_sqlite is not installed.\n");exit(2);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA foreign_keys=ON');
foreach(array('001_initial.sql','002_parties-and-chart-of-accounts.sql','003-party-subledger.sql','004-purchasing-inventory-foundation.sql','005-purchase-posting-costing.sql') as $migration){$sql=file_get_contents(dirname(__DIR__).'/database/migrations/'.$migration);if(false===$sql)throw new RuntimeException('Migration not found: '.$migration);$pdo->exec($sql);}
$tables=$pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach(array('mda_settings','mda_parties','mda_party_roles','mda_accounts','mda_documents','mda_journal_entries','mda_inventory_movements','mda_integration_outbox','mda_audit_logs','mda_party_control_accounts','mda_purchase_documents','mda_purchase_lines','mda_purchase_ancillary_costs','mda_purchase_payments','mda_purchase_price_history','mda_product_cost_history') as $table)if(!in_array($table,$tables,true))throw new RuntimeException('Missing table: '.$table);
$now=gmdate('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO mda_parties (party_code,display_name,created_at,updated_at) VALUES ('PTY-000001','Test party',:now,:now)")->execute(array(':now'=>$now));
$pdo->prepare("INSERT INTO mda_party_roles (party_id,role_key,created_at) VALUES (1,'customer',:now),(1,'supplier',:now)")->execute(array(':now'=>$now));
if(2!==(int)$pdo->query("SELECT COUNT(*) FROM mda_party_roles WHERE party_id=1")->fetchColumn())throw new RuntimeException('Multi-role party test failed.');
if((int)$pdo->query("SELECT COUNT(*) FROM mda_accounts WHERE is_system=1")->fetchColumn()<10)throw new RuntimeException('Default chart seed failed.');
if(2!==(int)$pdo->query("SELECT COUNT(*) FROM mda_party_control_accounts WHERE role_key IN ('customer','supplier')")->fetchColumn())throw new RuntimeException('Party control-account mappings failed.');
$pdo->exec("INSERT INTO mda_accounts (account_code,account_name,account_type,created_at) VALUES ('9998','Smoke cash','asset','$now'),('9999','Smoke sales','income','$now')");
$pdo->exec("INSERT INTO mda_documents (document_number,document_type,document_status,idempotency_key,payload_hash,currency_code,document_date,created_at,posted_at) VALUES ('SMOKE-1','sale','posted','smoke-key-0001','hash','IRR','2026-01-01','$now','$now')");
$pdo->exec("INSERT INTO mda_journal_entries (document_id,entry_number,account_id,debit_minor,credit_minor,created_at) VALUES (1,1,(SELECT id FROM mda_accounts WHERE account_code='9998'),50000,0,'$now'),(1,2,(SELECT id FROM mda_accounts WHERE account_code='9999'),0,50000,'$now')");
if(50000!==(int)$pdo->query('SELECT SUM(debit_minor) FROM mda_journal_entries')->fetchColumn())throw new RuntimeException('Balanced journal smoke test failed.');
$warehouse=(int)$pdo->query("SELECT id FROM mda_warehouses WHERE warehouse_code='MAIN'")->fetchColumn();if($warehouse<1)throw new RuntimeException('Default warehouse seed failed.');
$pdo->exec("INSERT INTO mda_inventory_movements (product_id,variation_id,warehouse_id,movement_type,quantity_scaled,quantity_scale,idempotency_key,occurred_at) VALUES (77,0,$warehouse,'opening',2500000,1000000,'stock-smoke-0001','$now')");
$balance=(int)$pdo->query("SELECT SUM(quantity_scaled) FROM mda_inventory_movements WHERE product_id=77 AND warehouse_id=$warehouse")->fetchColumn();if(2500000!==$balance)throw new RuntimeException('Inventory balance calculation failed.');
try{$pdo->exec("UPDATE mda_inventory_movements SET quantity_scaled=1 WHERE id=1");throw new RuntimeException('Inventory immutability trigger failed to block update.');}catch(PDOException $expected){}
$pdo->exec("INSERT INTO mda_purchase_documents (document_number,supplier_party_id,warehouse_id,document_status,document_date,idempotency_key,payload_hash,created_at) VALUES ('PUR-SMOKE-1',1,$warehouse,'draft','2026-01-01','purchase-smoke-0001','hash','$now')");
$purchase=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO mda_purchase_lines (purchase_id,line_number,product_id,product_name_snapshot,quantity_scaled,unit_cost_minor,line_total_minor,created_at) VALUES ($purchase,1,77,'Test product',1000000,120000,120000,'$now')");
$pdo->exec("UPDATE mda_purchase_documents SET document_status='posted',posted_at='$now' WHERE id=$purchase");
try{$pdo->exec("UPDATE mda_purchase_documents SET notes='tamper' WHERE id=$purchase");throw new RuntimeException('Posted purchase immutability trigger failed.');}catch(PDOException $expected){}
echo "OK: migrations, subledger, balanced journal, warehouse, immutable stock ledger and posted purchase protections passed.\n";

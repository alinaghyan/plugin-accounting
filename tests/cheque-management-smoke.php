<?php
declare(strict_types=1);
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"SKIP: pdo_sqlite unavailable\n");exit(2);}
define('ABSPATH',__DIR__.'/');function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}function sanitize_text_field($v){return trim(strip_tags((string)$v));}function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}function absint($v){return abs((int)$v);}function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_UNICODE);}function get_current_user_id(){return 1;}
require_once dirname(__DIR__).'/includes/class-mda-database.php';
require_once dirname(__DIR__).'/includes/class-mda-cheque-service.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');
$reflection=new ReflectionClass('MDA_Database');$prop=$reflection->getProperty('connection');$prop->setAccessible(true);$prop->setValue(null,$pdo);
foreach(glob(dirname(__DIR__).'/database/migrations/*.sql') as $file)$pdo->exec(file_get_contents($file));
$now=gmdate('Y-m-d H:i:s');$pdo->exec("INSERT INTO mda_parties (party_code,display_name,is_customer,is_supplier,created_at,updated_at) VALUES ('C-1','Customer',1,0,'$now','$now'),('S-1','Supplier',0,1,'$now','$now')");
$pdo->exec("INSERT INTO mda_party_roles (party_id,role_key,created_at) VALUES (1,'customer','$now'),(2,'supplier','$now')");
$pdo->exec("INSERT INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry) SELECT '1121','Bank test','asset',id,1,'$now','debit',0,0,1 FROM mda_accounts WHERE account_code='1120'");
$bank=(int)$pdo->query("SELECT id FROM mda_accounts WHERE account_code='1121'")->fetchColumn();
$pdo->exec("INSERT INTO mda_documents (document_number,document_type,document_status,idempotency_key,payload_hash,currency_code,document_date,created_at,posted_at) VALUES ('OPEN-BANK','opening','posted','open-bank-0001','h','IRR','2026-01-01','$now','$now')");
$doc=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO mda_journal_entries (document_id,entry_number,account_id,debit_minor,credit_minor,created_at) SELECT :doc,1,id,1000000,0,:now FROM mda_accounts WHERE account_code='1121'")->execute(array(':doc'=>$doc,':now'=>$now));$pdo->prepare("INSERT INTO mda_journal_entries (document_id,entry_number,account_id,debit_minor,credit_minor,created_at) SELECT :doc,2,id,0,1000000,:now FROM mda_accounts WHERE account_code='3000'")->execute(array(':doc'=>$doc,':now'=>$now));
$received=MDA_Cheque_Service::register(array('cheque_type'=>'received','party_id'=>1,'bank_account_id'=>$bank,'amount_minor'=>100000,'currency_code'=>'IRR','cheque_number'=>'R-100','issue_date'=>'2026-01-01','due_date'=>'2026-01-10','request_key'=>'received-cheque-0001'));
MDA_Cheque_Service::transition($received['cheque_id'],'deposit','2026-01-02','received-deposit-0001');
MDA_Cheque_Service::transition($received['cheque_id'],'clear','2026-01-03','received-clear-0001');
MDA_Cheque_Service::transition($received['cheque_id'],'bounce','2026-01-04','received-bounce-0001');$retry=MDA_Cheque_Service::transition($received['cheque_id'],'clear','2026-01-03','received-clear-0001');if(empty($retry['duplicate']))throw new RuntimeException('Transition idempotency failed.');
$issued=MDA_Cheque_Service::register(array('cheque_type'=>'issued','party_id'=>2,'bank_account_id'=>$bank,'amount_minor'=>50000,'currency_code'=>'IRR','cheque_number'=>'I-200','issue_date'=>'2026-01-01','due_date'=>'2026-01-10','request_key'=>'issued-cheque-0001'));
MDA_Cheque_Service::transition($issued['cheque_id'],'clear','2026-01-03','issued-clear-0001');
MDA_Cheque_Service::transition($issued['cheque_id'],'bounce','2026-01-04','issued-bounce-0001');
$preBounce=MDA_Cheque_Service::register(array('cheque_type'=>'received','party_id'=>1,'bank_account_id'=>$bank,'amount_minor'=>15000,'currency_code'=>'IRR','cheque_number'=>'R-102','issue_date'=>'2026-01-01','due_date'=>'2026-01-10','request_key'=>'received-cheque-0003'));MDA_Cheque_Service::transition($preBounce['cheque_id'],'bounce','2026-01-02','received-bounce-before-clear-01');
$issuedVoid=MDA_Cheque_Service::register(array('cheque_type'=>'issued','party_id'=>2,'bank_account_id'=>$bank,'amount_minor'=>15000,'currency_code'=>'IRR','cheque_number'=>'I-201','issue_date'=>'2026-01-01','due_date'=>'2026-01-10','request_key'=>'issued-cheque-0002'));MDA_Cheque_Service::transition($issuedVoid['cheque_id'],'void','2026-01-02','issued-void-0001');
$void=MDA_Cheque_Service::register(array('cheque_type'=>'received','party_id'=>1,'bank_account_id'=>$bank,'amount_minor'=>25000,'currency_code'=>'IRR','cheque_number'=>'R-101','issue_date'=>'2026-01-01','due_date'=>'2026-01-10','request_key'=>'received-cheque-0002'));
MDA_Cheque_Service::transition($void['cheque_id'],'void','2026-01-02','received-void-0001');
$bad=false;try{$pdo->exec("UPDATE mda_cheques SET description='tampered' WHERE id=1");}catch(PDOException $e){$bad=true;}if(!$bad)throw new RuntimeException('Cheque master immutability failed.');
$bad=false;try{$pdo->exec("DELETE FROM mda_cheque_events WHERE cheque_id=1");}catch(PDOException $e){$bad=true;}if(!$bad)throw new RuntimeException('Cheque event append-only failed.');
$unbalanced=(int)$pdo->query("SELECT COALESCE(SUM(j.debit_minor-j.credit_minor),0) FROM mda_journal_entries j JOIN mda_documents d ON d.id=j.document_id WHERE d.document_status='posted'")->fetchColumn();if($unbalanced!==0)throw new RuntimeException('Global journal is not balanced: '.$unbalanced);
$events=(int)$pdo->query('SELECT COUNT(*) FROM mda_cheque_events')->fetchColumn();if($events!==13)throw new RuntimeException('Expected 13 lifecycle events, got '.$events);
echo "OK: received/issued lifecycle, deposit, clear, bounce-after-clear, void, balanced journals and append-only history passed.\n";

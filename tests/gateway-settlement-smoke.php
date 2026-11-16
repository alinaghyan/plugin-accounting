<?php
declare(strict_types=1);
if(!function_exists('apply_filters')){function apply_filters($h,$v,...$a){return $v;}}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"SKIP: pdo_sqlite unavailable\n");exit(2);}
define('ABSPATH',__DIR__.'/');
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_UNICODE);}
function get_current_user_id(){return 1;}
function wc_format_decimal($v,$d=0){return number_format((float)$v,$d,'.','');}
function wp_next_scheduled($hook){return false;}
function wp_schedule_single_event($time,$hook){}
class MDA_Test_Gateway_Order {public function get_id(){return 502;}public function get_currency(){return 'IRR';}public function is_paid(){return true;}public function get_total(){return '50000';}}
require_once dirname(__DIR__).'/includes/class-mda-database.php';
require_once dirname(__DIR__).'/includes/class-mda-gateway-settlement-service.php';
require_once dirname(__DIR__).'/includes/class-mda-outbox-service.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');
$ref=new ReflectionClass('MDA_Database');$prop=$ref->getProperty('connection');$prop->setAccessible(true);$prop->setValue(null,$pdo);
foreach(glob(dirname(__DIR__).'/database/migrations/*.sql') as $file)$pdo->exec(file_get_contents($file));
$now=gmdate('Y-m-d H:i:s');
$pdo->exec("INSERT INTO mda_parties (party_code,display_name,is_customer,created_at,updated_at) VALUES ('C-1','Customer',1,'$now','$now')");
$pdo->exec("INSERT INTO mda_warehouses (warehouse_code,warehouse_name,is_active,created_at) VALUES ('WH-1','Main',1,'$now')");
$pdo->exec("INSERT INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry) SELECT '1121','Bank test','asset',id,1,'$now','debit',0,0,1 FROM mda_accounts WHERE account_code='1120'");
$bank=(int)$pdo->query("SELECT id FROM mda_accounts WHERE account_code='1121'")->fetchColumn();
$party=(int)$pdo->query("SELECT id FROM mda_parties WHERE party_code='C-1'")->fetchColumn();$warehouse=(int)$pdo->query("SELECT id FROM mda_warehouses WHERE warehouse_code='WH-1'")->fetchColumn();
$pdo->exec("INSERT INTO mda_documents (document_number,document_type,document_status,idempotency_key,payload_hash,currency_code,document_date,created_at,posted_at) VALUES ('SALE-1','sale','posted','sale-seed-key-001','h','IRR','2026-11-15','$now','$now')");
$doc=(int)$pdo->lastInsertId();$ar=(int)$pdo->query("SELECT id FROM mda_accounts WHERE account_code='1200'")->fetchColumn();$income=(int)$pdo->query("SELECT id FROM mda_accounts WHERE account_code='4100'")->fetchColumn();
$st=$pdo->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,party_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,1,:ar,:party,100000,0,\'AR\',:now),(:doc,2,:income,NULL,0,100000,\'Sale\',:now)');$st->execute(array(':doc'=>$doc,':ar'=>$ar,':party'=>$party,':income'=>$income,':now'=>$now));
$st=$pdo->prepare("INSERT INTO mda_sales_documents (source_type,source_id,order_id,party_id,warehouse_id,document_number,document_date,currency_code,product_total_minor,shipping_total_minor,fee_total_minor,tax_total_minor,total_minor,cogs_total_minor,tax_snapshot_complete,idempotency_key,payload_hash,accounting_document_id,created_at) VALUES ('woocommerce','501',501,:party,:warehouse,'MDA-SAL-501','2026-11-15','IRR',100000,0,0,0,100000,0,1,'sale-key-501','sale-hash-501',:doc,:now)");
$st->execute(array(':party'=>$party,':warehouse'=>$warehouse,':doc'=>$doc,':now'=>$now));
MDA_Gateway_Settlement_Service::configure('sample_gateway','درگاه آزمایشی');
MDA_Outbox_Service::enqueue_gateway_payment(new MDA_Test_Gateway_Order(),'sample_gateway','TX-502');
$queued=$pdo->query("SELECT event_type,payload_json FROM mda_integration_outbox WHERE event_key='wc-gateway-capture-502-sample_gateway'")->fetch();
if(!$queued||$queued['event_type']!=='gateway.capture'||json_decode($queued['payload_json'],true)['gross_minor']!==50000)throw new RuntimeException('WooCommerce gateway capture was not durably queued.');
$capture=MDA_Gateway_Settlement_Service::capture(501,'sample_gateway',100000,'TX-501','capture-key-501');
if(empty($capture['payment_id'])||!empty($capture['duplicate']))throw new RuntimeException('Gateway capture failed.');
$csv1="order_id,gross_minor,fee_minor,net_minor,reference\n501,40000,800,39200,TX-501";
$csv2="order_id,gross_minor,fee_minor,net_minor,reference\n501,60000,1200,58800,TX-501";
$import=MDA_Gateway_Settlement_Service::import_csv('sample_gateway','SET-2026-001',$bank,'IRR','2026-11-16',$csv1,'import-key-001');
$dup=MDA_Gateway_Settlement_Service::import_csv('sample_gateway','SET-2026-001',$bank,'IRR','2026-11-16',$csv1,'import-key-001');
if(empty($dup['duplicate']))throw new RuntimeException('Import idempotency failed.');
$import2=MDA_Gateway_Settlement_Service::import_csv('sample_gateway','SET-2026-002',$bank,'IRR','2026-11-16',$csv2,'import-key-002');
$over=false;try{MDA_Gateway_Settlement_Service::import_csv('sample_gateway','SET-2026-003',$bank,'IRR','2026-11-16',"order_id,gross_minor,fee_minor,net_minor\n501,1,0,1",'import-key-003');}catch(RuntimeException $e){$over=true;}if(!$over)throw new RuntimeException('Over-allocation of gateway payment was accepted.');
$posted=MDA_Gateway_Settlement_Service::post($import['settlement_id'],'post-key-001');
$again=MDA_Gateway_Settlement_Service::post($import['settlement_id'],'post-key-002');
if(empty($again['duplicate']))throw new RuntimeException('Posting idempotency failed.');
$posted2=MDA_Gateway_Settlement_Service::post($import2['settlement_id'],'post-key-003');
$sum=(int)$pdo->query("SELECT COALESCE(SUM(j.debit_minor-j.credit_minor),0) FROM mda_journal_entries j JOIN mda_documents d ON d.id=j.document_id WHERE d.document_status='posted'")->fetchColumn();
if($sum!==0)throw new RuntimeException('Journal is unbalanced: '.$sum);
$fee=(int)$pdo->query("SELECT SUM(j.debit_minor) FROM mda_journal_entries j JOIN mda_documents d ON d.id=j.document_id WHERE d.document_type='gateway_settlement' AND j.account_id=(SELECT id FROM mda_accounts WHERE account_code='5240')")->fetchColumn();
$net=(int)$pdo->query("SELECT SUM(j.debit_minor) FROM mda_journal_entries j JOIN mda_documents d ON d.id=j.document_id WHERE d.document_type='gateway_settlement' AND j.account_id=$bank")->fetchColumn();
if($fee!==2000||$net!==98000)throw new RuntimeException('Fee or net settlement posting is incorrect.');
$bad=false;try{$pdo->exec("UPDATE mda_gateway_payments SET gross_minor=1 WHERE id=1");}catch(PDOException $e){$bad=true;}if(!$bad)throw new RuntimeException('Gateway payment immutability failed.');
echo "OK: gateway capture, CSV settlement import, fee/net posting, idempotency and balanced journals passed.\n";

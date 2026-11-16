<?php
declare(strict_types=1);
if(!function_exists('apply_filters')){function apply_filters($h,$v,...$a){return $v;}}
define('ABSPATH',__DIR__.'/');
function sanitize_key($v){return preg_replace('/[^a-z0-9_\\-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function current_time($type){return '2026-11-16';}
function get_current_user_id(){return 1;}
final class MDA_Database {
 private static ?PDO $pdo=null;
 public static function connection():PDO {if(!self::$pdo){self::$pdo=new PDO('sqlite::memory:');self::$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);self::$pdo->exec('PRAGMA foreign_keys=ON');}return self::$pdo;}
 public static function transaction(callable $callback){$db=self::connection();$db->beginTransaction();try{$result=$callback($db);$db->commit();return$result;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw$e;}}
}
$migrations=array_map('basename',glob(dirname(__DIR__).'/database/migrations/*.sql'));
foreach($migrations as $file){$sql=file_get_contents(dirname(__DIR__).'/database/migrations/'.$file);if(false===$sql)throw new RuntimeException('Missing migration '.$file);MDA_Database::connection()->exec($sql);}
require_once dirname(__DIR__).'/includes/class-mda-inventory-service.php';
require_once dirname(__DIR__).'/includes/class-mda-ledger-service.php';require_once dirname(__DIR__).'/includes/class-mda-supply-service.php';require_once dirname(__DIR__).'/includes/class-mda-purchase-service.php';
require_once dirname(__DIR__).'/includes/class-mda-purchase-settlement-service.php';
require_once dirname(__DIR__).'/includes/class-mda-purchase-return-service.php';
$db=MDA_Database::connection();$now='2026-11-16 10:00:00';
$db->prepare("INSERT INTO mda_parties (party_code,display_name,is_supplier,created_at,updated_at) VALUES ('SUP-1','Supplier One',1,:n,:n)")->execute(array(':n'=>$now));
$db->exec("INSERT INTO mda_party_roles (party_id,role_key,created_at) VALUES (1,'supplier','$now')");
$warehouse=(int)$db->query("SELECT id FROM mda_warehouses WHERE warehouse_code='MAIN'")->fetchColumn();
$cash=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='1110'")->fetchColumn();
$ap=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='2100'")->fetchColumn();

$purchase=MDA_Purchase_Service::create_draft(array('supplier_party_id'=>1,'warehouse_id'=>$warehouse,'document_date'=>'2026-11-16','currency_code'=>'IRR','idempotency_key'=>'test-return-purchase-001','lines'=>array(array('product_id'=>777,'variation_id'=>0,'product_name_snapshot'=>'Return test item','quantity_scaled'=>2000000,'quantity_scale'=>1000000,'unit_cost_minor'=>100))));
$posted=MDA_Purchase_Service::post($purchase,100,$cash,'cash','test-initial-payment-001');
if($posted['total_minor']!==200||$posted['payable_minor']!==100)throw new RuntimeException('Initial purchase payable incorrect.');
$pay=MDA_Purchase_Settlement_Service::record_payment($purchase,50,$cash,'bank','test-stage-payment-001','2026-11-16');
if($pay['paid_minor']!==150||$pay['payable_minor']!==50)throw new RuntimeException('Staged payment balance incorrect.');
$dup=MDA_Purchase_Settlement_Service::record_payment($purchase,50,$cash,'bank','test-stage-payment-001','2026-11-16');
if(empty($dup['duplicate'])||count(MDA_Purchase_Settlement_Service::payments($purchase))!==2)throw new RuntimeException('Payment idempotency failed.');
try{MDA_Purchase_Settlement_Service::record_payment($purchase,51,$cash,'cash','test-overpay-001','2026-11-16');throw new RuntimeException('Overpayment was accepted.');}catch(InvalidArgumentException $expected){}
if(MDA_Purchase_Settlement_Service::summary($purchase)['payable_minor']!==50)throw new RuntimeException('Overpayment rollback failed.');
$line=(int)$db->query("SELECT id FROM mda_purchase_lines WHERE purchase_id=$purchase")->fetchColumn();
$return1=MDA_Purchase_Return_Service::post($purchase,array(array('purchase_line_id'=>$line,'quantity_scaled'=>500000)),'test-partial-return-001','2026-11-16');
if($return1['total_minor']!==50||MDA_Inventory_Service::balance(777,$warehouse)!==1500000)throw new RuntimeException('Partial return quantity or valuation incorrect.');
$dupReturn=MDA_Purchase_Return_Service::post($purchase,array(array('purchase_line_id'=>$line,'quantity_scaled'=>500000)),'test-partial-return-001','2026-11-16');
if(empty($dupReturn['duplicate']))throw new RuntimeException('Return idempotency failed.');
$return2=MDA_Purchase_Return_Service::post($purchase,array(array('purchase_line_id'=>$line,'quantity_scaled'=>1500000)),'test-full-return-001','2026-11-16');
if($return2['total_minor']!==150||MDA_Inventory_Service::balance(777,$warehouse)!==0)throw new RuntimeException('Full return or inventory reversal failed.');
try{MDA_Purchase_Return_Service::post($purchase,array(array('purchase_line_id'=>$line,'quantity_scaled'=>1)),'test-overreturn-001','2026-11-16');throw new RuntimeException('Over-return was accepted.');}catch(RuntimeException $expected){}
$balanced=$db->query("SELECT d.id,SUM(j.debit_minor) debit,SUM(j.credit_minor) credit FROM mda_documents d JOIN mda_journal_entries j ON j.document_id=d.id GROUP BY d.id")->fetchAll();
foreach($balanced as $j)if((int)$j['debit']!==(int)$j['credit'])throw new RuntimeException('Unbalanced journal document '.$j['id']);
if((int)$db->query("SELECT COUNT(*) FROM mda_purchase_returns")->fetchColumn()!==2)throw new RuntimeException('Return documents missing.');
if((int)$db->query("SELECT COUNT(*) FROM mda_purchase_payments")->fetchColumn()!==2)throw new RuntimeException('Payment records missing.');
try{$db->exec("UPDATE mda_purchase_returns SET total_minor=1");throw new RuntimeException('Return immutability failed.');}catch(PDOException $expected){}
try{$db->exec("DELETE FROM mda_purchase_return_lines");throw new RuntimeException('Return line immutability failed.');}catch(PDOException $expected){}
echo "OK: staged settlements, supplier balance, overpayment rollback, partial/full returns, stock reversal, balanced journals, idempotency and append-only return history passed.\\n";

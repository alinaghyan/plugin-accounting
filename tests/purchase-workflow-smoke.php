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
foreach(array_map('basename',glob(dirname(__DIR__).'/database/migrations/*.sql')) as $file){$sql=file_get_contents(dirname(__DIR__).'/database/migrations/'.$file);if(false===$sql)throw new RuntimeException('Missing migration '.$file);MDA_Database::connection()->exec($sql);}
require_once dirname(__DIR__).'/includes/class-mda-ledger-service.php';require_once dirname(__DIR__).'/includes/class-mda-supply-service.php';require_once dirname(__DIR__).'/includes/class-mda-purchase-service.php';
$db=MDA_Database::connection();$now='2026-11-16 10:00:00';
$db->prepare("INSERT INTO mda_parties (party_code,display_name,is_supplier,created_at,updated_at) VALUES ('SUP-1','Supplier One',1,:n,:n)")->execute(array(':n'=>$now));
$db->exec("INSERT INTO mda_party_roles (party_id,role_key,created_at) VALUES (1,'supplier','$now')");
$warehouse=(int)$db->query("SELECT id FROM mda_warehouses WHERE warehouse_code='MAIN'")->fetchColumn();
$draft=MDA_Purchase_Service::create_draft(array(
 'supplier_party_id'=>1,'warehouse_id'=>$warehouse,'document_date'=>'2026-11-16','currency_code'=>'IRR','idempotency_key'=>'test-purchase-draft-001',
 'lines'=>array(array('product_id'=>77,'variation_id'=>0,'product_name_snapshot'=>'Test product','sku_snapshot'=>'SKU-77','quantity_scaled'=>2000000,'quantity_scale'=>1000000,'unit_cost_minor'=>100)),
 'ancillary_costs'=>array(array('cost_code'=>'shipping','description'=>'Inbound shipping','amount_minor'=>20))
));
$posted=MDA_Purchase_Service::post($draft,100,(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='1110'")->fetchColumn(),'cash','test-purchase-payment-001');
if($posted['total_minor']!==220||$posted['paid_minor']!==100||$posted['payable_minor']!==120)throw new RuntimeException('Purchase totals or supplier payable are incorrect.');
$stock=(int)$db->query("SELECT SUM(quantity_scaled) FROM mda_inventory_movements WHERE product_id=77")->fetchColumn();
if($stock!==2000000)throw new RuntimeException('Purchase receipt did not increase stock.');
$cost=$db->query("SELECT average_cost_minor,quantity_scaled FROM mda_product_cost_state WHERE product_id=77")->fetch();
if((int)$cost['average_cost_minor']!==110||(int)$cost['quantity_scaled']!==2000000)throw new RuntimeException('Weighted average cost calculation is incorrect.');
if((int)$db->query("SELECT COUNT(*) FROM mda_purchase_price_history WHERE product_id=77")->fetchColumn()!==1)throw new RuntimeException('Purchase price history was not recorded.');
if((int)$db->query("SELECT COUNT(*) FROM mda_purchase_payments WHERE purchase_id=".(int)$draft)->fetchColumn()!==1)throw new RuntimeException('Cash payment was not recorded.');
$journal=$db->query("SELECT SUM(debit_minor) d,SUM(credit_minor) c FROM mda_journal_entries")->fetch();
if((int)$journal['d']!==220||(int)$journal['c']!==220)throw new RuntimeException('Purchase journal is not balanced.');
$again=MDA_Purchase_Service::post($draft,100,(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='1110'")->fetchColumn(),'cash','test-purchase-payment-001');
if(empty($again['duplicate'])||(int)$db->query("SELECT COUNT(*) FROM mda_inventory_movements WHERE source_id='".(int)$draft."'")->fetchColumn()!==1)throw new RuntimeException('Purchase posting is not idempotent.');
try{$db->exec("UPDATE mda_purchase_price_history SET unit_cost_minor=1 WHERE product_id=77");throw new RuntimeException('Purchase price history immutability failed.');}catch(PDOException $expected){}
try{$db->exec("DELETE FROM mda_purchase_payments WHERE purchase_id=".(int)$draft);throw new RuntimeException('Payment history immutability failed.');}catch(PDOException $expected){}
echo "OK: atomic purchase posting, stock receipt, supplier payable, cash payment, price history, weighted-average cost, idempotency and immutable history passed.\\n";

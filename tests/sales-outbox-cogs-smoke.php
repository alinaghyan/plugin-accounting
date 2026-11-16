<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_email($v){return filter_var((string)$v,FILTER_VALIDATE_EMAIL)?(string)$v:'';}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function get_current_user_id(){return 1;}
function wp_next_scheduled($hook){return false;}
function wp_schedule_single_event($time,$hook){return true;}
final class MDA_Database {
 private static ?PDO $pdo=null;
 public static function connection():PDO {if(!self::$pdo){self::$pdo=new PDO('sqlite::memory:');self::$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);self::$pdo->exec('PRAGMA foreign_keys=ON');}return self::$pdo;}
 public static function transaction(callable $callback){$db=self::connection();$db->beginTransaction();try{$result=$callback($db);$db->commit();return$result;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw$e;}}
}
$files=array('001_initial.sql','002_parties-and-chart-of-accounts.sql','003-party-subledger.sql','004-purchasing-inventory-foundation.sql','005-purchase-posting-costing.sql','006-immutable-purchase-history.sql','007-purchase-settlements-and-returns.sql','008-sales-outbox-and-cogs.sql');
foreach($files as $file){$sql=file_get_contents(dirname(__DIR__).'/database/migrations/'.$file);if(false===$sql)throw new RuntimeException('Missing migration '.$file);MDA_Database::connection()->exec($sql);}
require_once dirname(__DIR__).'/includes/class-mda-sales-service.php';
require_once dirname(__DIR__).'/includes/class-mda-outbox-service.php';
$db=MDA_Database::connection();$now='2026-11-16 10:00:00';
$warehouse=(int)$db->query("SELECT id FROM mda_warehouses WHERE warehouse_code='MAIN'")->fetchColumn();
$db->prepare("INSERT INTO mda_inventory_movements (product_id,variation_id,warehouse_id,movement_type,quantity_scaled,quantity_scale,unit_cost_minor,currency_code,source_type,source_id,idempotency_key,occurred_at) VALUES (501,0,:w,'opening',10000000,1000000,1000,'IRR','opening','test','opening-501-test-001',:n)")->execute(array(':w'=>$warehouse,':n'=>$now));
$db->prepare("INSERT INTO mda_product_cost_state (product_id,variation_id,warehouse_id,quantity_scaled,quantity_scale,average_cost_minor,currency_code,updated_at) VALUES (501,0,:w,10000000,1000000,1000,'IRR',:n)")->execute(array(':w'=>$warehouse,':n'=>$now));
$payload=array('order_id'=>9001,'currency'=>'IRR','date'=>'2026-11-16','warehouse_id'=>$warehouse,'channel'=>'phone','customer'=>array('name'=>'Test Customer','phone'=>'09120000000','email'=>'test@example.com'),'items'=>array(array('order_item_id'=>77,'product_id'=>501,'variation_id'=>0,'name'=>'Test product','sku'=>'T-501','quantity_scaled'=>2000000,'quantity_scale'=>1000000,'revenue_minor'=>5000)),'shipping_minor'=>1000,'fees_minor'=>0,'tax_minor'=>600,'total_minor'=>6600,'idempotency_key'=>'wc-sale-9001-v1');
$db->prepare("INSERT INTO mda_integration_outbox (event_key,event_type,aggregate_type,aggregate_id,payload_json,status,attempts,available_at,created_at) VALUES (:key,'sale.post','woocommerce_order','9001',:payload,'pending',0,:now,:now)")->execute(array(':key'=>$payload['idempotency_key'],':payload'=>wp_json_encode($payload),':now'=>gmdate('Y-m-d H:i:s')));
$processed=MDA_Outbox_Service::process_batch(10);if(empty($processed[0])||$processed[0]['status']!=='completed')throw new RuntimeException('Outbox worker did not complete the sale event: '.wp_json_encode($processed));
$eventStatus=$db->query("SELECT status FROM mda_integration_outbox WHERE event_key='wc-sale-9001-v1'")->fetchColumn();if($eventStatus!=='completed')throw new RuntimeException('Outbox event was not marked completed.');
$again=MDA_Sales_Service::post_order_payload($payload);if(empty($again['duplicate']))throw new RuntimeException('Sale idempotency failed.');
if((int)$db->query("SELECT COUNT(*) FROM mda_sales_documents WHERE order_id=9001")->fetchColumn()!==1)throw new RuntimeException('Duplicate sale document was created.');
if((int)$db->query("SELECT COUNT(*) FROM mda_inventory_movements WHERE source_type='sale' AND source_id='9001'")->fetchColumn()!==1)throw new RuntimeException('Duplicate stock issue was created.');
if((int)$db->query("SELECT quantity_scaled FROM mda_product_cost_state WHERE product_id=501")->fetchColumn()!==8000000)throw new RuntimeException('Stock cost state was not reduced.');
$stock=(int)$db->query("SELECT SUM(quantity_scaled) FROM mda_inventory_movements WHERE product_id=501")->fetchColumn();if($stock!==8000000)throw new RuntimeException('Stock ledger and cost state disagree.');
$totals=$db->query("SELECT SUM(debit_minor) debit,SUM(credit_minor) credit FROM mda_journal_entries")->fetch();if((int)$totals['debit']!==(int)$totals['credit'])throw new RuntimeException('Sales journal is not balanced.');
$lines=$db->query("SELECT cogs_minor,unit_cost_minor,quantity_scaled FROM mda_sales_lines")->fetch();if((int)$lines['cogs_minor']!==2000||(int)$lines['unit_cost_minor']!==1000||(int)$lines['quantity_scaled']!==2000000)throw new RuntimeException('Immutable COGS snapshot is incorrect.');
$changed=$payload;$changed['total_minor']=6601;$rejected=false;try{MDA_Sales_Service::post_order_payload($changed);}catch(RuntimeException $e){$rejected=true;}if(!$rejected)throw new RuntimeException('Changed payload reused the same idempotency key.');
echo "OK: Outbox processing, sales posting, balanced journal, weighted-average COGS, stock issue, immutable snapshots and idempotency passed.\\n";

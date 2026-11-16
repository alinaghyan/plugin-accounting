<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_email($v){return filter_var((string)$v,FILTER_VALIDATE_EMAIL)?(string)$v:'';}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function get_current_user_id(){return 1;}
function wp_next_scheduled($hook){return false;}
function wp_schedule_single_event($time,$hook,$args=array()){return true;}
final class MDA_Database {
 private static ?PDO $pdo=null;
 public static function is_available():bool {return true;}
 public static function connection():PDO {if(!self::$pdo){self::$pdo=new PDO('sqlite::memory:');self::$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);self::$pdo->exec('PRAGMA foreign_keys=ON');}return self::$pdo;}
 public static function transaction(callable $callback){$db=self::connection();$db->beginTransaction();try{$result=$callback($db);$db->commit();return$result;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw$e;}}
}
$files=array('001_initial.sql','002_parties-and-chart-of-accounts.sql','003-party-subledger.sql','004-purchasing-inventory-foundation.sql','005-purchase-posting-costing.sql','006-immutable-purchase-history.sql','007-purchase-settlements-and-returns.sql','008-sales-outbox-and-cogs.sql','009-customer-receipts.sql','010-sales-returns-and-refunds.sql','011-woocommerce-reconciliation.sql','012-woocommerce-stock-sync.sql','013-cash-bank-and-expenses.sql');
foreach($files as $file){$sql=file_get_contents(dirname(__DIR__).'/database/migrations/'.$file);if(false===$sql)throw new RuntimeException('Missing migration '.$file);MDA_Database::connection()->exec($sql);}
require_once dirname(__DIR__).'/includes/class-mda-sales-service.php';
require_once dirname(__DIR__).'/includes/class-mda-outbox-service.php';
require_once dirname(__DIR__).'/includes/class-mda-sales-receipt-service.php';
require_once dirname(__DIR__).'/includes/class-mda-sales-return-service.php';
require_once dirname(__DIR__).'/includes/class-mda-reconciliation-service.php';
require_once dirname(__DIR__).'/includes/class-mda-stock-sync-service.php';
require_once dirname(__DIR__).'/includes/class-mda-report-service.php';
require_once dirname(__DIR__).'/includes/class-mda-cash-service.php';
class WooCommerce {}
final class SmokeWCDate { public function date($format){return gmdate($format,strtotime('2026-11-16 10:00:00'));} }
final class SmokeWCProduct { public static string $stock='8'; public static string $stockStatus='instock'; public function get_sku(){return 'T-502';} public function managing_stock(){return true;} public function get_manage_stock(){return true;} public function get_stock_quantity(){return self::$stock;} public function set_stock_quantity($value){self::$stock=(string)$value;} public function set_stock_status($value){self::$stockStatus=(string)$value;} public function save(){return 1;} }
final class SmokeWCItem {
 public function get_product(){return new SmokeWCProduct();} public function get_quantity(){return 1;} public function get_product_id(){return 502;} public function get_variation_id(){return 0;} public function get_name(){return 'Reconcile product';} public function get_total(){return '1000';} public function get_total_tax(){return '0';}
}
final class SmokeWCOrder {
 private int $id; public function __construct(int $id){$this->id=$id;} public function get_id(){return $this->id;} public function get_status(){return 'processing';} public function get_total_refunded(){return 0;} public function get_currency(){return 'IRR';} public function get_meta($key,$single=true){return 0;} public function get_items($type='line_item'){return $type==='line_item'?array(88=>new SmokeWCItem()):array();} public function get_date_created(){return new SmokeWCDate();} public function get_created_via(){return 'checkout';} public function get_billing_first_name(){return 'Recon';} public function get_billing_last_name(){return 'Test';} public function get_billing_phone(){return '09121111111';} public function get_billing_email(){return 'recon@example.com';} public function get_total_tax(){return '0';} public function get_total(){return '1000';}
}
function wc_format_decimal($value,$dp=2){return number_format((float)$value,$dp,'.','');}
$GLOBALS['mda_smoke_orders']=array(new SmokeWCOrder(9001),new SmokeWCOrder(9002));
function wc_get_orders($args){return (object)array('orders'=>$GLOBALS['mda_smoke_orders'],'max_num_pages'=>1);}
function wc_get_product($id){return new SmokeWCProduct();}
$db=MDA_Database::connection();$now='2026-11-16 10:00:00';
$warehouse=(int)$db->query("SELECT id FROM mda_warehouses WHERE warehouse_code='MAIN'")->fetchColumn();
$db->prepare("INSERT INTO mda_inventory_movements (product_id,variation_id,warehouse_id,movement_type,quantity_scaled,quantity_scale,unit_cost_minor,currency_code,source_type,source_id,idempotency_key,occurred_at) VALUES (501,0,:w,'opening',10000000,1000000,1000,'IRR','opening','test','opening-501-test-001',:n)")->execute(array(':w'=>$warehouse,':n'=>$now));
$db->prepare("INSERT INTO mda_product_cost_state (product_id,variation_id,warehouse_id,quantity_scaled,quantity_scale,average_cost_minor,currency_code,updated_at) VALUES (501,0,:w,10000000,1000000,1000,'IRR',:n)")->execute(array(':w'=>$warehouse,':n'=>$now));
$payload=array('order_id'=>9001,'currency'=>'IRR','date'=>'2026-11-16','warehouse_id'=>$warehouse,'channel'=>'phone','customer'=>array('name'=>'Test Customer','phone'=>'09120000000','email'=>'test@example.com'),'items'=>array(array('order_item_id'=>77,'product_id'=>501,'variation_id'=>0,'name'=>'Test product','sku'=>'T-501','quantity_scaled'=>2000000,'quantity_scale'=>1000000,'revenue_minor'=>5000,'tax_minor'=>400)),'shipping_minor'=>1000,'fees_minor'=>0,'tax_minor'=>600,'total_minor'=>6600,'idempotency_key'=>'wc-sale-9001-v1');
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
$saleId=(int)$db->query("SELECT id FROM mda_sales_documents WHERE order_id=9001")->fetchColumn();
$cash=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='1110'")->fetchColumn();
$receipt=MDA_Sales_Receipt_Service::record($saleId,3000,$cash,'bank','test-sales-receipt-001','2026-11-16');
if(!empty($receipt['duplicate'])||MDA_Sales_Receipt_Service::summary($saleId)['receivable_minor']!==3600)throw new RuntimeException('Customer receipt or receivable balance incorrect.');
$receiptAgain=MDA_Sales_Receipt_Service::record($saleId,3000,$cash,'bank','test-sales-receipt-001','2026-11-16');
if(empty($receiptAgain['duplicate'])||count(MDA_Sales_Receipt_Service::receipts($saleId))!==1)throw new RuntimeException('Customer receipt idempotency failed.');
try{MDA_Sales_Receipt_Service::record($saleId,3601,$cash,'cash','test-sales-overreceipt-001','2026-11-16');throw new RuntimeException('Over-receipt was accepted.');}catch(InvalidArgumentException $expected){}
if(MDA_Sales_Receipt_Service::summary($saleId)['receivable_minor']!==3600)throw new RuntimeException('Over-receipt rollback failed.');
$receiptSecond=MDA_Sales_Receipt_Service::record($saleId,3600,$cash,'bank','test-sales-receipt-002','2026-11-16');
if(MDA_Sales_Receipt_Service::summary($saleId)['receivable_minor']!==0)throw new RuntimeException('Customer sale was not fully settled before return.');
$saleLineId=(int)$db->query("SELECT id FROM mda_sales_lines WHERE sales_document_id=".$saleId)->fetchColumn();
$return=MDA_Sales_Return_Service::post($saleId,array(array('sales_line_id'=>$saleLineId,'quantity_scaled'=>1000000)),'test-sales-return-001','2026-11-16');
if($return['total_minor']!==2700||$return['cogs_reversed_minor']!==1000)throw new RuntimeException('Sales return revenue, tax or COGS reversal is incorrect.');
$returnAgain=MDA_Sales_Return_Service::post($saleId,array(array('sales_line_id'=>$saleLineId,'quantity_scaled'=>1000000)),'test-sales-return-001','2026-11-16');
if(empty($returnAgain['duplicate'])||count(MDA_Sales_Return_Service::returns($saleId))!==1)throw new RuntimeException('Sales return idempotency failed.');
if((int)$db->query("SELECT quantity_scaled FROM mda_product_cost_state WHERE product_id=501")->fetchColumn()!==9000000)throw new RuntimeException('Customer return did not restore inventory cost state.');
if((int)$db->query("SELECT SUM(quantity_scaled) FROM mda_inventory_movements WHERE product_id=501")->fetchColumn()!==9000000)throw new RuntimeException('Customer return stock ledger is incorrect.');
$refundSummary=MDA_Sales_Refund_Service::summary((int)$return['return_id']);
if($refundSummary['available_minor']!==2700)throw new RuntimeException('Customer credit available for refund is incorrect.');
$refund=MDA_Sales_Refund_Service::record((int)$return['return_id'],1000,$cash,'bank','test-sales-refund-001','2026-11-16');
if(!empty($refund['duplicate'])||MDA_Sales_Refund_Service::summary((int)$return['return_id'])['refunded_minor']!==1000)throw new RuntimeException('Customer refund posting failed.');
try{MDA_Sales_Refund_Service::record((int)$return['return_id'],1800,$cash,'cash','test-sales-refund-over-001','2026-11-16');throw new RuntimeException('Over-refund was accepted.');}catch(InvalidArgumentException $expected){}
try{$db->exec("UPDATE mda_sales_returns SET total_minor=1 WHERE id=".(int)$return['return_id']);throw new RuntimeException('Posted sales return was mutable.');}catch(PDOException $expected){}
$totals=$db->query("SELECT SUM(debit_minor) debit,SUM(credit_minor) credit FROM mda_journal_entries")->fetch();if((int)$totals['debit']!==(int)$totals['credit'])throw new RuntimeException('Sales return/refund journals are not balanced.');
$plReport=MDA_Report_Service::profit_and_loss('2026-11-16','2026-11-16');
if(($plReport['totals']['IRR']['net_profit_minor']??null)!==2500)throw new RuntimeException('Profit and loss report did not reflect sales return and COGS reversal.');
$orderReport=MDA_Report_Service::order_profitability('2026-11-16','2026-11-16',100);
if(count($orderReport)!==1||$orderReport[0]['gross_profit_minor']!==1500)throw new RuntimeException('Order profitability report is incorrect.');
$productReport=MDA_Report_Service::product_profitability('2026-11-16','2026-11-16',100);
if(count($productReport)!==1||$productReport[0]['net_revenue_minor']!==2500||$productReport[0]['net_cogs_minor']!==1000)throw new RuntimeException('Product profitability report is incorrect.');
$channelReport=MDA_Report_Service::channel_profitability('2026-11-16','2026-11-16');
if(count($channelReport)!==1||$channelReport[0]['gross_profit_minor']!==1500)throw new RuntimeException('Sales channel profitability report is incorrect.');
$inventoryReport=MDA_Report_Service::inventory_valuation($warehouse);
if(($inventoryReport['totals']['IRR']??null)!==9000)throw new RuntimeException('Inventory valuation report is incorrect.');
$bankParent=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='1120'")->fetchColumn();
$db->prepare("INSERT INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order) VALUES ('1121','Smoke bank','asset',:parent,1,:now,'debit',0,0,1,'Test bank',41)")->execute(array(':parent'=>$bankParent,':now'=>$now));
$bank=(int)$db->lastInsertId();
$incomeAccount=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='4400'")->fetchColumn();$expenseAccount=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='5210'")->fetchColumn();
$cashReceipt=MDA_Cash_Service::record('receipt',1000,$cash,$incomeAccount,0,'IRR','cash','test-cash-receipt-001','2026-11-16','دریافت متفرقه','RCPT-1');
$cashPayment=MDA_Cash_Service::record('payment',200,$cash,$expenseAccount,0,'IRR','cash','test-cash-payment-001','2026-11-16','هزینه جاری','PAY-1');
$cashTransfer=MDA_Cash_Service::record('transfer',500,$cash,0,$bank,'IRR','transfer','test-cash-transfer-001','2026-11-16','انتقال به بانک','TR-1');
$cashDuplicate=MDA_Cash_Service::record('receipt',1000,$cash,$incomeAccount,0,'IRR','cash','test-cash-receipt-001','2026-11-16','دریافت متفرقه','RCPT-1');
if(empty($cashDuplicate['duplicate'])||count(MDA_Cash_Service::transactions(100))!==3)throw new RuntimeException('Cash transaction idempotency or history failed.');
$cashBalances=MDA_Cash_Service::balances();$balanceMap=array();foreach($cashBalances as $balance)$balanceMap[$balance['account_code']]=(int)$balance['balance_minor'];
if(($balanceMap['1110']??null)!==5900||($balanceMap['1121']??null)!==500)throw new RuntimeException('Cash/bank balances or transfer posting are incorrect: '.wp_json_encode($balanceMap));
try{$db->exec("UPDATE mda_cash_transactions SET amount_minor=1 WHERE id=1");throw new RuntimeException('Posted cash transaction was mutable.');}catch(PDOException $expected){}
$stockSync=MDA_Stock_Sync_Service::run_batch($warehouse,100);
if($stockSync['status']!=='completed'||$stockSync['synced']!==1||SmokeWCProduct::$stock!=='9')throw new RuntimeException('SQLite-to-WooCommerce stock sync failed: '.wp_json_encode($stockSync));
if((int)$db->query('SELECT COUNT(*) FROM mda_stock_sync_items WHERE run_id='.(int)$stockSync['run_id'])->fetchColumn()!==1)throw new RuntimeException('Stock sync audit item was not recorded.');
$reconciliation=MDA_Reconciliation_Service::run_batch(100);
if($reconciliation['scanned']!==2||$reconciliation['already_posted']!==1||$reconciliation['queued']!==1||$reconciliation['failed']!==0)throw new RuntimeException('WooCommerce reconciliation did not correctly find and queue a missing sale: '.wp_json_encode($reconciliation));
if($db->query("SELECT status FROM mda_integration_outbox WHERE event_key='wc-sale-9002-v1'")->fetchColumn()!=='pending')throw new RuntimeException('Reconciliation did not create a pending outbox event.');
if((int)$db->query('SELECT COUNT(*) FROM mda_reconciliation_runs')->fetchColumn()!==1)throw new RuntimeException('Reconciliation run audit record was not saved.');
$stockBlocked=MDA_Stock_Sync_Service::run_batch($warehouse,100);
if($stockBlocked['status']!=='blocked'||$stockBlocked['pending_events']<1)throw new RuntimeException('Stock sync was not blocked while an accounting outbox event was pending.');
$changed=$payload;$changed['total_minor']=6601;$rejected=false;try{MDA_Sales_Service::post_order_payload($changed);}catch(RuntimeException $e){$rejected=true;}if(!$rejected)throw new RuntimeException('Changed payload reused the same idempotency key.');
$cashIncome=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='4400'")->fetchColumn();
$cashExpense=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='5210'")->fetchColumn();
$bankParent=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='1120'")->fetchColumn();
$bankCode=1191;while($db->query("SELECT 1 FROM mda_accounts WHERE account_code='".$bankCode."'")->fetchColumn())$bankCode++;
$db->prepare("INSERT INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order) VALUES (:code,'بانک آزمایشی','asset',:parent,1,:now,'debit',0,0,1,'حساب بانکی تست',41)")->execute(array(':code'=>(string)$bankCode,':parent'=>$bankParent,':now'=>$now));
$bankAccount=(int)$db->lastInsertId();
$cashReceipt=MDA_Cash_Service::record('receipt',5000,$cash,$cashIncome,0,'IRR','bank','test-cash-receipt-001','2026-11-16','درآمد متفرقه تست','CASH-IN-001');
if(!empty($cashReceipt['duplicate']))throw new RuntimeException('Cash receipt was incorrectly marked duplicate.');
$storedCashHash=$db->query("SELECT payload_hash FROM mda_cash_transactions WHERE idempotency_key='test-cash-receipt-001'")->fetchColumn();$expectedCashHash=hash('sha256',wp_json_encode(array('receipt',5000,$cash,$cashIncome,0,'IRR','bank','2026-11-16','درآمد متفرقه تست','CASH-IN-001')));if(!hash_equals((string)$storedCashHash,$expectedCashHash))throw new RuntimeException('Cash receipt test payload hash mismatch: stored='.(string)$storedCashHash.' expected='.$expectedCashHash);
$cashReceiptAgain=MDA_Cash_Service::record('receipt',5000,$cash,$cashIncome,0,'IRR','bank','test-cash-receipt-001','2026-11-16','درآمد متفرقه تست','CASH-IN-001');
if(empty($cashReceiptAgain['duplicate']))throw new RuntimeException('Cash receipt idempotency failed.');
$receiptLines=$db->prepare('SELECT account_id,debit_minor,credit_minor FROM mda_journal_entries WHERE document_id=:id ORDER BY entry_number');$receiptLines->execute(array(':id'=>$cashReceipt['document_id']));$receiptRows=$receiptLines->fetchAll();
if((int)$receiptRows[0]['account_id']!==$cash||(int)$receiptRows[0]['debit_minor']!==5000||(int)$receiptRows[0]['credit_minor']!==0||(int)$receiptRows[1]['account_id']!==$cashIncome||(int)$receiptRows[1]['credit_minor']!==5000)throw new RuntimeException('Cash receipt journal debit/credit direction is incorrect.');
$cashPayment=MDA_Cash_Service::record('payment',2000,$cash,$cashExpense,0,'IRR','bank','test-cash-payment-001','2026-11-16','هزینه عملیاتی تست','CASH-OUT-001');
if(!empty($cashPayment['duplicate']))throw new RuntimeException('Cash payment was incorrectly marked duplicate.');
$cashTransfer=MDA_Cash_Service::record('transfer',1000,$cash,0,$bankAccount,'IRR','transfer','test-cash-transfer-001','2026-11-16','انتقال به بانک تست','CASH-TR-001');
if(!empty($cashTransfer['duplicate']))throw new RuntimeException('Cash transfer was incorrectly marked duplicate.');
try{MDA_Cash_Service::record('payment',8000,$cash,$cashExpense,0,'IRR','bank','test-cash-overdraft-001','2026-11-16','پرداخت بیش از موجودی','CASH-OUT-OVER');throw new RuntimeException('Cash overdraft was accepted.');}catch(RuntimeException $expected){if($expected->getMessage()==='Cash overdraft was accepted.')throw $expected;}
$cashBalance=$db->prepare("SELECT COALESCE(SUM(j.debit_minor-j.credit_minor),0) FROM mda_journal_entries j JOIN mda_documents d ON d.id=j.document_id WHERE j.account_id=:account AND d.currency_code='IRR' AND d.document_status='posted'");$cashBalance->execute(array(':account'=>$cash));if((int)$cashBalance->fetchColumn()!==7600)throw new RuntimeException('Cash balance after receipt, payment and transfer is incorrect.');
$bankBalance=$cashBalance;$bankBalance->execute(array(':account'=>$bankAccount));if((int)$bankBalance->fetchColumn()!==1000)throw new RuntimeException('Destination bank balance after transfer is incorrect.');
if(count(MDA_Cash_Service::transactions(100))!==3)throw new RuntimeException('Cash transaction history is incomplete.');
try{$db->exec("UPDATE mda_cash_transactions SET amount_minor=1 WHERE id=".(int)$cashReceipt['transaction_id']);throw new RuntimeException('Posted cash transaction was mutable.');}catch(PDOException $expected){}
$allJournal=$db->query('SELECT SUM(debit_minor) debit,SUM(credit_minor) credit FROM mda_journal_entries')->fetch();if((int)$allJournal['debit']!==(int)$allJournal['credit'])throw new RuntimeException('Cash/bank journals are not balanced.');
$cashPl=MDA_Report_Service::profit_and_loss('2026-11-16','2026-11-16');if(($cashPl['totals']['IRR']['net_profit_minor']??null)!==5500)throw new RuntimeException('Cash income and operating expense did not flow into profit and loss correctly.');
echo "OK: Outbox processing, sales posting, balanced journal, weighted-average COGS, stock issue, immutable snapshots, customer receipts, sales returns, COGS reversal, inventory restoration, customer refunds cash receipts, expense payments, transfers, cash balances, overdraft protection and idempotency passed.\\n";

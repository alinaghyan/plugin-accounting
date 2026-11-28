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
$migrations=array_map('basename',glob(dirname(__DIR__).'/database/migrations/*.sql'));sort($migrations,SORT_STRING);
foreach($migrations as $file){$sql=file_get_contents(dirname(__DIR__).'/database/migrations/'.$file);if(false===$sql)throw new RuntimeException('Missing migration '.$file);MDA_Database::connection()->exec($sql);}
require_once dirname(__DIR__).'/includes/class-mda-inventory-service.php';
require_once dirname(__DIR__).'/includes/class-mda-ledger-service.php';
require_once dirname(__DIR__).'/includes/class-mda-supply-service.php';
require_once dirname(__DIR__).'/includes/class-mda-purchase-service.php';
require_once dirname(__DIR__).'/includes/class-mda-purchase-settlement-service.php';
require_once dirname(__DIR__).'/includes/class-mda-purchase-return-service.php';
$db=MDA_Database::connection();$now='2026-11-16 10:00:00';
$db->prepare("INSERT INTO mda_parties (party_code,display_name,is_supplier,created_at,updated_at) VALUES ('SUP-1','Supplier One',1,:n,:n)")->execute(array(':n'=>$now));
$db->exec("INSERT INTO mda_party_roles (party_id,role_key,created_at) VALUES (1,'supplier','$now')");
$warehouse=(int)$db->query("SELECT id FROM mda_warehouses WHERE warehouse_code='MAIN'")->fetchColumn();
$cash=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='1110'")->fetchColumn();
$equity=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='3000'")->fetchColumn();
$inventoryAccount=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='1300'")->fetchColumn();
$opening=$db->prepare("INSERT INTO mda_documents (document_number,document_type,document_status,idempotency_key,payload_hash,currency_code,document_date,created_at,posted_at) VALUES ('TEST-OPENING-CASH','opening','posted','test-opening-cash-001','test-hash','IRR','2026-11-16',:n,:n)");
$opening->execute(array(':n'=>$now));$openingId=(int)$db->lastInsertId();
$openEntry=$db->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,:n,:account,:debit,:credit,:memo,:now)');
$openEntry->execute(array(':doc'=>$openingId,':n'=>1,':account'=>$cash,':debit'=>1000,':credit'=>0,':memo'=>'Test opening cash',':now'=>$now));
$openEntry->execute(array(':doc'=>$openingId,':n'=>2,':account'=>$equity,':debit'=>0,':credit'=>1000,':memo'=>'Test opening equity',':now'=>$now));
$cashBalance=static function()use($db,$cash):int{$q=$db->prepare("SELECT COALESCE(SUM(j.debit_minor-j.credit_minor),0) FROM mda_journal_entries j JOIN mda_documents d ON d.id=j.document_id WHERE j.account_id=:account AND d.currency_code='IRR' AND d.document_status='posted'");$q->execute(array(':account'=>$cash));return(int)$q->fetchColumn();};
$purchase=MDA_Purchase_Service::create_draft(array('supplier_party_id'=>1,'warehouse_id'=>$warehouse,'document_date'=>'2026-11-16','currency_code'=>'IRR','idempotency_key'=>'test-return-purchase-001','lines'=>array(array('product_id'=>777,'variation_id'=>0,'product_name_snapshot'=>'Return test item','quantity_scaled'=>2000000,'quantity_scale'=>1000000,'unit_cost_minor'=>100))));
$posted=MDA_Purchase_Service::post($purchase,100,$cash,'cash','test-initial-payment-001');
if($posted['total_minor']!==200||$posted['paid_minor']!==100||$posted['payable_minor']!==100||$cashBalance()!==900)throw new RuntimeException('Initial purchase payable or cash balance is incorrect.');
$line=(int)$db->query("SELECT id FROM mda_purchase_lines WHERE purchase_id=$purchase")->fetchColumn();
$beforePayments=(int)$db->query("SELECT COUNT(*) FROM mda_purchase_payments WHERE purchase_id=$purchase")->fetchColumn();
$beforePaymentDocs=(int)$db->query("SELECT COUNT(*) FROM mda_documents WHERE document_type='supplier_payment'")->fetchColumn();
$db->exec("CREATE TRIGGER test_fail_purchase_payment_audit BEFORE INSERT ON mda_audit_logs WHEN NEW.action='purchase_payment_recorded' BEGIN SELECT RAISE(ABORT,'forced purchase payment audit failure'); END");
try{MDA_Purchase_Settlement_Service::record_payment($purchase,50,$cash,'bank','test-stage-payment-rollback-001','2026-11-16');throw new RuntimeException('Payment audit failure did not roll back.');}catch(PDOException $expected){}
$db->exec('DROP TRIGGER test_fail_purchase_payment_audit');
if((int)$db->query("SELECT COUNT(*) FROM mda_purchase_payments WHERE purchase_id=$purchase")->fetchColumn()!==$beforePayments||(int)$db->query("SELECT COUNT(*) FROM mda_documents WHERE document_type='supplier_payment'")->fetchColumn()!==$beforePaymentDocs||$cashBalance()!==900)throw new RuntimeException('Failed payment left partial journal, payment or cash changes.');
$pay=MDA_Purchase_Settlement_Service::record_payment($purchase,50,$cash,'bank','test-stage-payment-001','2026-11-16');
if($pay['paid_minor']!==150||$pay['payable_minor']!==50||$cashBalance()!==850)throw new RuntimeException('Staged payment balance or cash balance is incorrect.');
$dup=MDA_Purchase_Settlement_Service::record_payment($purchase,50,$cash,'bank','test-stage-payment-001','2026-11-16');
if(empty($dup['duplicate'])||count(MDA_Purchase_Settlement_Service::payments($purchase))!==2)throw new RuntimeException('Payment idempotency failed.');
$overpayRejected=false;try{MDA_Purchase_Settlement_Service::record_payment($purchase,51,$cash,'cash','test-overpay-001','2026-11-16');}catch(InvalidArgumentException $e){$overpayRejected=true;}if(!$overpayRejected)throw new RuntimeException('Overpayment was accepted.');
$payloadMismatchRejected=false;try{MDA_Purchase_Settlement_Service::record_payment($purchase,51,$cash,'bank','test-stage-payment-001','2026-11-16');}catch(RuntimeException $e){$payloadMismatchRejected=true;}if(!$payloadMismatchRejected)throw new RuntimeException('An idempotency key accepted a different payload.');
$second=MDA_Purchase_Service::create_draft(array('supplier_party_id'=>1,'warehouse_id'=>$warehouse,'document_date'=>'2026-11-16','currency_code'=>'IRR','idempotency_key'=>'test-cash-check-purchase-001','lines'=>array(array('product_id'=>778,'variation_id'=>0,'product_name_snapshot'=>'Cash check item','quantity_scaled'=>1000000,'quantity_scale'=>1000000,'unit_cost_minor'=>1000))));
MDA_Purchase_Service::post($second,0,0,'cash','test-cash-check-post-001');
$invalidCashRejected=false;try{MDA_Purchase_Settlement_Service::record_payment($second,1,$inventoryAccount,'cash','test-invalid-cash-account-001','2026-11-16');}catch(InvalidArgumentException $e){$invalidCashRejected=true;}if(!$invalidCashRejected)throw new RuntimeException('Inventory account was accepted as a cash account.');
$databaseCashGuard=false;try{$db->prepare("INSERT INTO mda_purchase_payments (purchase_id,amount_minor,cash_account_id,payment_method,idempotency_key,paid_at,created_at) VALUES (:purchase,1,:account,'cash','test-direct-invalid-cash-001','2026-11-16 10:00:00',:now)")->execute(array(':purchase'=>$second,':account'=>$inventoryAccount,':now'=>$now));}catch(PDOException $e){$databaseCashGuard=true;}if(!$databaseCashGuard)throw new RuntimeException('Database accepted a non-cash account for a purchase payment.');
$insufficientFunds=false;try{MDA_Purchase_Settlement_Service::record_payment($second,851,$cash,'bank','test-insufficient-cash-001','2026-11-16');}catch(RuntimeException $e){$insufficientFunds=strpos($e->getMessage(),'موجودی حساب')!==false;}if(!$insufficientFunds)throw new RuntimeException('Payment exceeded available cash/bank funds.');
if(MDA_Purchase_Settlement_Service::summary($second)['payable_minor']!==1000||(int)$db->query("SELECT COUNT(*) FROM mda_purchase_payments WHERE purchase_id=$second")->fetchColumn()!==0||$cashBalance()!==850)throw new RuntimeException('Insufficient-funds failure changed supplier payable or cash.');
$return1=MDA_Purchase_Return_Service::post($purchase,array(array('purchase_line_id'=>$line,'quantity_scaled'=>500000)),'test-partial-return-001','2026-11-16');
if($return1['total_minor']!==50||MDA_Inventory_Service::balance(777,$warehouse)!==1500000)throw new RuntimeException('Partial return quantity or valuation incorrect.');
$summary=MDA_Purchase_Settlement_Service::summary($purchase);
if($summary['returned_minor']!==50||$summary['paid_minor']!==150||$summary['payable_minor']!==0)throw new RuntimeException('Net supplier payable did not include the partial return.');
$dupReturn=MDA_Purchase_Return_Service::post($purchase,array(array('purchase_line_id'=>$line,'quantity_scaled'=>500000)),'test-partial-return-001','2026-11-16');
if(empty($dupReturn['duplicate']))throw new RuntimeException('Return idempotency failed.');
$paymentAfterReturnRejected=false;try{MDA_Purchase_Settlement_Service::record_payment($purchase,1,$cash,'bank','test-after-return-payment-001','2026-11-16');}catch(InvalidArgumentException $e){$paymentAfterReturnRejected=true;}if(!$paymentAfterReturnRejected)throw new RuntimeException('Payment was accepted after the net payable reached zero.');
$beforeReturns=(int)$db->query("SELECT COUNT(*) FROM mda_purchase_returns WHERE purchase_id=$purchase")->fetchColumn();
$beforeReturnDocs=(int)$db->query("SELECT COUNT(*) FROM mda_documents WHERE document_type='purchase_return'")->fetchColumn();
$db->exec("CREATE TRIGGER test_fail_purchase_return_line BEFORE INSERT ON mda_purchase_return_lines BEGIN SELECT RAISE(ABORT,'forced purchase return line failure'); END");
try{MDA_Purchase_Return_Service::post($purchase,array(array('purchase_line_id'=>$line,'quantity_scaled'=>250000)),'test-return-rollback-001','2026-11-16');throw new RuntimeException('Return line failure did not roll back.');}catch(PDOException $expected){}
$db->exec('DROP TRIGGER test_fail_purchase_return_line');
if((int)$db->query("SELECT COUNT(*) FROM mda_purchase_returns WHERE purchase_id=$purchase")->fetchColumn()!==$beforeReturns||(int)$db->query("SELECT COUNT(*) FROM mda_documents WHERE document_type='purchase_return'")->fetchColumn()!==$beforeReturnDocs||MDA_Inventory_Service::balance(777,$warehouse)!==1500000)throw new RuntimeException('Failed return left partial document or inventory changes.');
$return2=MDA_Purchase_Return_Service::post($purchase,array(array('purchase_line_id'=>$line,'quantity_scaled'=>1500000)),'test-full-return-001','2026-11-16');
if($return2['total_minor']!==150||MDA_Inventory_Service::balance(777,$warehouse)!==0)throw new RuntimeException('Full return or inventory reversal failed.');
$summary=MDA_Purchase_Settlement_Service::summary($purchase);
if($summary['returned_minor']!==200||$summary['paid_minor']!==150||$summary['payable_minor']!==-150)throw new RuntimeException('Supplier credit balance after a fully returned, partly paid purchase is incorrect.');
$overreturnRejected=false;try{MDA_Purchase_Return_Service::post($purchase,array(array('purchase_line_id'=>$line,'quantity_scaled'=>1)),'test-overreturn-001','2026-11-16');}catch(Throwable $e){$overreturnRejected=true;}if(!$overreturnRejected)throw new RuntimeException('Over-return was accepted.');
$db->exec("INSERT INTO mda_financial_periods (period_name,starts_on,ends_on,status) VALUES ('Closed smoke period','2026-11-16','2026-11-16','closed')");
$periodBlocked=false;try{MDA_Purchase_Settlement_Service::record_payment($second,1,$cash,'bank','test-closed-period-payment-001','2026-11-16');}catch(RuntimeException $e){$periodBlocked=strpos($e->getMessage(),'دوره مالی بسته')!==false;}if(!$periodBlocked)throw new RuntimeException('Closed-period purchase payment was not blocked.');
$balanced=$db->query("SELECT d.id,COALESCE(SUM(j.debit_minor),0) debit,COALESCE(SUM(j.credit_minor),0) credit FROM mda_documents d LEFT JOIN mda_journal_entries j ON j.document_id=d.id GROUP BY d.id")->fetchAll();
foreach($balanced as $j)if((int)$j['debit']!==(int)$j['credit'])throw new RuntimeException('Unbalanced journal document '.$j['id']);
if((int)$db->query("SELECT COUNT(*) FROM mda_purchase_returns")->fetchColumn()!==2)throw new RuntimeException('Return documents missing.');
if((int)$db->query("SELECT COUNT(*) FROM mda_purchase_payments")->fetchColumn()!==2)throw new RuntimeException('Payment records missing.');
try{$db->exec("UPDATE mda_purchase_payments SET amount_minor=1 WHERE purchase_id=$purchase");throw new RuntimeException('Payment immutability failed.');}catch(PDOException $expected){}
try{$db->exec("DELETE FROM mda_purchase_payments WHERE purchase_id=$purchase");throw new RuntimeException('Payment delete protection failed.');}catch(PDOException $expected){}
try{$db->exec("UPDATE mda_purchase_returns SET total_minor=1");throw new RuntimeException('Return immutability failed.');}catch(PDOException $expected){}
try{$db->exec("DELETE FROM mda_purchase_return_lines");throw new RuntimeException('Return line immutability failed.');}catch(PDOException $expected){}
echo "OK: multi-stage payments, net payable after returns, cash/bank account validation, sufficient funds, atomic rollback, partial/full returns, stock reversal, balanced journals, closed periods, idempotency and append-only history passed.\n";

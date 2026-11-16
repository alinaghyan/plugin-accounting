<?php
defined('ABSPATH') || exit;

/** Generic CSV-based payment-gateway capture and settlement posting. All financial state lives in SQLite. */
final class MDA_Gateway_Settlement_Service {
 public static function configure(string $key,string $name): int {
  $key=sanitize_key($key);$name=trim(sanitize_text_field($name));
  if($key===''||strlen($key)>64||$name===''||strlen($name)>120)throw new InvalidArgumentException('شناسه و نام درگاه معتبر نیست.');
  return MDA_Database::transaction(static function(PDO $db)use($key,$name):int{
   $old=$db->prepare('SELECT account_id FROM mda_gateway_accounts WHERE gateway_key=:key');$old->execute(array(':key'=>$key));$id=$old->fetchColumn();if($id)return(int)$id;
   $parent=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='1140' AND is_active=1")->fetchColumn();if(!$parent)throw new RuntimeException('حساب واسط درگاه‌ها ایجاد نشده است.');
   $next=(int)$db->query("SELECT COALESCE(MAX(CAST(account_code AS INTEGER)),1140)+1 FROM mda_accounts WHERE account_code GLOB '114[1-9]*'")->fetchColumn();
   if($next>1149)throw new RuntimeException('ظرفیت کدینگ حساب درگاه‌ها تکمیل است.');
   $q=$db->prepare("INSERT INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order) VALUES (:code,:name,'asset',:parent,1,:now,'debit',0,1,0,:description,46)");
   $q->execute(array(':code'=>(string)$next,':name'=>'درگاه '.$name,':parent'=>$parent,':now'=>gmdate('Y-m-d H:i:s'),':description'=>'حساب واسط اختصاصی درگاه '.$key));$id=(int)$db->lastInsertId();
   $db->prepare('INSERT INTO mda_gateway_accounts (gateway_key,gateway_name,account_id,created_at) VALUES (:key,:name,:account,:now)')->execute(array(':key'=>$key,':name'=>$name,':account'=>$id,':now'=>gmdate('Y-m-d H:i:s')));return$id;
  });
 }
 public static function import_csv(string $gateway,string $reference,int $bankAccount,string $currency,string $date,string $csv,string $key):array {
  $gateway=sanitize_key($gateway);$reference=trim($reference);$currency=strtoupper($currency);
  if($gateway===''||$reference===''||strlen($reference)>120||$bankAccount<1||!in_array($currency,array('IRR','IRT'),true)||strlen($key)<12||strlen($csv)>1048576)throw new InvalidArgumentException('اطلاعات فایل تسویه معتبر نیست.');
  $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('تاریخ تسویه معتبر نیست.');
  $rows=self::parse_csv($csv);if(!$rows||count($rows)>2000)throw new InvalidArgumentException('فایل باید بین ۱ تا ۲۰۰۰ ردیف تسویه داشته باشد.');
  $hash=hash('sha256',wp_json_encode(array($gateway,$reference,$bankAccount,$currency,$date,$rows)));
  return MDA_Database::transaction(static function(PDO $db)use($gateway,$reference,$bankAccount,$currency,$date,$rows,$key,$hash):array{
   $old=$db->prepare('SELECT id,payload_hash FROM mda_gateway_settlements WHERE idempotency_key=:key OR (gateway_key=:gateway AND settlement_reference=:ref)');$old->execute(array(':key'=>$key,':gateway'=>$gateway,':ref'=>$reference));$found=$old->fetch();if($found){if(!hash_equals((string)$found['payload_hash'],$hash))throw new RuntimeException('شماره تسویه قبلاً با محتوای متفاوت ثبت شده است.');return array('settlement_id'=>(int)$found['id'],'duplicate'=>true);}
   $ga=$db->prepare('SELECT account_id FROM mda_gateway_accounts WHERE gateway_key=:key');$ga->execute(array(':key'=>$gateway));$clearing=(int)$ga->fetchColumn();if(!$clearing)throw new RuntimeException('ابتدا حساب واسط این درگاه را تعریف کنید.');
   $bank=$db->prepare("SELECT id FROM mda_accounts WHERE id=:id AND account_code GLOB '112*' AND account_type='asset' AND is_control=0 AND is_active=1 AND archived_at IS NULL");$bank->execute(array(':id'=>$bankAccount));if(!$bank->fetchColumn())throw new InvalidArgumentException('حساب بانکی مقصد معتبر نیست.');
   $closed=$db->prepare("SELECT 1 FROM mda_financial_periods WHERE status='closed' AND :date BETWEEN starts_on AND ends_on");$closed->execute(array(':date'=>$date));if($closed->fetchColumn())throw new RuntimeException('ثبت تسویه در دوره مالی بسته‌شده مجاز نیست.');
   $insert=$db->prepare('INSERT INTO mda_gateway_settlements (gateway_key,settlement_reference,bank_account_id,currency_code,settlement_date,row_count,idempotency_key,payload_hash,created_at) VALUES (:gateway,:ref,:bank,:currency,:date,:count,:key,:hash,:now)');
   $insert->execute(array(':gateway'=>$gateway,':ref'=>$reference,':bank'=>$bankAccount,':currency'=>$currency,':date'=>$date,':count'=>count($rows),':key'=>$key,':hash'=>$hash,':now'=>gmdate('Y-m-d H:i:s')));$sid=(int)$db->lastInsertId();
   $payment=$db->prepare('SELECT * FROM mda_gateway_payments WHERE gateway_key=:gateway AND order_id=:order');
   $insertLine=$db->prepare('INSERT INTO mda_gateway_settlement_lines (settlement_id,line_number,payment_id,order_id,gross_minor,fee_minor,net_minor,reference_number,payload_hash,created_at) VALUES (:sid,:line,:payment,:order,:gross,:fee,:net,:reference,:hash,:now)');
   foreach($rows as $i=>$row){
    $payment->execute(array(':gateway'=>$gateway,':order'=>$row['order_id']));$p=$payment->fetch();if(!$p)throw new RuntimeException('برای سفارش '.$row['order_id'].' پرداخت ثبت‌شده‌ای از این درگاه پیدا نشد؛ ردیف بدون تطبیق باقی می‌ماند و تسویه ثبت نشد.');
    if($p['currency_code']!==$currency||(int)$p['gross_minor']!==$row['gross_minor'])throw new RuntimeException('مبلغ ناخالص یا ارز سفارش '.$row['order_id'].' با پرداخت ثبت‌شده تطبیق ندارد.');
    $already=$db->prepare('SELECT COALESCE(SUM(l.gross_minor),0) FROM mda_gateway_settlement_lines l JOIN mda_gateway_settlement_events e ON e.settlement_id=l.settlement_id AND e.action=\'posted\' WHERE l.payment_id=:payment');$already->execute(array(':payment'=>$p['id']));$settled=(int)$already->fetchColumn();
    if($row['gross_minor']>$p['gross_minor']-$settled)throw new RuntimeException('مبلغ تسویه سفارش '.$row['order_id'].' از مانده تسویه‌نشده بیشتر است.');
    $insertLine->execute(array(':sid'=>$sid,':line'=>$i+1,':payment'=>$p['id'],':order'=>$row['order_id'],':gross'=>$row['gross_minor'],':fee'=>$row['fee_minor'],':net'=>$row['net_minor'],':reference'=>$row['reference']?:null,':hash'=>hash('sha256',wp_json_encode($row)),':now'=>gmdate('Y-m-d H:i:s')));
   }
   return array('settlement_id'=>$sid,'duplicate'=>false);
  });
 }
 public static function post(int $id,string $key):array {
  if($id<1||strlen($key)<12)throw new InvalidArgumentException('شناسه تسویه یا کلید یکتا معتبر نیست.');
  return MDA_Database::transaction(static function(PDO $db)use($id,$key):array{
   $old=$db->prepare("SELECT id,accounting_document_id FROM mda_gateway_settlement_events WHERE settlement_id=:id AND action='posted'");$old->execute(array(':id'=>$id));$existing=$old->fetch();if($existing)return array('document_id'=>(int)$existing['accounting_document_id'],'duplicate'=>true);
   $q=$db->prepare('SELECT * FROM mda_gateway_settlements WHERE id=:id');$q->execute(array(':id'=>$id));$batch=$q->fetch();if(!$batch)throw new RuntimeException('تسویه پیدا نشد.');
   $ga=$db->prepare('SELECT account_id FROM mda_gateway_accounts WHERE gateway_key=:key');$ga->execute(array(':key'=>$batch['gateway_key']));$clearing=(int)$ga->fetchColumn();
   $lines=$db->prepare('SELECT * FROM mda_gateway_settlement_lines WHERE settlement_id=:id ORDER BY line_number');$lines->execute(array(':id'=>$id));$rows=$lines->fetchAll();if(!$rows)throw new RuntimeException('تسویه فاقد ردیف است.');
   $gross=0;$fee=0;$net=0;foreach($rows as $r){$gross=self::add($gross,(int)$r['gross_minor']);$fee=self::add($fee,(int)$r['fee_minor']);$net=self::add($net,(int)$r['net_minor']);}
   if($gross!==$fee+$net)throw new RuntimeException('جمع ناخالص، کارمزد و خالص تسویه متوازن نیست.');
   $feeAccount=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='5240' AND is_active=1 AND archived_at IS NULL")->fetchColumn();if(!$feeAccount)throw new RuntimeException('سرفصل کارمزد 5240 پیدا نشد.');
   $now=gmdate('Y-m-d H:i:s');$number='MDA-GWY-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));$hash=hash('sha256',wp_json_encode(array($id,$gross,$fee,$net)));
   $doc=$db->prepare("INSERT INTO mda_documents (document_number,document_type,document_status,source_type,source_id,idempotency_key,payload_hash,currency_code,amount_scale,document_date,description,created_by,created_at,posted_at) VALUES (:number,'gateway_settlement','posted','gateway_settlement',:source,:key,:hash,:currency,1,:date,:description,:user,:now,:now)");
   $doc->execute(array(':number'=>$number,':source'=>(string)$id,':key'=>'gateway-settlement-'.$key,':hash'=>$hash,':currency'=>$batch['currency_code'],':date'=>$batch['settlement_date'],':description'=>'تسویه درگاه '.$batch['gateway_key'].' / '.$batch['settlement_reference'],':user'=>get_current_user_id()?:null,':now'=>$now));$docId=(int)$db->lastInsertId();
   $entry=$db->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,:n,:account,:debit,:credit,:memo,:now)');
   $entry->execute(array(':doc'=>$docId,':n'=>1,':account'=>(int)$batch['bank_account_id'],':debit'=>$net,':credit'=>0,':memo'=>'واریز خالص درگاه',':now'=>$now));
   if($fee>0)$entry->execute(array(':doc'=>$docId,':n'=>2,':account'=>$feeAccount,':debit'=>$fee,':credit'=>0,':memo'=>'کارمزد تسویه درگاه',':now'=>$now));
   $entry->execute(array(':doc'=>$docId,':n'=>$fee>0?3:2,':account'=>$clearing,':debit'=>0,':credit'=>$gross,':memo'=>'بستن مطالبات درگاه بابت تسویه',':now'=>$now));
   $db->prepare("INSERT INTO mda_gateway_settlement_events (settlement_id,action,accounting_document_id,idempotency_key,created_by,created_at) VALUES (:id,'posted',:doc,:key,:user,:now)")->execute(array(':id'=>$id,':doc'=>$docId,':key'=>$key,':user'=>get_current_user_id()?:null,':now'=>$now));
   $db->prepare("INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,'gateway_settlement_posted','gateway_settlement',:id,:json,:now)")->execute(array(':user'=>get_current_user_id()?:null,':id'=>(string)$id,':json'=>wp_json_encode(array('gross_minor'=>$gross,'fee_minor'=>$fee,'net_minor'=>$net,'document_id'=>$docId)),':now'=>$now));
   return array('document_id'=>$docId,'document_number'=>$number,'duplicate'=>false);
  });
 }
 public static function all(int $limit=100):array{return MDA_Database::connection()->query('SELECT s.*,a.account_code,a.account_name,e.accounting_document_id FROM mda_gateway_settlements s JOIN mda_accounts a ON a.id=s.bank_account_id LEFT JOIN mda_gateway_settlement_events e ON e.settlement_id=s.id AND e.action=\'posted\' ORDER BY s.id DESC LIMIT '.max(1,min(500,$limit)))->fetchAll();}
 public static function gateways():array{return MDA_Database::connection()->query('SELECT g.*,a.account_code FROM mda_gateway_accounts g JOIN mda_accounts a ON a.id=g.account_id ORDER BY g.gateway_key')->fetchAll();}
 private static function parse_csv(string $csv):array{
  $csv=str_replace(array("\r\n","\r"),"\n",$csv);$lines=array_values(array_filter(explode("\n",$csv),static function($v){return trim($v)!=='';}));if(count($lines)<2)throw new InvalidArgumentException('CSV باید سطر عنوان و حداقل یک ردیف داشته باشد.');
  $delimiter=substr_count($lines[0],';')>substr_count($lines[0],',')?';':',';$header=array_map('trim',str_getcsv(array_shift($lines),$delimiter));$required=array('order_id','gross_minor','fee_minor','net_minor');foreach($required as $col)if(!in_array($col,$header,true))throw new InvalidArgumentException('ستون ضروری '.$col.' در CSV وجود ندارد.');
  $out=array();foreach($lines as $line){$v=str_getcsv($line,$delimiter);if(count($v)!==count($header))throw new InvalidArgumentException('تعداد ستون‌های CSV یکسان نیست.');$r=array_combine($header,$v);$id=self::integer($r['order_id']);$gross=self::integer($r['gross_minor']);$fee=self::integer($r['fee_minor']);$net=self::integer($r['net_minor']);if($id<1||$gross<1||$fee<0||$net<1||$gross!==$fee+$net)throw new InvalidArgumentException('مبالغ یا شناسه سفارش در CSV معتبر نیست.');$out[]=array('order_id'=>$id,'gross_minor'=>$gross,'fee_minor'=>$fee,'net_minor'=>$net,'reference'=>sanitize_text_field($r['reference']??''));}
  return$out;
 }
 private static function integer($v):int{$v=strtr(trim((string)$v),array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',','=>''));if(!preg_match('/^(0|[1-9][0-9]*)$/',$v)||strlen($v)>15)throw new InvalidArgumentException('مبلغ باید عدد صحیح مثبت باشد.');return(int)$v;}
 private static function add(int $a,int $b):int{if($b>0&&$a>PHP_INT_MAX-$b)throw new OverflowException('مجموع مبالغ از ظرفیت عدد صحیح بیشتر است.');return$a+$b;}

 public static function capture(int $orderId,string $gateway,int $gross,string $reference,string $key):array {
  $gateway=sanitize_key($gateway);$reference=trim($reference);
  if($orderId<1||$gateway===''||$gross<1||strlen($key)<12||strlen($reference)>120)throw new InvalidArgumentException('اطلاعات دریافت درگاه معتبر نیست.');
  return MDA_Database::transaction(static function(PDO $db)use($orderId,$gateway,$gross,$reference,$key):array{
   $old=$db->prepare('SELECT id,payload_hash,accounting_document_id FROM mda_gateway_payments WHERE idempotency_key=:key');$old->execute(array(':key'=>$key));$existing=$old->fetch();
   $saleQ=$db->prepare("SELECT * FROM mda_sales_documents WHERE order_id=:order AND status='posted'");$saleQ->execute(array(':order'=>$orderId));$sale=$saleQ->fetch();if(!$sale)throw new RuntimeException('سند فروش قطعی این سفارش پیدا نشد.');
   $hash=hash('sha256',wp_json_encode(array($orderId,$gateway,$gross,$sale['currency_code'],$reference)));
   if($existing){if(!hash_equals((string)$existing['payload_hash'],$hash))throw new RuntimeException('کلید یکتا برای پرداخت متفاوتی استفاده شده است.');return array('payment_id'=>(int)$existing['id'],'duplicate'=>true);}
   $ga=$db->prepare('SELECT account_id FROM mda_gateway_accounts WHERE gateway_key=:key');$ga->execute(array(':key'=>$gateway));$clearing=(int)$ga->fetchColumn();if(!$clearing)throw new RuntimeException('حساب واسط درگاه تعریف نشده است.');
   if($sale['currency_code']!=='IRR'&&$sale['currency_code']!=='IRT')throw new RuntimeException('ارز سفارش پشتیبانی نمی‌شود.');
   $paid=$db->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM mda_sales_receipts WHERE sales_document_id=:id');$paid->execute(array(':id'=>$sale['id']));$received=(int)$paid->fetchColumn();
   $gw=$db->prepare('SELECT COALESCE(SUM(gross_minor),0) FROM mda_gateway_payments WHERE sales_document_id=:id');$gw->execute(array(':id'=>$sale['id']));$captured=(int)$gw->fetchColumn();
   if($gross>(int)$sale['total_minor']-$received-$captured)throw new RuntimeException('مبلغ پرداخت درگاه از مانده قابل دریافت سفارش بیشتر است.');
   $ar=(int)$db->query("SELECT id FROM mda_accounts WHERE account_code='1200' AND is_control=1 AND is_active=1")->fetchColumn();if(!$ar)throw new RuntimeException('حساب دریافتنی مشتریان پیدا نشد.');
   $now=gmdate('Y-m-d H:i:s');$number='MDA-GWP-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
   $doc=$db->prepare("INSERT INTO mda_documents (document_number,document_type,document_status,party_id,source_type,source_id,idempotency_key,payload_hash,currency_code,amount_scale,document_date,description,created_by,created_at,posted_at) VALUES (:number,'gateway_capture','posted',:party,'gateway_payment',:source,:key,:hash,:currency,1,:date,:description,:user,:now,:now)");
   $doc->execute(array(':number'=>$number,':party'=>$sale['party_id'],':source'=>(string)$orderId,':key'=>'gateway-capture-'.$key,':hash'=>$hash,':currency'=>$sale['currency_code'],':date'=>$sale['document_date'],':description'=>'دریافت درگاه '.$gateway.' بابت سفارش '.$orderId,':user'=>get_current_user_id()?:null,':now'=>$now));$docId=(int)$db->lastInsertId();
   $entry=$db->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,party_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,:n,:account,:party,:debit,:credit,:memo,:now)');
   $entry->execute(array(':doc'=>$docId,':n'=>1,':account'=>$clearing,':party'=>null,':debit'=>$gross,':credit'=>0,':memo'=>'مطالبه از درگاه بابت سفارش '.$orderId,':now'=>$now));
   $entry->execute(array(':doc'=>$docId,':n'=>2,':account'=>$ar,':party'=>$sale['party_id'],':debit'=>0,':credit'=>$gross,':memo'=>'تسویه مطالبات مشتری از طریق درگاه',':now'=>$now));
   $ins=$db->prepare('INSERT INTO mda_gateway_payments (gateway_key,order_id,sales_document_id,gross_minor,currency_code,reference_number,captured_at,idempotency_key,payload_hash,accounting_document_id,created_at) VALUES (:gateway,:order,:sale,:gross,:currency,:reference,:date,:key,:hash,:doc,:now)');
   $ins->execute(array(':gateway'=>$gateway,':order'=>$orderId,':sale'=>$sale['id'],':gross'=>$gross,':currency'=>$sale['currency_code'],':reference'=>$reference?:null,':date'=>$now,':key'=>$key,':hash'=>$hash,':doc'=>$docId,':now'=>$now));
   return array('payment_id'=>(int)$db->lastInsertId(),'document_id'=>$docId,'duplicate'=>false);
  });
 }
}

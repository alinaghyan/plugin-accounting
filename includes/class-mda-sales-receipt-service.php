<?php
defined( 'ABSPATH' ) || exit;

/** Append-only customer receipts; posting a sale never implies payment collection. */
final class MDA_Sales_Receipt_Service {
 public static function record(int $saleId,int $amount,int $cashAccountId,string $method,string $key,?string $date=null):array {
  if($saleId<1||$amount<1||$cashAccountId<1||!in_array($method,array('cash','bank','pos','transfer'),true)||strlen($key)<8||strlen($key)>191)throw new InvalidArgumentException('اطلاعات دریافت از مشتری معتبر نیست.');
  $date=$date?:current_time('Y-m-d');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('تاریخ دریافت معتبر نیست.');
  $hash=hash('sha256',wp_json_encode(array($saleId,$amount,$cashAccountId,$method,$date)));$now=gmdate('Y-m-d H:i:s');
  return MDA_Database::transaction(static function(PDO $db)use($saleId,$amount,$cashAccountId,$method,$key,$date,$hash,$now):array {
   $old=$db->prepare('SELECT id,sales_document_id,amount_minor,cash_account_id,payment_method,payment_date,payload_hash,accounting_document_id FROM mda_sales_receipts WHERE idempotency_key=:key');$old->execute(array(':key'=>$key));$existing=$old->fetch();
   if($existing){if(!hash_equals((string)$existing['payload_hash'],$hash))throw new RuntimeException('کلید یکتا برای دریافت متفاوتی استفاده شده است.');return array('receipt_id'=>(int)$existing['id'],'accounting_document_id'=>(int)$existing['accounting_document_id'],'duplicate'=>true);}
   $q=$db->prepare("SELECT * FROM mda_sales_documents WHERE id=:id AND status='posted'");$q->execute(array(':id'=>$saleId));$sale=$q->fetch();if(!$sale)throw new RuntimeException('سند فروش قطعی پیدا نشد.');
   $closed=$db->prepare("SELECT 1 FROM mda_financial_periods WHERE status='closed' AND :date BETWEEN starts_on AND ends_on LIMIT 1");$closed->execute(array(':date'=>$date));if($closed->fetchColumn())throw new RuntimeException('ثبت دریافت در دوره مالی بسته‌شده مجاز نیست.');
   $paid=$db->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM mda_sales_receipts WHERE sales_document_id=:id');$paid->execute(array(':id'=>$saleId));$received=(int)$paid->fetchColumn();
   if($amount>(int)$sale['total_minor']-$received)throw new InvalidArgumentException('مجموع دریافت‌ها نمی‌تواند از مانده فاکتور فروش بیشتر شود.');
   if($sale['currency_code']!=='IRR'&&$sale['currency_code']!=='IRT')throw new RuntimeException('واحد پول این فروش برای ثبت دریافت پشتیبانی نمی‌شود.');
   $cash=$db->prepare("SELECT id FROM mda_accounts WHERE id=:id AND account_type='asset' AND is_active=1 AND archived_at IS NULL AND is_control=0");$cash->execute(array(':id'=>$cashAccountId));if(!$cash->fetchColumn())throw new InvalidArgumentException('حساب انتخاب‌شده صندوق/بانک فعال نیست.');
   $ar=$db->prepare("SELECT id FROM mda_accounts WHERE account_code='1200' AND account_type='asset' AND is_active=1 AND archived_at IS NULL AND is_control=1");$ar->execute();$arId=(int)$ar->fetchColumn();if(!$arId)throw new RuntimeException('حساب دریافتنی مشتریان تنظیم نشده است.');
   $mapping=$db->prepare("SELECT 1 FROM mda_party_control_accounts WHERE role_key='customer' AND account_id=:id");$mapping->execute(array(':id'=>$arId));if(!$mapping->fetchColumn())throw new RuntimeException('حساب دریافتنی به تفصیلی مشتریان متصل نیست.');
   $number='MDA-REC-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
   $doc=$db->prepare("INSERT INTO mda_documents (document_number,document_type,document_status,party_id,source_type,source_id,idempotency_key,payload_hash,currency_code,amount_scale,document_date,description,created_by,created_at,posted_at) VALUES (:number,'customer_receipt','posted',:party,'sales_receipt',:source,:key,:hash,:currency,1,:date,:description,:user,:now,:now)");
   $doc->execute(array(':number'=>$number,':party'=>$sale['party_id'],':source'=>(string)$saleId,':key'=>'sales-receipt-journal-'.$key,':hash'=>$hash,':currency'=>$sale['currency_code'],':date'=>$date,':description'=>'دریافت وجه بابت فروش '.$sale['document_number'],':user'=>get_current_user_id()?:null,':now'=>$now));$journalId=(int)$db->lastInsertId();
   $entry=$db->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,party_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,:n,:account,:party,:debit,:credit,:memo,:now)');
   $entry->execute(array(':doc'=>$journalId,':n'=>1,':account'=>$cashAccountId,':party'=>null,':debit'=>$amount,':credit'=>0,':memo'=>'دریافت وجه '.$method.' بابت فروش '.$sale['document_number'],':now'=>$now));
   $entry->execute(array(':doc'=>$journalId,':n'=>2,':account'=>$arId,':party'=>$sale['party_id'],':debit'=>0,':credit'=>$amount,':memo'=>'کاهش مطالبات مشتری بابت دریافت وجه',':now'=>$now));
   $insert=$db->prepare('INSERT INTO mda_sales_receipts (sales_document_id,party_id,amount_minor,cash_account_id,payment_method,payment_date,idempotency_key,payload_hash,accounting_document_id,created_by,created_at) VALUES (:sale,:party,:amount,:cash,:method,:date,:key,:hash,:journal,:user,:now)');
   $insert->execute(array(':sale'=>$saleId,':party'=>$sale['party_id'],':amount'=>$amount,':cash'=>$cashAccountId,':method'=>$method,':date'=>$date,':key'=>$key,':hash'=>$hash,':journal'=>$journalId,':user'=>get_current_user_id()?:null,':now'=>$now));$id=(int)$db->lastInsertId();
   $db->prepare("INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,'customer_receipt_posted','sales_receipt',:id,:json,:now)")->execute(array(':user'=>get_current_user_id()?:null,':id'=>(string)$id,':json'=>wp_json_encode(array('sale_id'=>$saleId,'amount_minor'=>$amount,'cash_account_id'=>$cashAccountId)),':now'=>$now));
   return array('receipt_id'=>$id,'accounting_document_id'=>$journalId,'duplicate'=>false);
  });
 }
 public static function summary(int $saleId):array {
  $db=MDA_Database::connection();$q=$db->prepare('SELECT total_minor FROM mda_sales_documents WHERE id=:id');$q->execute(array(':id'=>$saleId));$total=$q->fetchColumn();if(false===$total)throw new RuntimeException('سند فروش پیدا نشد.');
  $q=$db->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM mda_sales_receipts WHERE sales_document_id=:id');$q->execute(array(':id'=>$saleId));$received=(int)$q->fetchColumn();return array('total_minor'=>(int)$total,'received_minor'=>$received,'receivable_minor'=>(int)$total-$received);
 }
 public static function all(int $limit=100):array {
  return MDA_Database::connection()->query('SELECT s.id,s.document_number,s.order_id,s.document_date,s.currency_code,s.total_minor,s.source_type,p.display_name,p.phone,COALESCE(SUM(r.amount_minor),0) received_minor FROM mda_sales_documents s JOIN mda_parties p ON p.id=s.party_id LEFT JOIN mda_sales_receipts r ON r.sales_document_id=s.id GROUP BY s.id ORDER BY s.id DESC LIMIT '.max(1,min(500,$limit)))->fetchAll();
 }
 public static function receipts(int $saleId):array {
  $q=MDA_Database::connection()->prepare('SELECT r.*,a.account_code,a.account_name,d.document_number AS accounting_document_number FROM mda_sales_receipts r JOIN mda_accounts a ON a.id=r.cash_account_id JOIN mda_documents d ON d.id=r.accounting_document_id WHERE r.sales_document_id=:id ORDER BY r.id');$q->execute(array(':id'=>$saleId));return$q->fetchAll();
 }
}

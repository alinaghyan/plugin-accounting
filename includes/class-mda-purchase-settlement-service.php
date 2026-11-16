<?php
defined( 'ABSPATH' ) || exit;

/** Append-only supplier settlements for posted purchase documents. */
final class MDA_Purchase_Settlement_Service {
 public static function record_payment(int $purchaseId,int $amountMinor,int $cashAccountId,string $method,string $idempotencyKey,?string $paymentDate=null): array {
  if($purchaseId<1||$amountMinor<1||$cashAccountId<1||!in_array($method,array('cash','bank','pos','transfer'),true)||strlen($idempotencyKey)<8||strlen($idempotencyKey)>191) throw new InvalidArgumentException('اطلاعات پرداخت معتبر نیست.');
  $date=$paymentDate?:current_time('Y-m-d');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
  if(!$parsed||$parsed->format('Y-m-d')!==$date) throw new InvalidArgumentException('تاریخ پرداخت معتبر نیست.');
  $now=gmdate('Y-m-d H:i:s');$hash=hash('sha256',wp_json_encode(array($purchaseId,$amountMinor,$cashAccountId,$method,$date)));
  return MDA_Database::transaction(static function(PDO $db) use($purchaseId,$amountMinor,$cashAccountId,$method,$idempotencyKey,$date,$now,$hash):array {
   $old=$db->prepare('SELECT id,purchase_id,amount_minor,cash_account_id,payment_method,paid_at FROM mda_purchase_payments WHERE idempotency_key=:key');$old->execute(array(':key'=>$idempotencyKey));$existing=$old->fetch();
   if($existing){$same=(int)$existing['purchase_id']===$purchaseId&&(int)$existing['amount_minor']===$amountMinor&&(int)$existing['cash_account_id']===$cashAccountId&&$existing['payment_method']===$method&&substr((string)$existing['paid_at'],0,10)===$date;if(!$same)throw new RuntimeException('کلید یکتا برای پرداخت متفاوتی استفاده شده است.');return array('payment_id'=>(int)$existing['id'],'duplicate'=>true);}
   $q=$db->prepare("SELECT * FROM mda_purchase_documents WHERE id=:id AND document_status='posted'");$q->execute(array(':id'=>$purchaseId));$purchase=$q->fetch();if(!$purchase)throw new RuntimeException('فاکتور قطعی خرید پیدا نشد.');
   $closed=$db->prepare("SELECT 1 FROM mda_financial_periods WHERE status='closed' AND :date BETWEEN starts_on AND ends_on");$closed->execute(array(':date'=>$date));if($closed->fetchColumn())throw new RuntimeException('ثبت پرداخت در دوره مالی بسته‌شده مجاز نیست.');
   $party=$db->prepare("SELECT 1 FROM mda_parties p JOIN mda_party_roles r ON r.party_id=p.id AND r.role_key='supplier' WHERE p.id=:id AND p.is_active=1 AND p.archived_at IS NULL");$party->execute(array(':id'=>$purchase['supplier_party_id']));if(!$party->fetchColumn())throw new RuntimeException('تأمین‌کننده فاکتور معتبر یا فعال نیست.');
   $paid=$db->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM mda_purchase_payments WHERE purchase_id=:id');$paid->execute(array(':id'=>$purchaseId));$paidTotal=(int)$paid->fetchColumn();
   if($amountMinor>(int)$purchase['total_minor']-$paidTotal)throw new InvalidArgumentException('مجموع پرداخت‌ها نمی‌تواند از مبلغ فاکتور بیشتر شود.');
   $cash=$db->prepare("SELECT id FROM mda_accounts WHERE id=:id AND account_type='asset' AND is_active=1 AND archived_at IS NULL AND is_control=0");$cash->execute(array(':id'=>$cashAccountId));if(!$cash->fetchColumn())throw new InvalidArgumentException('حساب انتخاب‌شده صندوق/بانک فعال نیست.');
   $ap=$db->prepare("SELECT id FROM mda_accounts WHERE account_code='2100' AND is_active=1 AND archived_at IS NULL AND is_control=1");$ap->execute();$apId=(int)$ap->fetchColumn();if(!$apId)throw new RuntimeException('حساب پرداختنی تأمین‌کنندگان تنظیم نشده است.');
   $mapping=$db->prepare("SELECT 1 FROM mda_party_control_accounts WHERE role_key='supplier' AND account_id=:id");$mapping->execute(array(':id'=>$apId));if(!$mapping->fetchColumn())throw new RuntimeException('حساب پرداختنی به تفصیلی تأمین‌کنندگان متصل نیست.');
   $number='MDA-PAY-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
   $doc=$db->prepare("INSERT INTO mda_documents (document_number,document_type,document_status,party_id,source_type,source_id,idempotency_key,payload_hash,currency_code,amount_scale,document_date,description,created_by,created_at,posted_at) VALUES (:number,'supplier_payment','posted',:party,'purchase_payment',:source,:key,:hash,:currency,1,:date,:description,:user,:now,:now)");
   $doc->execute(array(':number'=>$number,':party'=>$purchase['supplier_party_id'],':source'=>(string)$purchaseId,':key'=>'purchase-payment-journal-'.$idempotencyKey,':hash'=>$hash,':currency'=>$purchase['currency_code'],':date'=>$date,':description'=>'پرداخت مرحله‌ای فاکتور '.$purchase['document_number'],':user'=>get_current_user_id()?:null,':now'=>$now));$journalId=(int)$db->lastInsertId();
   $entry=$db->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,party_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,:n,:account,:party,:debit,:credit,:memo,:now)');
   $entry->execute(array(':doc'=>$journalId,':n'=>1,':account'=>$apId,':party'=>$purchase['supplier_party_id'],':debit'=>$amountMinor,':credit'=>0,':memo'=>'کاهش بدهی تأمین‌کننده',':now'=>$now));
   $entry->execute(array(':doc'=>$journalId,':n'=>2,':account'=>$cashAccountId,':party'=>null,':debit'=>0,':credit'=>$amountMinor,':memo'=>'خروج وجه بابت پرداخت خرید',':now'=>$now));
   $ins=$db->prepare('INSERT INTO mda_purchase_payments (purchase_id,amount_minor,cash_account_id,payment_method,idempotency_key,paid_at,created_at,accounting_document_id) VALUES (:purchase,:amount,:cash,:method,:key,:paid,:now,:journal)');
   $ins->execute(array(':purchase'=>$purchaseId,':amount'=>$amountMinor,':cash'=>$cashAccountId,':method'=>$method,':key'=>$idempotencyKey,':paid'=>$date.' '.substr($now,11),':now'=>$now,':journal'=>$journalId));$paymentId=(int)$db->lastInsertId();
   $audit=$db->prepare('INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,:action,:type,:id,:json,:now)');
   $audit->execute(array(':user'=>get_current_user_id()?:null,':action'=>'purchase_payment_recorded',':type'=>'purchase_payment',':id'=>(string)$paymentId,':json'=>wp_json_encode(array('purchase_id'=>$purchaseId,'amount_minor'=>$amountMinor,'journal_id'=>$journalId)),':now'=>$now));
   return array('payment_id'=>$paymentId,'document_id'=>$journalId,'duplicate'=>false,'paid_minor'=>$paidTotal+$amountMinor,'payable_minor'=>(int)$purchase['total_minor']-$paidTotal-$amountMinor);
  });
 }
 public static function summary(int $purchaseId): array {
  $db=MDA_Database::connection();$q=$db->prepare('SELECT total_minor FROM mda_purchase_documents WHERE id=:id');$q->execute(array(':id'=>$purchaseId));$total=$q->fetchColumn();if(false===$total)throw new RuntimeException('فاکتور پیدا نشد.');
  $p=$db->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM mda_purchase_payments WHERE purchase_id=:id');$p->execute(array(':id'=>$purchaseId));$paid=(int)$p->fetchColumn();
  return array('total_minor'=>(int)$total,'paid_minor'=>$paid,'payable_minor'=>(int)$total-$paid);
 }
 public static function payments(int $purchaseId): array {$q=MDA_Database::connection()->prepare('SELECT p.*,a.account_code,a.account_name,d.document_number AS accounting_document_number FROM mda_purchase_payments p JOIN mda_accounts a ON a.id=p.cash_account_id LEFT JOIN mda_documents d ON d.id=p.accounting_document_id WHERE p.purchase_id=:id ORDER BY p.id');$q->execute(array(':id'=>$purchaseId));return $q->fetchAll();}
}

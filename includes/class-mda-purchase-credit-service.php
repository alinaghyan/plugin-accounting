<?php
defined( 'ABSPATH' ) || exit;

/**
 * Supplier credit notes confirm the value already posted by a purchase return.
 * A credit note is not journaled a second time: the return has already debited AP.
 * Actual cash refunds are separate balanced, append-only journal documents.
 */
final class MDA_Purchase_Credit_Service {
 public static function record_credit_note(int $returnId,string $supplierReference,int $amountMinor,string $idempotencyKey,?string $documentDate=null):array {
  $supplierReference=trim($supplierReference);
  if($returnId<1||$amountMinor<1||$supplierReference===''||strlen($supplierReference)>100||strlen($idempotencyKey)<8||strlen($idempotencyKey)>191)throw new InvalidArgumentException('اطلاعات اعتبارنامه تأمین‌کننده معتبر نیست.');
  $date=$documentDate?:current_time('Y-m-d');self::validateDate($date);
  $hash=hash('sha256',wp_json_encode(array($returnId,$supplierReference,$amountMinor,$date)));$now=gmdate('Y-m-d H:i:s');
  return MDA_Database::transaction(static function(PDO $db)use($returnId,$supplierReference,$amountMinor,$idempotencyKey,$date,$hash,$now):array {
   $old=$db->prepare('SELECT id,payload_hash,amount_minor FROM mda_supplier_credit_notes WHERE idempotency_key=:key');$old->execute(array(':key'=>$idempotencyKey));$existing=$old->fetch();
   if($existing){if(!hash_equals((string)$existing['payload_hash'],$hash))throw new RuntimeException('کلید یکتا برای اعتبارنامه دیگری استفاده شده است.');return array('credit_note_id'=>(int)$existing['id'],'amount_minor'=>(int)$existing['amount_minor'],'duplicate'=>true);}
   $q=$db->prepare("SELECT r.*,p.document_number FROM mda_purchase_returns r JOIN mda_purchase_documents p ON p.id=r.purchase_id WHERE r.id=:id AND r.status='posted'");$q->execute(array(':id'=>$returnId));$ret=$q->fetch();if(!$ret)throw new RuntimeException('مرجوعی قطعی خرید پیدا نشد.');
   $closed=$db->prepare("SELECT 1 FROM mda_financial_periods WHERE status='closed' AND :date BETWEEN starts_on AND ends_on");$closed->execute(array(':date'=>$date));if($closed->fetchColumn())throw new RuntimeException('ثبت اعتبارنامه در دوره مالی بسته‌شده مجاز نیست.');
   $party=$db->prepare("SELECT 1 FROM mda_parties p JOIN mda_party_roles r ON r.party_id=p.id AND r.role_key='supplier' WHERE p.id=:id AND p.is_active=1 AND p.archived_at IS NULL");$party->execute(array(':id'=>$ret['supplier_party_id']));if(!$party->fetchColumn())throw new RuntimeException('تأمین‌کننده مرجوعی معتبر یا فعال نیست.');
   if($amountMinor!==(int)$ret['total_minor'])throw new InvalidArgumentException('مبلغ اعتبارنامه باید دقیقاً با ارزش دفتری مرجوعی برابر باشد؛ اختلاف قیمت نیازمند سند تعدیل مستقل است.');
   $ins=$db->prepare('INSERT INTO mda_supplier_credit_notes (return_id,purchase_id,supplier_party_id,credit_note_number,amount_minor,currency_code,document_date,idempotency_key,payload_hash,created_by,created_at) VALUES (:return,:purchase,:supplier,:reference,:amount,:currency,:date,:key,:hash,:user,:now)');
   $ins->execute(array(':return'=>$returnId,':purchase'=>$ret['purchase_id'],':supplier'=>$ret['supplier_party_id'],':reference'=>$supplierReference,':amount'=>$amountMinor,':currency'=>$ret['currency_code'],':date'=>$date,':key'=>$idempotencyKey,':hash'=>$hash,':user'=>get_current_user_id()?:null,':now'=>$now));$id=(int)$db->lastInsertId();
   $audit=$db->prepare('INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,:action,:type,:id,:json,:now)');
   $audit->execute(array(':user'=>get_current_user_id()?:null,':action'=>'supplier_credit_note_confirmed',':type'=>'supplier_credit_note',':id'=>(string)$id,':json'=>wp_json_encode(array('return_id'=>$returnId,'amount_minor'=>$amountMinor,'supplier_reference'=>$supplierReference)),':now'=>$now));
   return array('credit_note_id'=>$id,'amount_minor'=>$amountMinor,'duplicate'=>false);
  });
 }
 public static function record_refund(int $creditNoteId,int $amountMinor,int $cashAccountId,string $method,string $idempotencyKey,?string $paymentDate=null):array {
  if($creditNoteId<1||$amountMinor<1||$cashAccountId<1||!in_array($method,array('cash','bank','pos','transfer'),true)||strlen($idempotencyKey)<8||strlen($idempotencyKey)>191)throw new InvalidArgumentException('اطلاعات استرداد وجه تأمین‌کننده معتبر نیست.');
  $date=$paymentDate?:current_time('Y-m-d');self::validateDate($date);$now=gmdate('Y-m-d H:i:s');
  $hash=hash('sha256',wp_json_encode(array($creditNoteId,$amountMinor,$cashAccountId,$method,$date)));
  return MDA_Database::transaction(static function(PDO $db)use($creditNoteId,$amountMinor,$cashAccountId,$method,$idempotencyKey,$date,$now,$hash):array {
   $old=$db->prepare('SELECT id,payload_hash,amount_minor FROM mda_purchase_refunds WHERE idempotency_key=:key');$old->execute(array(':key'=>$idempotencyKey));$existing=$old->fetch();
   if($existing){if(!hash_equals((string)$existing['payload_hash'],$hash))throw new RuntimeException('کلید یکتا برای استرداد متفاوتی استفاده شده است.');return array('refund_id'=>(int)$existing['id'],'amount_minor'=>(int)$existing['amount_minor'],'duplicate'=>true);}
   $q=$db->prepare('SELECT c.*,r.return_number,d.document_number FROM mda_supplier_credit_notes c JOIN mda_purchase_returns r ON r.id=c.return_id JOIN mda_purchase_documents d ON d.id=c.purchase_id WHERE c.id=:id');$q->execute(array(':id'=>$creditNoteId));$note=$q->fetch();if(!$note)throw new RuntimeException('اعتبارنامه تأییدشده تأمین‌کننده پیدا نشد.');
   $closed=$db->prepare("SELECT 1 FROM mda_financial_periods WHERE status='closed' AND :date BETWEEN starts_on AND ends_on");$closed->execute(array(':date'=>$date));if($closed->fetchColumn())throw new RuntimeException('استرداد وجه در دوره مالی بسته‌شده مجاز نیست.');
   $party=$db->prepare("SELECT 1 FROM mda_parties p JOIN mda_party_roles r ON r.party_id=p.id AND r.role_key='supplier' WHERE p.id=:id AND p.is_active=1 AND p.archived_at IS NULL");$party->execute(array(':id'=>$note['supplier_party_id']));if(!$party->fetchColumn())throw new RuntimeException('تأمین‌کننده اعتبارنامه معتبر یا فعال نیست.');
   $cash=$db->prepare("SELECT id FROM mda_accounts WHERE id=:id AND account_code GLOB '11*' AND account_type='asset' AND is_active=1 AND archived_at IS NULL AND is_control=0 AND allow_manual_entry=1");$cash->execute(array(':id'=>$cashAccountId));if(!$cash->fetchColumn())throw new InvalidArgumentException('حساب مقصد باید صندوق یا بانک فعال و مجاز برای ثبت دستی باشد.');
   $refunded=$db->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM mda_purchase_refunds WHERE credit_note_id=:id');$refunded->execute(array(':id'=>$creditNoteId));$already=(int)$refunded->fetchColumn();
   if($amountMinor>(int)$note['amount_minor']-$already)throw new InvalidArgumentException('مبلغ استرداد از مانده اعتبارنامه تأمین‌کننده بیشتر است.');
   $ap=$db->prepare("SELECT id FROM mda_accounts WHERE account_code='2100' AND account_type='liability' AND is_active=1 AND archived_at IS NULL AND is_control=1");$ap->execute();$apId=(int)$ap->fetchColumn();if(!$apId)throw new RuntimeException('حساب پرداختنی تأمین‌کنندگان تنظیم نشده است.');
   $mapping=$db->prepare("SELECT 1 FROM mda_party_control_accounts WHERE role_key='supplier' AND account_id=:id");$mapping->execute(array(':id'=>$apId));if(!$mapping->fetchColumn())throw new RuntimeException('حساب پرداختنی به تفصیلی تأمین‌کنندگان متصل نیست.');
   $number='MDA-SRF-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
   $doc=$db->prepare("INSERT INTO mda_documents (document_number,document_type,document_status,party_id,source_type,source_id,idempotency_key,payload_hash,currency_code,amount_scale,document_date,description,created_by,created_at,posted_at) VALUES (:number,'supplier_refund','posted',:party,'supplier_refund',:source,:key,:hash,:currency,1,:date,:description,:user,:now,:now)");
   $doc->execute(array(':number'=>$number,':party'=>$note['supplier_party_id'],':source'=>(string)$creditNoteId,':key'=>'supplier-refund-journal-'.$idempotencyKey,':hash'=>$hash,':currency'=>$note['currency_code'],':date'=>$date,':description'=>'استرداد وجه اعتبارنامه تأمین‌کننده '.$note['credit_note_number'],':user'=>get_current_user_id()?:null,':now'=>$now));$journalId=(int)$db->lastInsertId();
   $entry=$db->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,party_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,:n,:account,:party,:debit,:credit,:memo,:now)');
   $entry->execute(array(':doc'=>$journalId,':n'=>1,':account'=>$cashAccountId,':party'=>null,':debit'=>$amountMinor,':credit'=>0,':memo'=>'دریافت وجه مستردشده از تأمین‌کننده',':now'=>$now));
   $entry->execute(array(':doc'=>$journalId,':n'=>2,':account'=>$apId,':party'=>$note['supplier_party_id'],':debit'=>0,':credit'=>$amountMinor,':memo'=>'تسویه بستانکاری اعتبارنامه تأمین‌کننده',':now'=>$now));
   $ins=$db->prepare('INSERT INTO mda_purchase_refunds (credit_note_id,return_id,purchase_id,supplier_party_id,amount_minor,cash_account_id,payment_method,payment_date,idempotency_key,payload_hash,accounting_document_id,created_by,created_at) VALUES (:note,:return,:purchase,:supplier,:amount,:cash,:method,:date,:key,:hash,:journal,:user,:now)');
   $ins->execute(array(':note'=>$creditNoteId,':return'=>$note['return_id'],':purchase'=>$note['purchase_id'],':supplier'=>$note['supplier_party_id'],':amount'=>$amountMinor,':cash'=>$cashAccountId,':method'=>$method,':date'=>$date,':key'=>$idempotencyKey,':hash'=>$hash,':journal'=>$journalId,':user'=>get_current_user_id()?:null,':now'=>$now));$refundId=(int)$db->lastInsertId();
   $audit=$db->prepare('INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,:action,:type,:id,:json,:now)');
   $audit->execute(array(':user'=>get_current_user_id()?:null,':action'=>'supplier_refund_recorded',':type'=>'supplier_refund',':id'=>(string)$refundId,':json'=>wp_json_encode(array('credit_note_id'=>$creditNoteId,'amount_minor'=>$amountMinor,'journal_id'=>$journalId)),':now'=>$now));
   return array('refund_id'=>$refundId,'document_id'=>$journalId,'duplicate'=>false,'refunded_minor'=>$already+$amountMinor,'remaining_minor'=>(int)$note['amount_minor']-$already-$amountMinor);
  });
 }
 public static function credit_notes_for_return(int $returnId):array {$q=MDA_Database::connection()->prepare('SELECT * FROM mda_supplier_credit_notes WHERE return_id=:id');$q->execute(array(':id'=>$returnId));return $q->fetchAll();}
 public static function refunds_for_credit_note(int $creditNoteId):array {$q=MDA_Database::connection()->prepare('SELECT * FROM mda_purchase_refunds WHERE credit_note_id=:id ORDER BY id');$q->execute(array(':id'=>$creditNoteId));return $q->fetchAll();}
 private static function validateDate(string $date):void {$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('تاریخ معتبر نیست.');}
}

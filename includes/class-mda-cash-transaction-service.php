<?php
defined( 'ABSPATH' ) || exit;

/** Immutable cash/bank receipts, operating payments and account transfers. */
final class MDA_Cash_Transaction_Service {
 public static function record(array $input):array {
  $type=sanitize_key((string)($input['transaction_type']??''));
  $cashId=absint($input['cash_account_id']??0);$contraId=absint($input['contra_account_id']??0);$destinationId=absint($input['destination_cash_account_id']??0);
  $amount=$input['amount_minor']??null;$currency=strtoupper(sanitize_text_field((string)($input['currency_code']??'IRR')));
  $method=sanitize_key((string)($input['payment_method']??'cash'));$date=sanitize_text_field((string)($input['transaction_date']??''));
  $key=trim((string)($input['idempotency_key']??''));$reference=sanitize_text_field((string)($input['reference_number']??''));$description=sanitize_textarea_field((string)($input['description']??''));
  $validTypes=array('receipt','payment','transfer');$validMethods=array('cash','bank','pos','transfer','cheque');
  $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
  if(!in_array($type,$validTypes,true)||$cashId<1||!is_int($amount)||$amount<1||!in_array($currency,array('IRR','IRT'),true)||!in_array($method,$validMethods,true)||!$parsed||$parsed->format('Y-m-d')!==$date||strlen($key)<12||strlen($key)>191||$description==='')throw new InvalidArgumentException('اطلاعات تراکنش صندوق/بانک معتبر نیست.');
  if($type==='transfer'){if($destinationId<1||$destinationId===$cashId||$contraId!==0)throw new InvalidArgumentException('حساب مبدأ و مقصد انتقال باید متفاوت و معتبر باشند.');$method='transfer';}
  else{if($contraId<1||$destinationId!==0)throw new InvalidArgumentException('حساب مقابل تراکنش معتبر نیست.');}
  $payload=array('type'=>$type,'cash_account_id'=>$cashId,'contra_account_id'=>$contraId,'destination_cash_account_id'=>$destinationId,'amount_minor'=>$amount,'currency_code'=>$currency,'payment_method'=>$method,'transaction_date'=>$date,'reference_number'=>$reference,'description'=>$description);
  $hash=hash('sha256',wp_json_encode($payload));$now=gmdate('Y-m-d H:i:s');
  return MDA_Database::transaction(static function(PDO $db)use($type,$cashId,$contraId,$destinationId,$amount,$currency,$method,$date,$key,$reference,$description,$hash,$now):array{
   $old=$db->prepare('SELECT id,payload_hash,accounting_document_id FROM mda_cash_transactions WHERE idempotency_key=:key');$old->execute(array(':key'=>$key));$existing=$old->fetch();
   if($existing){if(!hash_equals((string)$existing['payload_hash'],$hash))throw new RuntimeException('کلید یکتا قبلاً برای تراکنش متفاوتی استفاده شده است.');return array('transaction_id'=>(int)$existing['id'],'accounting_document_id'=>(int)$existing['accounting_document_id'],'duplicate'=>true);}
   $closed=$db->prepare("SELECT 1 FROM mda_financial_periods WHERE status='closed' AND :date BETWEEN starts_on AND ends_on LIMIT 1");$closed->execute(array(':date'=>$date));if($closed->fetchColumn())throw new RuntimeException('ثبت تراکنش در دوره مالی بسته‌شده مجاز نیست.');
   self::assertCashAccount($db,$cashId);
   if($type==='transfer')self::assertCashAccount($db,$destinationId);
   else self::assertContraAccount($db,$contraId,$type==='receipt'?'income':'expense');
   if($type==='payment'||$type==='transfer'){
    $balance=$db->prepare("SELECT COALESCE(SUM(j.debit_minor-j.credit_minor),0) FROM mda_journal_entries j JOIN mda_documents d ON d.id=j.document_id WHERE j.account_id=:account AND d.currency_code=:currency AND d.document_status='posted'");
    $balance->execute(array(':account'=>$cashId,':currency'=>$currency));$available=(int)$balance->fetchColumn();
    if($available<$amount)throw new RuntimeException('موجودی حساب مبدأ برای این پرداخت/انتقال کافی نیست.');
   }
   $docType=$type==='receipt'?'cash_receipt':($type==='payment'?'cash_payment':'cash_transfer');
   $number='MDA-CASH-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
   $doc=$db->prepare("INSERT INTO mda_documents (document_number,document_type,document_status,party_id,source_type,source_id,idempotency_key,payload_hash,currency_code,amount_scale,document_date,description,created_by,created_at,posted_at) VALUES (:number,:type,'posted',NULL,'cash_transaction',:source,:key,:hash,:currency,1,:date,:description,:user,:now,:now)");
   $doc->execute(array(':number'=>$number,':type'=>$docType,':source'=>$key,':key'=>'cash-journal-'.$key,':hash'=>$hash,':currency'=>$currency,':date'=>$date,':description'=>$description,':user'=>get_current_user_id()?:null,':now'=>$now));$journalId=(int)$db->lastInsertId();
   $lines=array();
   if($type==='receipt'){$lines[]=array($cashId,$amount,0,'افزایش موجودی صندوق/بانک');$lines[]=array($contraId,0,$amount,'ثبت درآمد دریافتی');}
   elseif($type==='payment'){$lines[]=array($contraId,$amount,0,'ثبت هزینه پرداخت‌شده');$lines[]=array($cashId,0,$amount,'کاهش موجودی صندوق/بانک');}
   else{$lines[]=array($destinationId,$amount,0,'افزایش حساب مقصد انتقال');$lines[]=array($cashId,0,$amount,'کاهش حساب مبدأ انتقال');}
   $entry=$db->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,party_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,:number,:account,NULL,:debit,:credit,:memo,:now)');
   foreach($lines as $i=>$line)$entry->execute(array(':doc'=>$journalId,':number'=>$i+1,':account'=>$line[0],':debit'=>$line[1],':credit'=>$line[2],':memo'=>$line[3],':now'=>$now));
   $insert=$db->prepare('INSERT INTO mda_cash_transactions (transaction_type,cash_account_id,contra_account_id,destination_cash_account_id,amount_minor,currency_code,payment_method,transaction_date,reference_number,description,idempotency_key,payload_hash,accounting_document_id,created_by,created_at) VALUES (:type,:cash,:contra,:destination,:amount,:currency,:method,:date,:reference,:description,:key,:hash,:journal,:user,:now)');
   $insert->execute(array(':type'=>$type,':cash'=>$cashId,':contra'=>$contraId?:null,':destination'=>$destinationId?:null,':amount'=>$amount,':currency'=>$currency,':method'=>$method,':date'=>$date,':reference'=>$reference?:null,':description'=>$description,':key'=>$key,':hash'=>$hash,':journal'=>$journalId,':user'=>get_current_user_id()?:null,':now'=>$now));$id=(int)$db->lastInsertId();
   $db->prepare("INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,'cash_transaction_posted','cash_transaction',:id,:json,:now)")->execute(array(':user'=>get_current_user_id()?:null,':id'=>(string)$id,':json'=>wp_json_encode($payload=array('type'=>$type,'cash_account_id'=>$cashId,'contra_account_id'=>$contraId,'destination_cash_account_id'=>$destinationId,'amount_minor'=>$amount,'currency_code'=>$currency,'method'=>$method)),':now'=>$now));
   return array('transaction_id'=>$id,'accounting_document_id'=>$journalId,'duplicate'=>false);
  });
 }
 private static function assertCashAccount(PDO $db,int $id):void {
  $q=$db->prepare("SELECT id FROM mda_accounts WHERE id=:id AND account_type='asset' AND is_control=0 AND allow_manual_entry=1 AND is_active=1 AND archived_at IS NULL LIMIT 1");$q->execute(array(':id'=>$id));if(!$q->fetchColumn())throw new InvalidArgumentException('حساب صندوق/بانک فعال و قابل ثبت انتخاب نشده است.');
 }
 private static function assertContraAccount(PDO $db,int $id,string $type):void {
  $q=$db->prepare('SELECT id FROM mda_accounts WHERE id=:id AND account_type=:type AND is_control=0 AND allow_manual_entry=1 AND is_active=1 AND archived_at IS NULL AND account_code<>\'5100\' LIMIT 1');$q->execute(array(':id'=>$id,':type'=>$type));if(!$q->fetchColumn())throw new InvalidArgumentException('حساب درآمد یا هزینه فعال و قابل ثبت انتخاب نشده است.');
 }
 public static function cash_accounts():array {
  return MDA_Database::connection()->query("SELECT id,account_code,account_name FROM mda_accounts WHERE account_type='asset' AND is_control=0 AND allow_manual_entry=1 AND is_active=1 AND archived_at IS NULL ORDER BY account_code")->fetchAll();
 }
 public static function contra_accounts(string $type):array {
  if(!in_array($type,array('income','expense'),true))throw new InvalidArgumentException('گروه حساب نامعتبر است.');
  $q=MDA_Database::connection()->prepare("SELECT id,account_code,account_name FROM mda_accounts WHERE account_type=:type AND is_control=0 AND allow_manual_entry=1 AND is_active=1 AND archived_at IS NULL AND account_code<>'5100' ORDER BY account_code");$q->execute(array(':type'=>$type));return$q->fetchAll();
 }
 public static function balances():array {
  return MDA_Database::connection()->query("SELECT a.id,a.account_code,a.account_name,COALESCE(d.currency_code,'IRR') currency_code,COALESCE(SUM(j.debit_minor),0) debit_minor,COALESCE(SUM(j.credit_minor),0) credit_minor,COALESCE(SUM(j.debit_minor-j.credit_minor),0) balance_minor FROM mda_accounts a LEFT JOIN mda_journal_entries j ON j.account_id=a.id LEFT JOIN mda_documents d ON d.id=j.document_id AND d.document_status='posted' WHERE a.account_type='asset' AND a.is_control=0 AND a.allow_manual_entry=1 AND a.is_active=1 AND a.archived_at IS NULL GROUP BY a.id,d.currency_code ORDER BY a.account_code,d.currency_code")->fetchAll();
 }
 public static function transactions(int $limit=100):array {
  $q=MDA_Database::connection()->prepare('SELECT t.*,a.account_code,a.account_name,c.account_code contra_code,c.account_name contra_name,d.account_code destination_code,d.account_name destination_name,j.document_number FROM mda_cash_transactions t JOIN mda_accounts a ON a.id=t.cash_account_id LEFT JOIN mda_accounts c ON c.id=t.contra_account_id LEFT JOIN mda_accounts d ON d.id=t.destination_cash_account_id JOIN mda_documents j ON j.id=t.accounting_document_id ORDER BY t.id DESC LIMIT :limit');$q->bindValue(':limit',max(1,min(500,$limit)),PDO::PARAM_INT);$q->execute();return$q->fetchAll();
 }
}

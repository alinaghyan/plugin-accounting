<?php
defined('ABSPATH') || exit;

/**
 * Lifecycle of received and issued cheques. Current state is derived from the
 * append-only event stream; cheque master data is immutable after creation.
 */
final class MDA_Cheque_Service {
 private const STATUSES=array('registered','deposited','cleared','bounced','voided');
 private static function date(string $date): bool {$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);return $d&&$d->format('Y-m-d')===$date;}
 private static function account(PDO $db,string $code,string $type): int {
  $q=$db->prepare('SELECT id FROM mda_accounts WHERE account_code=:code AND account_type=:type AND is_active=1 AND archived_at IS NULL');$q->execute(array(':code'=>$code,':type'=>$type));$id=(int)$q->fetchColumn();if(!$id)throw new RuntimeException('حساب سیستمی موردنیاز دفتر چک پیدا نشد: '.$code);return $id;
 }
 private static function bank(PDO $db,int $id): void {$q=$db->prepare("SELECT 1 FROM mda_accounts WHERE id=:id AND account_code GLOB '112*' AND account_type='asset' AND is_control=0 AND allow_manual_entry=1 AND is_active=1 AND archived_at IS NULL");$q->execute(array(':id'=>$id));if(!$q->fetchColumn())throw new InvalidArgumentException('حساب بانکی معتبر و فعال انتخاب نشده است.');}
 private static function party(PDO $db,int $id,string $role): void {$q=$db->prepare('SELECT 1 FROM mda_parties p JOIN mda_party_roles r ON r.party_id=p.id WHERE p.id=:id AND p.is_active=1 AND p.archived_at IS NULL AND r.role_key=:role');$q->execute(array(':id'=>$id,':role'=>$role));if(!$q->fetchColumn())throw new InvalidArgumentException('طرف‌حساب فعال با نقش لازم پیدا نشد.');}
 private static function state(PDO $db,int $id): array {$q=$db->prepare('SELECT * FROM mda_cheques WHERE id=:id');$q->execute(array(':id'=>$id));$c=$q->fetch();if(!$c)throw new InvalidArgumentException('چک پیدا نشد.');$e=$db->prepare('SELECT * FROM mda_cheque_events WHERE cheque_id=:id ORDER BY id DESC LIMIT 1');$e->execute(array(':id'=>$id));$event=$e->fetch();$c['status']=$event?(string)$event['status_after']:'registered';$c['last_event_id']=$event?(int)$event['id']:null;return $c;}
 private static function post(PDO $db,array $c,string $type,string $date,array $lines,string $key,string $description): int {
  $hash=hash('sha256',wp_json_encode(array($c['id'],$type,$date,$lines,$key)));$old=$db->prepare('SELECT id,payload_hash FROM mda_documents WHERE idempotency_key=:key');$old->execute(array(':key'=>$key));$found=$old->fetch();if($found){if(!hash_equals((string)$found['payload_hash'],$hash))throw new RuntimeException('کلید یکتای سند برای محتوای دیگری استفاده شده است.');return (int)$found['id'];}
  $closed=$db->prepare("SELECT 1 FROM mda_financial_periods WHERE status='closed' AND :date BETWEEN starts_on AND ends_on LIMIT 1");$closed->execute(array(':date'=>$date));if($closed->fetchColumn())throw new RuntimeException('ثبت سند در دوره مالی بسته‌شده مجاز نیست.');
  $debit=0;$credit=0;foreach($lines as $l){$debit+=$l[1];$credit+=$l[2];}if($debit<1||$debit!==$credit)throw new LogicException('سند دفتر چک تراز نیست.');
  foreach($lines as $l){$q=$db->prepare('SELECT is_control FROM mda_accounts WHERE id=:id AND is_active=1 AND archived_at IS NULL');$q->execute(array(':id'=>$l[0]));$a=$q->fetch();if(!$a)throw new RuntimeException('حساب سند نامعتبر است.');if((int)$a['is_control']===1){if(empty($l[3]))throw new RuntimeException('حساب کنترلی باید تفصیلی داشته باشد.');$q=$db->prepare('SELECT 1 FROM mda_party_control_accounts WHERE account_id=:account');$q->execute(array(':account'=>$l[0]));if(!$q->fetchColumn())throw new RuntimeException('حساب کنترلی با تفصیلی چک سازگار نیست.');}}
  $now=gmdate('Y-m-d H:i:s');$number='MDA-CHQ-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
  $q=$db->prepare("INSERT INTO mda_documents (document_number,document_type,document_status,party_id,source_type,source_id,idempotency_key,payload_hash,currency_code,amount_scale,document_date,description,created_by,created_at,posted_at) VALUES (:number,:type,'posted',:party,'cheque',:source,:key,:hash,:currency,1,:date,:description,:user,:now,:now)");
  $q->execute(array(':number'=>$number,':type'=>$type,':party'=>(int)$c['party_id'],':source'=>(string)$c['id'],':key'=>$key,':hash'=>$hash,':currency'=>$c['currency_code'],':date'=>$date,':description'=>$description,':user'=>get_current_user_id()?:null,':now'=>$now));$doc=(int)$db->lastInsertId();
  $q=$db->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,party_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,:num,:account,:party,:debit,:credit,:memo,:now)');
  foreach($lines as $i=>$l)$q->execute(array(':doc'=>$doc,':num'=>$i+1,':account'=>$l[0],':party'=>$l[3],':debit'=>$l[1],':credit'=>$l[2],':memo'=>$l[4],':now'=>$now));return $doc;
 }
 private static function event(PDO $db,array $c,string $action,string $after,?int $doc,string $key,string $note=''): int {
  $old=$db->prepare('SELECT id,action,status_after,accounting_document_id FROM mda_cheque_events WHERE idempotency_key=:key');$old->execute(array(':key'=>$key));$found=$old->fetch();if($found){if($found['action']!==$action||$found['status_after']!==$after||(int)($found['accounting_document_id']??0)!==(int)($doc??0))throw new RuntimeException('کلید یکتای رویداد برای عملیات دیگری استفاده شده است.');return (int)$found['id'];}
  $q=$db->prepare('INSERT INTO mda_cheque_events (cheque_id,action,status_after,accounting_document_id,previous_event_id,idempotency_key,note,created_by,created_at) VALUES (:cheque,:action,:status,:doc,:previous,:key,:note,:user,:now)');
  $q->execute(array(':cheque'=>$c['id'],':action'=>$action,':status'=>$after,':doc'=>$doc,':previous'=>$c['last_event_id'],':key'=>$key,':note'=>$note?:null,':user'=>get_current_user_id()?:null,':now'=>gmdate('Y-m-d H:i:s')));return (int)$db->lastInsertId();
 }
 public static function register(array $in): array {
  $kind=sanitize_key((string)($in['cheque_type']??''));$party=(int)($in['party_id']??0);$bank=(int)($in['bank_account_id']??0);$amount=(int)($in['amount_minor']??0);$currency=strtoupper((string)($in['currency_code']??'IRR'));$number=sanitize_text_field((string)($in['cheque_number']??''));$date=(string)($in['due_date']??'');$issue=(string)($in['issue_date']??'');$key=trim((string)($in['request_key']??''));$description=sanitize_text_field((string)($in['description']??''));
  if(!in_array($kind,array('received','issued'),true)||$party<1||$bank<1||$amount<1||!in_array($currency,array('IRR','IRT'),true)||$number===''||strlen($number)>80||!self::date($date)||!self::date($issue)||$date<$issue||strlen($key)<12||strlen($key)>191)throw new InvalidArgumentException('اطلاعات چک معتبر نیست.');
  $role=$kind==='received'?'customer':'supplier';$now=gmdate('Y-m-d H:i:s');
  return MDA_Database::transaction(static function(PDO $db)use($kind,$party,$bank,$amount,$currency,$number,$date,$issue,$key,$description,$role,$now):array{
   $old=$db->prepare('SELECT id,payload_hash FROM mda_cheques WHERE idempotency_key=:key');$old->execute(array(':key'=>$key));$found=$old->fetch();$hash=hash('sha256',wp_json_encode(array($kind,$party,$bank,$amount,$currency,$number,$date,$issue,$description)));if($found){if(!hash_equals((string)$found['payload_hash'],$hash))throw new RuntimeException('کلید یکتا برای چک دیگری استفاده شده است.');return array('cheque_id'=>(int)$found['id'],'duplicate'=>true);}
   self::party($db,$party,$role);self::bank($db,$bank);
   $dup=$db->prepare('SELECT 1 FROM mda_cheques WHERE bank_account_id=:bank AND cheque_number=:number AND cheque_type=:type');$dup->execute(array(':bank'=>$bank,':number'=>$number,':type'=>$kind));if($dup->fetchColumn())throw new RuntimeException('این شماره چک برای همین بانک و نوع قبلاً ثبت شده است.');
   $q=$db->prepare('INSERT INTO mda_cheques (cheque_type,party_id,bank_account_id,cheque_number,amount_minor,currency_code,issue_date,due_date,description,idempotency_key,payload_hash,created_by,created_at) VALUES (:type,:party,:bank,:number,:amount,:currency,:issue,:due,:description,:key,:hash,:user,:now)');
   $q->execute(array(':type'=>$kind,':party'=>$party,':bank'=>$bank,':number'=>$number,':amount'=>$amount,':currency'=>$currency,':issue'=>$issue,':due'=>$date,':description'=>$description?:null,':key'=>$key,':hash'=>$hash,':user'=>get_current_user_id()?:null,':now'=>$now));$id=(int)$db->lastInsertId();$c=array('id'=>$id,'party_id'=>$party,'cheque_type'=>$kind,'last_event_id'=>null);
   self::event($db,$c,'registered','registered',null,'cheque-register-'.$key,$description);$db->prepare("INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,'cheque.registered','cheque',:id,:json,:now)")->execute(array(':user'=>get_current_user_id()?:null,':id'=>(string)$id,':json'=>wp_json_encode(array('type'=>$kind,'amount_minor'=>$amount,'currency'=>$currency,'due_date'=>$date)),':now'=>$now));return array('cheque_id'=>$id,'duplicate'=>false);
  });
 }
 public static function transition(int $id,string $action,string $date,string $key,string $note=''): array {
  $action=sanitize_key($action);$key=trim($key);$note=sanitize_text_field($note);if($id<1||!in_array($action,array('deposit','clear','bounce','void'),true)||!self::date($date)||strlen($key)<12||strlen($key)>191)throw new InvalidArgumentException('اطلاعات تغییر وضعیت چک معتبر نیست.');
  return MDA_Database::transaction(static function(PDO $db)use($id,$action,$date,$key,$note):array{
   $c=self::state($db,$id);$before=$c['status'];$kind=$c['cheque_type'];if(in_array($before,array('cleared','bounced','voided'),true))throw new RuntimeException('چک در وضعیت نهایی است و تغییر وضعیت دیگری مجاز نیست.');
   $bank=(int)$c['bank_account_id'];$amount=(int)$c['amount_minor'];$party=(int)$c['party_id'];$currency=$c['currency_code'];$doc=null;$after=$before;
   $holding=self::account($db,'1130','asset');$payableCheque=self::account($db,'2130','liability');$partyAccount=self::account($db,$kind==='received'?'1200':'2100',$kind==='received'?'asset':'liability');
   if($action==='deposit'){if($kind!=='received'||$before!=='registered')throw new RuntimeException('فقط چک دریافتی ثبت‌شده قابل واگذاری به بانک است.');$after='deposited';}
   elseif($action==='clear'){
    if($kind==='received'&&!in_array($before,array('registered','deposited'),true))throw new RuntimeException('وضعیت چک دریافتی برای وصول معتبر نیست.');
    if($kind==='issued'&&$before!=='registered')throw new RuntimeException('وضعیت چک صادره برای وصول معتبر نیست.');
    self::bank($db,$bank);
    if($kind==='received')$lines=array(array($bank,$amount,0,null,'وصول چک دریافتی'),array($holding,0,$amount,null,'خروج از چک‌های دریافتنی'));
    else {$q=$db->prepare("SELECT COALESCE(SUM(j.debit_minor-j.credit_minor),0) FROM mda_journal_entries j JOIN mda_documents d ON d.id=j.document_id WHERE j.account_id=:bank AND d.currency_code=:currency AND d.document_status='posted'");$q->execute(array(':bank'=>$bank,':currency'=>$currency));if((int)$q->fetchColumn()<$amount)throw new RuntimeException('مانده حساب بانکی برای وصول چک صادره کافی نیست.');$lines=array(array($payableCheque,$amount,0,null,'تسویه چک پرداختنی'),array($bank,0,$amount,null,'برداشت بابت چک صادره'));}
    $doc=self::post($db,$c,'cheque_'.$kind.'_cleared',$date,$lines,'cheque-'.$id.'-clear-'.$key,'وصول چک شماره '.$c['cheque_number']);$after='cleared';
   }
   elseif($action==='bounce'){
    if(!in_array($before,array('registered','deposited','cleared'),true))throw new RuntimeException('وضعیت فعلی چک قابل برگشت نیست.');
    if($kind==='received'){$creditAccount=$before==='cleared'?$bank:$holding;$lines=array(array($partyAccount,$amount,0,$party,'بازگشت مطالبات مشتری بابت چک برگشتی'),array($creditAccount,0,$amount,null,'برگشت چک دریافتی'));}
    else{$debitAccount=$before==='cleared'?$bank:$payableCheque;$lines=array(array($debitAccount,$amount,0,null,'اصلاح چک صادره برگشتی'),array($partyAccount,0,$amount,$party,'بازگشت بدهی تأمین‌کننده'));}
    $doc=self::post($db,$c,'cheque_'.$kind.'_bounced',$date,$lines,'cheque-'.$id.'-bounce-'.$key,'برگشت چک شماره '.$c['cheque_number']);$after='bounced';
   } else {
    if($before!=='registered')throw new RuntimeException('فقط چک ثبت‌شده و واگذار‌نشده قابل ابطال است.');
    if($kind==='received')$lines=array(array($partyAccount,$amount,0,$party,'ابطال چک دریافتی'),array($holding,0,$amount,null,'خروج چک از اسناد دریافتنی'));
    else $lines=array(array($payableCheque,$amount,0,null,'ابطال چک پرداختنی'),array($partyAccount,0,$amount,$party,'بازگشت بدهی تأمین‌کننده'));
    $doc=self::post($db,$c,'cheque_'.$kind.'_voided',$date,$lines,'cheque-'.$id.'-void-'.$key,'ابطال چک شماره '.$c['cheque_number']);$after='voided';
   }
   $event=self::event($db,$c,$action,$after,$doc,'cheque-'.$id.'-'.$action.'-'.$key,$note);$db->prepare("INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,:action,'cheque',:id,:json,:now)")->execute(array(':user'=>get_current_user_id()?:null,':action'=>'cheque.'.$action,':id'=>(string)$id,':json'=>wp_json_encode(array('before'=>$before,'after'=>$after,'document_id'=>$doc,'event_id'=>$event)),':now'=>gmdate('Y-m-d H:i:s')));return array('cheque_id'=>$id,'status'=>$after,'document_id'=>$doc,'event_id'=>$event);
  });
 }
 public static function list(array $filters=array(),int $limit=300): array {
  $where=array();$params=array();if(!empty($filters['type'])&&in_array($filters['type'],array('received','issued'),true)){$where[]='c.cheque_type=:type';$params[':type']=$filters['type'];}
  $sql="SELECT c.*,p.display_name AS party_name,a.account_code AS bank_code,a.account_name AS bank_name,(SELECT e.status_after FROM mda_cheque_events e WHERE e.cheque_id=c.id ORDER BY e.id DESC LIMIT 1) AS status FROM mda_cheques c JOIN mda_parties p ON p.id=c.party_id JOIN mda_accounts a ON a.id=c.bank_account_id".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY c.due_date ASC,c.id DESC LIMIT ".max(1,min(1000,$limit));$q=MDA_Database::connection()->prepare($sql);$q->execute($params);return $q->fetchAll();
 }
 public static function due(int $days=7): array {$days=max(0,min(90,$days));$to=(new DateTimeImmutable('today'))->modify('+'.$days.' days')->format('Y-m-d');$q=MDA_Database::connection()->prepare("SELECT c.*,p.display_name AS party_name,a.account_name AS bank_name,(SELECT e.status_after FROM mda_cheque_events e WHERE e.cheque_id=c.id ORDER BY e.id DESC LIMIT 1) AS status FROM mda_cheques c JOIN mda_parties p ON p.id=c.party_id JOIN mda_accounts a ON a.id=c.bank_account_id WHERE c.due_date<=:to AND (SELECT e.status_after FROM mda_cheque_events e WHERE e.cheque_id=c.id ORDER BY e.id DESC LIMIT 1) IN ('registered','deposited') ORDER BY c.due_date");$q->execute(array(':to'=>$to));return $q->fetchAll();}
}

<?php
defined( 'ABSPATH' ) || exit;

/** Manual CSV bank-statement import and append-only matching against SQLite journals. */
final class MDA_Bank_Reconciliation_Service {
 private static function digits(string $value):string {
  return strtr(trim($value),array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٬'=>'',','=>''));
 }
 private static function validDate(string $date):bool {$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);return $d&&$d->format('Y-m-d')===$date;}
 private static function safeAdd(int $a,int $b):int {if($b>0&&$a>PHP_INT_MAX-$b)throw new OverflowException('جمع گردش صورت‌حساب بیش از ظرفیت عدد صحیح است.');if($b<0&&$a<PHP_INT_MIN-$b)throw new OverflowException('جمع گردش صورت‌حساب بیش از ظرفیت عدد صحیح است.');return$a+$b;}
 public static function import_csv(int $accountId,string $currency,string $reference,int $opening,int $closing,string $csv,string $key):array {
  $currency=strtoupper($currency);$reference=sanitize_text_field($reference);$key=trim($key);
  if($accountId<1||!in_array($currency,array('IRR','IRT'),true)||$reference===''||strlen($reference)>120||strlen($key)<12||strlen($key)>191||trim($csv)===''||strlen($csv)>1048576)throw new InvalidArgumentException('اطلاعات صورت‌حساب بانکی معتبر نیست.');
  $rows=array();$credits=0;$debits=0;$dates=array();$rawLines=preg_split('/\r\n|\r|\n/',trim($csv));$delimiter=substr_count((string)($rawLines[0]??''),';')>substr_count((string)($rawLines[0]??''),',')?';':(substr_count((string)($rawLines[0]??''),"\t")>substr_count((string)($rawLines[0]??''),',')?"\t":',');
  foreach($rawLines as $index=>$raw){if(trim($raw)==='')continue;$cols=str_getcsv($raw,$delimiter);if($index===0&&isset($cols[0])&&in_array(strtolower(trim((string)$cols[0])),array('date','تاریخ'),true))continue;if(count($cols)<3||count($cols)>5)throw new InvalidArgumentException('هر ردیف CSV باید شامل تاریخ، نوع گردش، مبلغ، مرجع و شرح باشد.');
   $date=self::digits((string)$cols[0]);$direction=strtolower(trim((string)$cols[1]));$direction=in_array($direction,array('واریز','بستانکار','credit','cr','کredit'),true)?'credit':(in_array($direction,array('برداشت','بدهکار','debit','dr'),true)?'debit':'');
   $amountText=self::digits((string)$cols[2]);$amount=filter_var($amountText,FILTER_VALIDATE_INT);
   if(!self::validDate($date)||$direction===''||false===$amount||$amount<1)throw new InvalidArgumentException('تاریخ، نوع گردش یا مبلغ یکی از ردیف‌های CSV معتبر نیست.');
   $row=array('transaction_date'=>$date,'direction'=>$direction,'amount_minor'=>(int)$amount,'reference_number'=>sanitize_text_field((string)($cols[3]??'')),'description'=>sanitize_text_field((string)($cols[4]??'')));
   if($row['description']==='')$row['description']='گردش بانکی';
   $rows[]=$row;$dates[]=$date;
   if($direction==='credit')$credits=self::safeAdd($credits,(int)$amount);else$debits=self::safeAdd($debits,(int)$amount);
   if(count($rows)>2000)throw new InvalidArgumentException('حداکثر ۲۰۰۰ ردیف در هر صورت‌حساب قابل ورود است.');
  }
  if(!$rows)throw new InvalidArgumentException('صورت‌حساب هیچ ردیف معتبری ندارد.');
  $calculated=self::safeAdd(self::safeAdd($opening,$credits),-$debits);if($calculated!==$closing)throw new InvalidArgumentException('مانده پایانی با مانده اول دوره و جمع واریز/برداشت‌ها برابر نیست.');
  sort($dates);$start=$dates[0];$end=$dates[count($dates)-1];$payloadHash=hash('sha256',wp_json_encode(array($accountId,$currency,$reference,$opening,$closing,$rows)));$now=gmdate('Y-m-d H:i:s');
  return MDA_Database::transaction(static function(PDO $db)use($accountId,$currency,$reference,$opening,$closing,$rows,$key,$payloadHash,$start,$end,$now):array{
   $account=$db->prepare("SELECT id FROM mda_accounts WHERE id=:id AND account_code GLOB '11*' AND account_type='asset' AND is_control=0 AND allow_manual_entry=1 AND is_active=1 AND archived_at IS NULL");$account->execute(array(':id'=>$accountId));if(!$account->fetchColumn())throw new InvalidArgumentException('حساب بانکی انتخاب‌شده معتبر یا فعال نیست.');
   $old=$db->prepare('SELECT id,payload_hash FROM mda_bank_statement_imports WHERE idempotency_key=:key');$old->execute(array(':key'=>$key));$existing=$old->fetch();
   if($existing){if(!hash_equals((string)$existing['payload_hash'],$payloadHash))throw new RuntimeException('کلید یکتا برای صورت‌حساب متفاوتی استفاده شده است.');return array('import_id'=>(int)$existing['id'],'duplicate'=>true,'line_count'=>count($rows));}
   $same=$db->prepare('SELECT id,payload_hash FROM mda_bank_statement_imports WHERE bank_account_id=:account AND currency_code=:currency AND statement_reference=:reference');$same->execute(array(':account'=>$accountId,':currency'=>$currency,':reference'=>$reference));$sameRow=$same->fetch();
   if($sameRow){if(hash_equals((string)$sameRow['payload_hash'],$payloadHash))return array('import_id'=>(int)$sameRow['id'],'duplicate'=>true,'line_count'=>count($rows));throw new RuntimeException('این شماره صورت‌حساب برای این حساب قبلاً با محتوای دیگری وارد شده است.');}
   $insert=$db->prepare('INSERT INTO mda_bank_statement_imports (bank_account_id,currency_code,statement_reference,period_start,period_end,opening_balance_minor,closing_balance_minor,line_count,idempotency_key,payload_hash,created_by,created_at) VALUES (:account,:currency,:reference,:start,:end,:opening,:closing,:count,:key,:hash,:user,:now)');
   $insert->execute(array(':account'=>$accountId,':currency'=>$currency,':reference'=>$reference,':start'=>$start,':end'=>$end,':opening'=>$opening,':closing'=>$closing,':count'=>count($rows),':key'=>$key,':hash'=>$payloadHash,':user'=>get_current_user_id()?:null,':now'=>$now));$importId=(int)$db->lastInsertId();
   $line=$db->prepare('INSERT INTO mda_bank_statement_lines (import_id,line_number,transaction_date,direction,amount_minor,reference_number,description,payload_hash,created_at) VALUES (:import,:number,:date,:direction,:amount,:reference,:description,:hash,:now)');
   foreach($rows as $i=>$row){$lineHash=hash('sha256',wp_json_encode($row));$line->execute(array(':import'=>$importId,':number'=>$i+1,':date'=>$row['transaction_date'],':direction'=>$row['direction'],':amount'=>$row['amount_minor'],':reference'=>$row['reference_number']?:null,':description'=>$row['description'],':hash'=>$lineHash,':now'=>$now));}
   $db->prepare("INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,'bank_statement_imported','bank_statement',:id,:json,:now)")->execute(array(':user'=>get_current_user_id()?:null,':id'=>(string)$importId,':json'=>wp_json_encode(array('account_id'=>$accountId,'currency'=>$currency,'reference'=>$reference,'lines'=>count($rows),'period_start'=>$start,'period_end'=>$end)),':now'=>$now));
   return array('import_id'=>$importId,'duplicate'=>false,'line_count'=>count($rows));
  });
 }
 public static function imports(int $limit=50):array {
  return MDA_Database::connection()->query('SELECT i.*,a.account_code,a.account_name,(SELECT COUNT(*) FROM mda_bank_statement_lines l WHERE l.import_id=i.id) total_lines,(SELECT COUNT(*) FROM mda_bank_statement_lines l WHERE l.import_id=i.id AND (SELECT e.action FROM mda_bank_reconciliation_events e WHERE e.statement_line_id=l.id ORDER BY e.id DESC LIMIT 1)=\'matched\') matched_lines,(SELECT COUNT(*) FROM mda_bank_statement_lines l WHERE l.import_id=i.id AND (SELECT e.action FROM mda_bank_reconciliation_events e WHERE e.statement_line_id=l.id ORDER BY e.id DESC LIMIT 1)=\'ignored\') ignored_lines FROM mda_bank_statement_imports i JOIN mda_accounts a ON a.id=i.bank_account_id ORDER BY i.id DESC LIMIT '.max(1,min(200,$limit)))->fetchAll();
 }
 public static function get_import(int $id):?array {
  $q=MDA_Database::connection()->prepare('SELECT i.*,a.account_code,a.account_name FROM mda_bank_statement_imports i JOIN mda_accounts a ON a.id=i.bank_account_id WHERE i.id=:id');$q->execute(array(':id'=>$id));$row=$q->fetch();return$row?:null;
 }
 private static function current_event(PDO $db,int $lineId):?array {
  $q=$db->prepare('SELECT * FROM mda_bank_reconciliation_events WHERE statement_line_id=:line ORDER BY id DESC LIMIT 1');$q->execute(array(':line'=>$lineId));$row=$q->fetch();return$row?:null;
 }
 private static function line(PDO $db,int $lineId):array {
  $q=$db->prepare('SELECT l.*,i.bank_account_id,i.currency_code,i.id import_id FROM mda_bank_statement_lines l JOIN mda_bank_statement_imports i ON i.id=l.import_id WHERE l.id=:id');$q->execute(array(':id'=>$lineId));$row=$q->fetch();if(!$row)throw new InvalidArgumentException('ردیف صورت‌حساب پیدا نشد.');return$row;
 }
 public static function candidates(int $lineId,int $limit=20):array {
  $db=MDA_Database::connection();$line=self::line($db,$lineId);
  $sql="SELECT j.id journal_entry_id,d.document_number,d.document_date,j.debit_minor,j.credit_minor,j.memo,d.description document_description,d.source_type,d.source_id FROM mda_journal_entries j JOIN mda_documents d ON d.id=j.document_id WHERE j.account_id=:account AND d.currency_code=:currency AND d.document_status='posted' AND d.document_date=:date AND ((:direction='credit' AND j.debit_minor=:amount AND j.credit_minor=0) OR (:direction='debit' AND j.credit_minor=:amount AND j.debit_minor=0)) AND NOT EXISTS (SELECT 1 FROM mda_bank_reconciliation_events e WHERE e.journal_entry_id=j.id AND e.action='matched' AND e.id=(SELECT MAX(e2.id) FROM mda_bank_reconciliation_events e2 WHERE e2.statement_line_id=e.statement_line_id)) ORDER BY j.id DESC LIMIT :limit";
  $q=$db->prepare($sql);$q->bindValue(':account',(int)$line['bank_account_id'],PDO::PARAM_INT);$q->bindValue(':currency',$line['currency_code']);$q->bindValue(':date',$line['transaction_date']);$q->bindValue(':direction',$line['direction']);$q->bindValue(':amount',(int)$line['amount_minor'],PDO::PARAM_INT);$q->bindValue(':limit',max(1,min(100,$limit)),PDO::PARAM_INT);$q->execute();return$q->fetchAll();
 }
 public static function match(int $lineId,int $journalEntryId):array {
  if($lineId<1||$journalEntryId<1)throw new InvalidArgumentException('ردیف یا سند دفتر کل معتبر نیست.');
  return MDA_Database::transaction(static function(PDO $db)use($lineId,$journalEntryId):array{
   $line=self::line($db,$lineId);$current=self::current_event($db,$lineId);
   if($current&&$current['action']==='matched'){if((int)$current['journal_entry_id']===$journalEntryId)return array('duplicate'=>true,'event_id'=>(int)$current['id']);throw new RuntimeException('این ردیف قبلاً با سند دیگری تطبیق داده شده است.');}
   if($current&&$current['action']==='ignored')throw new RuntimeException('ابتدا ردیف نادیده‌گرفته‌شده را فعال کنید.');
   $q=$db->prepare("SELECT j.id,j.account_id,j.debit_minor,j.credit_minor,d.currency_code,d.document_date,d.document_status FROM mda_journal_entries j JOIN mda_documents d ON d.id=j.document_id WHERE j.id=:id");$q->execute(array(':id'=>$journalEntryId));$journal=$q->fetch();
   if(!$journal||(int)$journal['account_id']!==(int)$line['bank_account_id']||$journal['currency_code']!==$line['currency_code']||$journal['document_status']!=='posted'||$journal['document_date']!==$line['transaction_date'])throw new InvalidArgumentException('سند دفتر کل متعلق به حساب، ارز یا تاریخ این ردیف نیست.');
   $directionOk=$line['direction']==='credit'?(int)$journal['debit_minor']===(int)$line['amount_minor']&&(int)$journal['credit_minor']===0:(int)$journal['credit_minor']===(int)$line['amount_minor']&&(int)$journal['debit_minor']===0;
   if(!$directionOk)throw new InvalidArgumentException('جهت یا مبلغ سند دفتر کل با صورت‌حساب برابر نیست.');
   $used=$db->prepare("SELECT 1 FROM mda_bank_reconciliation_events e WHERE e.journal_entry_id=:journal AND e.action='matched' AND e.id=(SELECT MAX(e2.id) FROM mda_bank_reconciliation_events e2 WHERE e2.statement_line_id=e.statement_line_id) LIMIT 1");$used->execute(array(':journal'=>$journalEntryId));if($used->fetchColumn())throw new RuntimeException('این ردیف دفتر کل قبلاً با ردیف صورت‌حساب دیگری تطبیق داده شده است.');
   $insert=$db->prepare("INSERT INTO mda_bank_reconciliation_events (statement_line_id,action,journal_entry_id,previous_event_id,created_by,created_at) VALUES (:line,'matched',:journal,:previous,:user,:now)");$insert->execute(array(':line'=>$lineId,':journal'=>$journalEntryId,':previous'=>$current?(int)$current['id']:null,':user'=>get_current_user_id()?:null,':now'=>gmdate('Y-m-d H:i:s')));return array('duplicate'=>false,'event_id'=>(int)$db->lastInsertId());
  });
 }
 public static function unmatch(int $lineId):array {
  if($lineId<1)throw new InvalidArgumentException('ردیف صورت‌حساب معتبر نیست.');
  return MDA_Database::transaction(static function(PDO $db)use($lineId):array{$current=self::current_event($db,$lineId);if(!$current||$current['action']!=='matched')throw new RuntimeException('این ردیف در وضعیت تطبیق‌شده نیست.');$q=$db->prepare("INSERT INTO mda_bank_reconciliation_events (statement_line_id,action,journal_entry_id,previous_event_id,created_by,created_at) VALUES (:line,'unmatched',:journal,:previous,:user,:now)");$q->execute(array(':line'=>$lineId,':journal'=>$current['journal_entry_id'],':previous'=>$current['id'],':user'=>get_current_user_id()?:null,':now'=>gmdate('Y-m-d H:i:s')));return array('event_id'=>(int)$db->lastInsertId());});
 }
 public static function ignore(int $lineId):array {
  if($lineId<1)throw new InvalidArgumentException('ردیف صورت‌حساب معتبر نیست.');
  return MDA_Database::transaction(static function(PDO $db)use($lineId):array{$line=self::line($db,$lineId);$current=self::current_event($db,$lineId);if($current&&$current['action']==='matched')throw new RuntimeException('برای نادیده‌گرفتن، ابتدا تطبیق را لغو کنید.');if($current&&$current['action']==='ignored')return array('duplicate'=>true);$q=$db->prepare("INSERT INTO mda_bank_reconciliation_events (statement_line_id,action,previous_event_id,created_by,created_at) VALUES (:line,'ignored',:previous,:user,:now)");$q->execute(array(':line'=>$lineId,':previous'=>$current?(int)$current['id']:null,':user'=>get_current_user_id()?:null,':now'=>gmdate('Y-m-d H:i:s')));return array('duplicate'=>false,'event_id'=>(int)$db->lastInsertId());});
 }
 public static function unignore(int $lineId):array {
  if($lineId<1)throw new InvalidArgumentException('ردیف صورت‌حساب معتبر نیست.');
  return MDA_Database::transaction(static function(PDO $db)use($lineId):array{$current=self::current_event($db,$lineId);if(!$current||$current['action']!=='ignored')throw new RuntimeException('این ردیف نادیده‌گرفته نشده است.');$q=$db->prepare("INSERT INTO mda_bank_reconciliation_events (statement_line_id,action,previous_event_id,created_by,created_at) VALUES (:line,'unignored',:previous,:user,:now)");$q->execute(array(':line'=>$lineId,':previous'=>$current['id'],':user'=>get_current_user_id()?:null,':now'=>gmdate('Y-m-d H:i:s')));return array('event_id'=>(int)$db->lastInsertId());});
 }
 public static function lines(int $importId,int $limit=500):array {
  $q=MDA_Database::connection()->prepare("SELECT l.*,e.action current_action,e.journal_entry_id matched_journal_entry_id,d.document_number FROM mda_bank_statement_lines l LEFT JOIN mda_bank_reconciliation_events e ON e.id=(SELECT MAX(e2.id) FROM mda_bank_reconciliation_events e2 WHERE e2.statement_line_id=l.id) LEFT JOIN mda_journal_entries j ON j.id=e.journal_entry_id LEFT JOIN mda_documents d ON d.id=j.document_id WHERE l.import_id=:import ORDER BY l.line_number LIMIT :limit");$q->bindValue(':import',$importId,PDO::PARAM_INT);$q->bindValue(':limit',max(1,min(1000,$limit)),PDO::PARAM_INT);$q->execute();return$q->fetchAll();
 }
 public static function summary(int $importId):array {
  $q=MDA_Database::connection()->prepare("SELECT COUNT(*) total_lines,SUM(CASE WHEN e.action='matched' THEN 1 ELSE 0 END) matched_lines,SUM(CASE WHEN e.action='ignored' THEN 1 ELSE 0 END) ignored_lines,SUM(CASE WHEN e.action IS NULL OR e.action IN ('unmatched','unignored') THEN 1 ELSE 0 END) unmatched_lines FROM mda_bank_statement_lines l LEFT JOIN mda_bank_reconciliation_events e ON e.id=(SELECT MAX(e2.id) FROM mda_bank_reconciliation_events e2 WHERE e2.statement_line_id=l.id) WHERE l.import_id=:import");$q->execute(array(':import'=>$importId));$row=$q->fetch();return array('total_lines'=>(int)$row['total_lines'],'matched_lines'=>(int)$row['matched_lines'],'ignored_lines'=>(int)$row['ignored_lines'],'unmatched_lines'=>(int)$row['unmatched_lines']);
 }
}

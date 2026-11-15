<?php
defined( 'ABSPATH' ) || exit;

/**
 * تفصیلی اشخاص بر پایه ردیف‌های دفتر روزنامه؛ مانده ذخیره‌شده و قابل واگرایی نداریم.
 */
final class MDA_Party_Ledger_Service {
 public static function balances(int $limit=200): array {
  $sql="SELECT p.id AS party_id,p.party_code,p.display_name,m.role_key,a.account_code,a.account_name,a.normal_balance,
   COALESCE(SUM(j.debit_minor),0) AS debit_total,COALESCE(SUM(j.credit_minor),0) AS credit_total
   FROM mda_party_control_accounts m JOIN mda_accounts a ON a.id=m.account_id
   JOIN mda_journal_entries j ON j.account_id=a.id AND j.party_id IS NOT NULL
   JOIN mda_documents d ON d.id=j.document_id AND d.document_status='posted'
   JOIN mda_parties p ON p.id=j.party_id
   GROUP BY p.id,m.role_key,a.id ORDER BY p.display_name,m.role_key LIMIT ".max(1,min(1000,$limit));
  $rows=MDA_Database::connection()->query($sql)->fetchAll();
  foreach($rows as &$row){$row['debit_total']=(int)$row['debit_total'];$row['credit_total']=(int)$row['credit_total'];$row['balance_minor']='credit'===$row['normal_balance']?$row['credit_total']-$row['debit_total']:$row['debit_total']-$row['credit_total'];}
  return $rows;
 }

 public static function statement(int $party_id,string $role,string $from,string $to): array {
  if(!in_array($role,array('customer','supplier','employee','partner','other'),true))throw new InvalidArgumentException('نقش تفصیلی معتبر نیست.');
  foreach(array($from,$to) as $date){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('بازه تاریخ معتبر نیست.');}
  if($from>$to)throw new InvalidArgumentException('تاریخ شروع نباید بعد از تاریخ پایان باشد.');
  $pdo=MDA_Database::connection();
  $q=$pdo->prepare("SELECT p.id,p.display_name,p.party_code,a.id AS account_id,a.account_code,a.account_name,a.normal_balance
   FROM mda_parties p JOIN mda_party_roles r ON r.party_id=p.id AND r.role_key=:role
   JOIN mda_party_control_accounts m ON m.role_key=r.role_key JOIN mda_accounts a ON a.id=m.account_id
   WHERE p.id=:party AND p.archived_at IS NULL AND p.is_active=1");$q->execute(array(':role'=>$role,':party'=>$party_id));$party=$q->fetch();
  if(!$party)throw new RuntimeException('طرف‌حساب فعال با نقش انتخاب‌شده یا حساب کنترلی متناظر پیدا نشد.');
  $base=" FROM mda_journal_entries j JOIN mda_documents d ON d.id=j.document_id WHERE j.party_id=:party AND j.account_id=:account AND d.document_status='posted'";
  $q=$pdo->prepare("SELECT COALESCE(SUM(j.debit_minor),0),COALESCE(SUM(j.credit_minor),0)".$base." AND d.document_date<:from");$q->execute(array(':party'=>$party_id,':account'=>$party['account_id'],':from'=>$from));$opening=$q->fetch(PDO::FETCH_NUM);
  $q=$pdo->prepare("SELECT d.document_date,d.document_number,d.document_type,d.description,j.entry_number,j.memo,j.debit_minor,j.credit_minor".$base." AND d.document_date BETWEEN :from AND :to ORDER BY d.document_date,d.id,j.entry_number");
  $q->execute(array(':party'=>$party_id,':account'=>$party['account_id'],':from'=>$from,':to'=>$to));$lines=$q->fetchAll();
  $sign='credit'===$party['normal_balance']?-1:1;
  $balance=($sign*((int)$opening[0]-(int)$opening[1]));
  $opening_balance=$balance;
  foreach($lines as &$line){$line['debit_minor']=(int)$line['debit_minor'];$line['credit_minor']=(int)$line['credit_minor'];$balance+=$sign*($line['debit_minor']-$line['credit_minor']);$line['running_balance_minor']=$balance;}
  return array('party'=>$party,'role'=>$role,'from'=>$from,'to'=>$to,'opening_balance_minor'=>$opening_balance,'closing_balance_minor'=>$balance,'lines'=>$lines);
 }

 public static function mappings(): array {
  return MDA_Database::connection()->query("SELECT m.role_key,a.id AS account_id,a.account_code,a.account_name FROM mda_party_control_accounts m JOIN mda_accounts a ON a.id=m.account_id ORDER BY m.role_key")->fetchAll();
 }
}

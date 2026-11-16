<?php
defined( 'ABSPATH' ) || exit;

final class MDA_Account_Service {
 private const TYPES=array('asset','liability','equity','income','expense');

 public static function save(array $input,?int $account_id=null): int {
  $code=strtoupper(sanitize_text_field((string)($input['account_code']??'')));$name=sanitize_text_field((string)($input['account_name']??''));$type=sanitize_key((string)($input['account_type']??''));$parent=!empty($input['parent_id'])?absint($input['parent_id']):null;
  $normal=sanitize_key((string)($input['normal_balance']??''));if(''===$normal)$normal=in_array($type,array('liability','equity','income'),true)?'credit':'debit';
  if(!preg_match('/^[0-9A-Z.-]{2,32}$/',$code)||''===$name||!in_array($type,self::TYPES,true)||!in_array($normal,array('debit','credit'),true))throw new InvalidArgumentException('کد، نام، ماهیت یا گروه حساب معتبر نیست.');
  return MDA_Database::transaction(static function(PDO $pdo) use($input,$account_id,$code,$name,$type,$parent,$normal): int {
   $before=null;
   if($account_id){$q=$pdo->prepare('SELECT * FROM mda_accounts WHERE id=:id AND archived_at IS NULL');$q->execute(array(':id'=>$account_id));$before=$q->fetch();if(!$before)throw new RuntimeException('حساب پیدا نشد.');
    if((int)$before['is_system']===1&&($before['account_code']!==$code||$before['account_type']!==$type))throw new RuntimeException('کد و گروه حساب سیستمی قابل تغییر نیست.');
    if((int)$before['is_system']===1&&empty($input['is_active']))throw new RuntimeException('حساب سیستمی قابل غیرفعال‌سازی نیست.');
   }
   if($parent){$q=$pdo->prepare('SELECT id,account_type FROM mda_accounts WHERE id=:id AND archived_at IS NULL');$q->execute(array(':id'=>$parent));$p=$q->fetch();if(!$p||$parent===$account_id||$p['account_type']!==$type)throw new InvalidArgumentException('حساب پدر معتبر نیست یا گروه آن متفاوت است.');
    if($account_id){$q=$pdo->prepare('WITH RECURSIVE descendants(id) AS (SELECT id FROM mda_accounts WHERE parent_id=:id UNION ALL SELECT a.id FROM mda_accounts a JOIN descendants d ON a.parent_id=d.id) SELECT 1 FROM descendants WHERE id=:parent LIMIT 1');$q->execute(array(':id'=>$account_id,':parent'=>$parent));if($q->fetchColumn())throw new InvalidArgumentException('حساب نمی‌تواند زیرمجموعه یکی از فرزندان خودش باشد.');}
   }
   $now=gmdate('Y-m-d H:i:s');$data=array(':name'=>$name,':parent'=>$parent,':normal'=>$normal,':control'=>empty($input['is_control'])?0:1,':manual'=>empty($input['allow_manual_entry'])?0:1,':description'=>sanitize_textarea_field((string)($input['description']??'')),':sort'=>absint($input['sort_order']??0));
   if($account_id){$q=$pdo->prepare('UPDATE mda_accounts SET account_name=:name,parent_id=:parent,normal_balance=:normal,is_control=:control,allow_manual_entry=:manual,description=:description,sort_order=:sort,is_active=:active WHERE id=:id');$q->execute($data+array(':active'=>empty($input['is_active'])?0:1,':id'=>$account_id));}
   else{$q=$pdo->prepare('INSERT INTO mda_accounts (account_code,account_name,account_type,parent_id,is_active,created_at,normal_balance,is_control,is_system,allow_manual_entry,description,sort_order) VALUES (:code,:name,:type,:parent,1,:now,:normal,:control,0,:manual,:description,:sort)');$q->execute($data+array(':code'=>$code,':type'=>$type,':now'=>$now));$account_id=(int)$pdo->lastInsertId();}
   $audit=$pdo->prepare("INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,:action,'account',:id,:after,:now)");$audit->execute(array(':user'=>get_current_user_id()?:null,':action'=>$before?'account.updated':'account.created',':id'=>(string)$account_id,':after'=>wp_json_encode(array('code'=>$code,'name'=>$name,'type'=>$type,'parent_id'=>$parent)),':now'=>$now));
   return $account_id;
  });
 }

 public static function archive(int $id): void {
  MDA_Database::transaction(static function(PDO $pdo) use($id): void {
   $q=$pdo->prepare('SELECT is_system FROM mda_accounts WHERE id=:id AND archived_at IS NULL');$q->execute(array(':id'=>$id));$row=$q->fetch();if(!$row||(int)$row['is_system']===1)throw new RuntimeException('حساب پیدا نشد یا حساب سیستمی است.');
   $q=$pdo->prepare('SELECT 1 FROM mda_journal_entries WHERE account_id=:id LIMIT 1');$q->execute(array(':id'=>$id));if($q->fetchColumn())throw new RuntimeException('حساب دارای گردش مالی است و قابل بایگانی نیست؛ آن را غیرفعال کنید.');
   $pdo->prepare('UPDATE mda_accounts SET is_active=0,archived_at=:now WHERE id=:id')->execute(array(':now'=>gmdate('Y-m-d H:i:s'),':id'=>$id));
  });
 }

 public static function all(bool $include_inactive=false): array {
  $sql='SELECT a.*,p.account_code AS parent_code,p.account_name AS parent_name FROM mda_accounts a LEFT JOIN mda_accounts p ON p.id=a.parent_id';if(!$include_inactive)$sql.=' WHERE a.is_active=1 AND a.archived_at IS NULL';return MDA_Database::connection()->query($sql.' ORDER BY a.account_code')->fetchAll();
 }
}

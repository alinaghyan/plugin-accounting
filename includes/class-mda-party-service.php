<?php
defined( 'ABSPATH' ) || exit;

final class MDA_Party_Service {
 private const ROLES = array( 'customer','supplier','employee','partner','other' );

 public static function save( array $input, ?int $party_id = null ): int {
  $name = sanitize_text_field( (string) ( $input['display_name'] ?? '' ) );
  $kind = sanitize_key( (string) ( $input['party_kind'] ?? 'person' ) );
  $phone = preg_replace( '/[^0-9+]/', '', (string) ( $input['phone'] ?? '' ) );
  $email = sanitize_email( (string) ( $input['email'] ?? '' ) );
  $roles = array_values( array_unique( array_intersect( self::ROLES, array_map( 'sanitize_key', (array) ( $input['roles'] ?? array() ) ) ) ) );
  if ( '' === $name || ! in_array( $kind, array('person','company'), true ) || empty($roles) ) { throw new InvalidArgumentException('نام، نوع طرف‌حساب و حداقل یک نقش معتبر الزامی است.'); }
  if ( '' !== $email && ! is_email($email) ) { throw new InvalidArgumentException('ایمیل معتبر نیست.'); }
  $now = gmdate('Y-m-d H:i:s');
  $values = array(
   ':name'=>$name, ':phone'=>''!==$phone?$phone:null, ':email'=>''!==$email?$email:null, ':kind'=>$kind,
   ':company'=>sanitize_text_field((string)($input['company_name']??''))?:null,
   ':economic'=>sanitize_text_field((string)($input['economic_code']??''))?:null,
   ':address'=>sanitize_textarea_field((string)($input['address']??''))?:null,
   ':postal'=>sanitize_text_field((string)($input['postal_code']??''))?:null,
   ':notes'=>sanitize_textarea_field((string)($input['notes']??''))?:null
  );
  return MDA_Database::transaction(static function(PDO $pdo) use($party_id,$input,$values,$roles,$now): int {
   if($party_id) {
    $q=$pdo->prepare('SELECT * FROM mda_parties WHERE id=:id AND archived_at IS NULL'); $q->execute(array(':id'=>$party_id)); $before=$q->fetch();
    if(!$before) throw new RuntimeException('طرف‌حساب پیدا نشد یا بایگانی شده است.');
    if(isset($input['party_code']) && strtoupper((string)$input['party_code'])!==$before['party_code']) throw new InvalidArgumentException('کد طرف‌حساب پس از ایجاد قابل تغییر نیست.');
    $q=$pdo->prepare('UPDATE mda_parties SET display_name=:name,phone=:phone,email=:email,party_kind=:kind,company_name=:company,economic_code=:economic,address=:address,postal_code=:postal,notes=:notes,updated_at=:updated WHERE id=:id');
    $q->execute($values+array(':updated'=>$now,':id'=>$party_id));
    $action='party.updated';
   } else {
    $code=strtoupper(sanitize_text_field((string)($input['party_code']??'')));
    if(''===$code) { $n=(int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTR(party_code,5) AS INTEGER)),0)+1 FROM mda_parties WHERE party_code GLOB 'PTY-[0-9]*'")->fetchColumn(); $code='PTY-'.str_pad((string)$n,6,'0',STR_PAD_LEFT); }
    if(!preg_match('/^[A-Z0-9-]{3,32}$/',$code)) throw new InvalidArgumentException('فرمت کد طرف‌حساب معتبر نیست.');
    $q=$pdo->prepare('INSERT INTO mda_parties (party_code,display_name,phone,email,party_kind,company_name,economic_code,address,postal_code,notes,created_at,updated_at) VALUES (:code,:name,:phone,:email,:kind,:company,:economic,:address,:postal,:notes,:created,:updated)');
    $q->execute($values+array(':code'=>$code,':created'=>$now,':updated'=>$now)); $party_id=(int)$pdo->lastInsertId(); $action='party.created';
   }
   $pdo->prepare('DELETE FROM mda_party_roles WHERE party_id=:id')->execute(array(':id'=>$party_id));
   $insert=$pdo->prepare('INSERT INTO mda_party_roles (party_id,role_key,created_at) VALUES (:id,:role,:created)');
   foreach($roles as $role) $insert->execute(array(':id'=>$party_id,':role'=>$role,':created'=>$now));
   $pdo->prepare('UPDATE mda_parties SET is_customer=:c,is_supplier=:s,is_employee=:e,is_partner=:p WHERE id=:id')->execute(array(':c'=>in_array('customer',$roles,true)?1:0,':s'=>in_array('supplier',$roles,true)?1:0,':e'=>in_array('employee',$roles,true)?1:0,':p'=>in_array('partner',$roles,true)?1:0,':id'=>$party_id));
   $audit=$pdo->prepare("INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,:action,'party',:id,:after,:now)");
   $audit->execute(array(':user'=>get_current_user_id()?:null,':action'=>$action,':id'=>(string)$party_id,':after'=>wp_json_encode($values+array('roles'=>$roles)),':now'=>$now));
   return $party_id;
  });
 }

 public static function archive(int $party_id): void {
  MDA_Database::transaction(static function(PDO $pdo) use($party_id): void {
   $now=gmdate('Y-m-d H:i:s'); $q=$pdo->prepare('UPDATE mda_parties SET is_active=0,archived_at=:now,updated_at=:now WHERE id=:id AND archived_at IS NULL'); $q->execute(array(':now'=>$now,':id'=>$party_id));
   if(1!==$q->rowCount()) throw new RuntimeException('طرف‌حساب برای بایگانی پیدا نشد.');
   $audit=$pdo->prepare("INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,created_at) VALUES (:user,'party.archived','party',:id,:now)");
   $audit->execute(array(':user'=>get_current_user_id()?:null,':id'=>(string)$party_id,':now'=>$now));
  });
 }

 public static function get(int $id): ?array {
  $pdo=MDA_Database::connection(); $q=$pdo->prepare('SELECT * FROM mda_parties WHERE id=:id'); $q->execute(array(':id'=>$id)); $row=$q->fetch();
  if(!$row) return null; $r=$pdo->prepare('SELECT role_key FROM mda_party_roles WHERE party_id=:id ORDER BY role_key'); $r->execute(array(':id'=>$id)); $row['roles']=$r->fetchAll(PDO::FETCH_COLUMN); return $row;
 }

 public static function search(string $term='',int $limit=50): array {
  $sql='SELECT p.*,GROUP_CONCAT(r.role_key,",") AS roles FROM mda_parties p LEFT JOIN mda_party_roles r ON r.party_id=p.id WHERE p.archived_at IS NULL'; $params=array();
  if(''!==trim($term)) {$sql.=' AND (p.display_name LIKE :term OR p.party_code LIKE :term OR p.phone LIKE :term)';$params[':term']='%'.trim($term).'%';}
  $sql.=' GROUP BY p.id ORDER BY p.id DESC LIMIT '.max(1,min(200,$limit)); $q=MDA_Database::connection()->prepare($sql);$q->execute($params);return $q->fetchAll();
 }
}

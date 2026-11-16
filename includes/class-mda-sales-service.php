<?php
defined( 'ABSPATH' ) || exit;

/** Posts an immutable sale, inventory issue, weighted-average COGS and balanced journal in one SQLite transaction. */
final class MDA_Sales_Service {
 public static function post_order_payload(array $payload): array {
  $orderId=(int)($payload['order_id']??0);
  $currency=strtoupper((string)($payload['currency']??''));
  $key=trim((string)($payload['idempotency_key']??''));
  $date=(string)($payload['date']??'');
  if($orderId<1||$currency!=='IRR'||strlen($key)<12||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) throw new InvalidArgumentException('سربرگ رویداد فروش معتبر نیست یا واحد پول IRR نیست.');
  $items=$payload['items']??array();
  if(!is_array($items)||!count($items)||count($items)>500) throw new InvalidArgumentException('سفارش باید حداقل یک ردیف کالای معتبر داشته باشد.');
  $hash=hash('sha256',wp_json_encode($payload));
  return MDA_Database::transaction(static function(PDO $db) use($payload,$orderId,$currency,$key,$date,$items,$hash): array {
   $existing=$db->prepare('SELECT id,document_number,accounting_document_id,payload_hash FROM mda_sales_documents WHERE order_id=:id OR idempotency_key=:key LIMIT 1');
   $existing->execute(array(':id'=>$orderId,':key'=>$key));$found=$existing->fetch();
   if($found){if(!hash_equals((string)$found['payload_hash'],$hash))throw new RuntimeException('این سفارش قبلاً با اطلاعات متفاوت ثبت حسابداری شده است؛ نیازمند تطبیق دستی است.');return array('sales_document_id'=>(int)$found['id'],'document_number'=>$found['document_number'],'accounting_document_id'=>(int)$found['accounting_document_id'],'duplicate'=>true);}
   $warehouse=(int)($payload['warehouse_id']??0);
   $wh=$db->prepare('SELECT id FROM mda_warehouses WHERE id=:id AND is_active=1');$wh->execute(array(':id'=>$warehouse));if(!$wh->fetchColumn())throw new RuntimeException('انبار فعال برای ثبت فروش تنظیم نشده است.');
   $partyId=self::customer_party($db,(array)($payload['customer']??array()),$orderId);
   $productTotal=0;$shipping=(int)($payload['shipping_minor']??0);$fees=(int)($payload['fees_minor']??0);$tax=(int)($payload['tax_minor']??0);$total=(int)($payload['total_minor']??0);
   if(min($shipping,$fees,$tax,$total)<0)throw new InvalidArgumentException('مبالغ سفارش نمی‌توانند منفی باشند.');
   $costTotal=0;$clean=array();
   foreach($items as $item){
    $product=(int)($item['product_id']??0);$variation=(int)($item['variation_id']??0);$qty=$item['quantity_scaled']??null;$scale=(int)($item['quantity_scale']??1000000);$revenue=(int)($item['revenue_minor']??-1);
    if($product<1||$variation<0||!is_int($qty)||$qty<1||$scale<1||$revenue<0)throw new InvalidArgumentException('ردیف کالا یا مقدار فروش معتبر نیست.');
    $state=$db->prepare('SELECT quantity_scaled,quantity_scale,average_cost_minor,currency_code FROM mda_product_cost_state WHERE product_id=:product AND variation_id=:variation AND warehouse_id=:warehouse');
    $state->execute(array(':product'=>$product,':variation'=>$variation,':warehouse'=>$warehouse));$cost=$state->fetch();
    $stock=$db->prepare('SELECT COALESCE(SUM(quantity_scaled),0) FROM mda_inventory_movements WHERE product_id=:product AND variation_id=:variation AND warehouse_id=:warehouse AND quantity_scale=:scale');
    $stock->execute(array(':product'=>$product,':variation'=>$variation,':warehouse'=>$warehouse));$stockQty=(int)$stock->fetchColumn();
    if(!$cost||(int)$cost['quantity_scale']!==$scale||(int)$cost['quantity_scaled']!==$stockQty||$cost['currency_code']!==$currency)throw new RuntimeException('بهای تمام‌شده یا کاردکس کالا با موجودی انبار سازگار نیست؛ فروش حسابداری متوقف شد.');
    if($qty>$stockQty)throw new RuntimeException('موجودی حسابداری برای یکی از کالاهای سفارش کافی نیست.');
    $unitCost=(int)$cost['average_cost_minor'];$cogs=self::mulDivRound($qty,$unitCost,$scale);
    $productTotal=self::safeAdd($productTotal,$revenue);$costTotal=self::safeAdd($costTotal,$cogs);
    $clean[]=array('item'=>$item,'product'=>$product,'variation'=>$variation,'qty'=>$qty,'scale'=>$scale,'revenue'=>$revenue,'unit_cost'=>$unitCost,'cogs'=>$cogs);
   }
   if(self::safeAdd(self::safeAdd(self::safeAdd($productTotal,$shipping),$fees),$tax)!==$total)throw new RuntimeException('جمع ردیف‌های کالا، ارسال، کارمزد و مالیات با مبلغ نهایی سفارش برابر نیست.');
   $accounts=self::accounts($db,array('1200','1300','4100','5100','2200','4200','4300'));
   $lines=array();$lines[]=array('account_id'=>$accounts['1200'],'party_id'=>$partyId,'debit_minor'=>$total,'credit_minor'=>0,'memo'=>'مطالبه از مشتری بابت سفارش '.$orderId);
   if($productTotal>0)$lines[]=array('account_id'=>$accounts['4100'],'party_id'=>null,'debit_minor'=>0,'credit_minor'=>$productTotal,'memo'=>'درآمد فروش کالا');
   if($shipping>0)$lines[]=array('account_id'=>$accounts['4200'],'party_id'=>null,'debit_minor'=>0,'credit_minor'=>$shipping,'memo'=>'درآمد ارسال');
   if($fees>0)$lines[]=array('account_id'=>$accounts['4300'],'party_id'=>null,'debit_minor'=>0,'credit_minor'=>$fees,'memo'=>'سایر درآمدهای سفارش');
   if($tax>0)$lines[]=array('account_id'=>$accounts['2200'],'party_id'=>null,'debit_minor'=>0,'credit_minor'=>$tax,'memo'=>'مالیات و عوارض فروش');
   if($costTotal>0){$lines[]=array('account_id'=>$accounts['5100'],'party_id'=>null,'debit_minor'=>$costTotal,'credit_minor'=>0,'memo'=>'بهای تمام‌شده کالای فروش‌رفته');$lines[]=array('account_id'=>$accounts['1300'],'party_id'=>null,'debit_minor'=>0,'credit_minor'=>$costTotal,'memo'=>'کاهش ارزش موجودی کالا');}
   $docNumber='MDA-SAL-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));$now=gmdate('Y-m-d H:i:s');
   $journalKey='sale-journal-'.$key;$journalHash=hash('sha256',wp_json_encode(array($orderId,$lines,$date,$currency)));
   $journal=$db->prepare("INSERT INTO mda_documents (document_number,document_type,document_status,party_id,source_type,source_id,idempotency_key,payload_hash,currency_code,amount_scale,document_date,description,created_by,created_at,posted_at) VALUES (:number,'sale','posted',:party,'woocommerce',:source,:key,:hash,:currency,1,:date,:description,:user,:now,:now)");
   $journal->execute(array(':number'=>$docNumber,':party'=>$partyId,':source'=>(string)$orderId,':key'=>$journalKey,':hash'=>$journalHash,':currency'=>$currency,':date'=>$date,':description'=>'ثبت فروش سفارش '.$orderId,':user'=>get_current_user_id()?:null,':now'=>$now));$journalId=(int)$db->lastInsertId();
   $entry=$db->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,party_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,:num,:account,:party,:debit,:credit,:memo,:now)');
   foreach($lines as $i=>$line)$entry->execute(array(':doc'=>$journalId,':num'=>$i+1,':account'=>$line['account_id'],':party'=>$line['party_id'],':debit'=>$line['debit_minor'],':credit'=>$line['credit_minor'],':memo'=>$line['memo'],':now'=>$now));
   $insert=$db->prepare("INSERT INTO mda_sales_documents (source_type,source_id,order_id,party_id,warehouse_id,document_number,document_date,currency_code,product_total_minor,shipping_total_minor,fee_total_minor,tax_total_minor,total_minor,cogs_total_minor,idempotency_key,payload_hash,accounting_document_id,created_at) VALUES (:source_type,:source_id,:order,:party,:warehouse,:number,:date,:currency,:products,:shipping,:fees,:tax,:total,:cogs,:key,:hash,:journal,:now)");
   $sourceType=in_array(($payload['channel']??'website'),array('phone','in_person'),true)?$payload['channel']:'woocommerce';
   $insert->execute(array(':source_type'=>$sourceType,':source_id'=>(string)$orderId,':order'=>$orderId,':party'=>$partyId,':warehouse'=>$warehouse,':number'=>$docNumber,':date'=>$date,':currency'=>$currency,':products'=>$productTotal,':shipping'=>$shipping,':fees'=>$fees,':tax'=>$tax,':total'=>$total,':cogs'=>$costTotal,':key'=>$key,':hash'=>$hash,':journal'=>$journalId,':now'=>$now));$saleId=(int)$db->lastInsertId();
   $lineInsert=$db->prepare('INSERT INTO mda_sales_lines (sales_document_id,order_item_id,product_id,variation_id,product_name_snapshot,sku_snapshot,quantity_scaled,quantity_scale,revenue_minor,unit_cost_minor,cogs_minor,currency_code,created_at) VALUES (:sale,:item,:product,:variation,:name,:sku,:qty,:scale,:revenue,:cost,:cogs,:currency,:now)');
   $movement=$db->prepare("INSERT INTO mda_inventory_movements (product_id,variation_id,warehouse_id,movement_type,quantity_scaled,quantity_scale,unit_cost_minor,currency_code,source_type,source_id,idempotency_key,occurred_at,created_by) VALUES (:product,:variation,:warehouse,'sale_issue',:qty,:scale,:cost,:currency,'sale',:source,:key,:now,:user)");
   $update=$db->prepare('UPDATE mda_product_cost_state SET quantity_scaled=quantity_scaled-:qty,updated_at=:now WHERE product_id=:product AND variation_id=:variation AND warehouse_id=:warehouse AND quantity_scaled>=:qty');
   foreach($clean as $row){$it=$row['item'];$lineInsert->execute(array(':sale'=>$saleId,':item'=>(int)$it['order_item_id'],':product'=>$row['product'],':variation'=>$row['variation'],':name'=>(string)$it['name'],':sku'=>$it['sku']??null,':qty'=>$row['qty'],':scale'=>$row['scale'],':revenue'=>$row['revenue'],':cost'=>$row['unit_cost'],':cogs'=>$row['cogs'],':currency'=>$currency,':now'=>$now));
    $movement->execute(array(':product'=>$row['product'],':variation'=>$row['variation'],':warehouse'=>$warehouse,':qty'=>-$row['qty'],':scale'=>$row['scale'],':cost'=>$row['unit_cost'],':currency'=>$currency,':source'=>$orderId,':key'=>'sale-issue-'.$orderId.'-'.$row['item']['order_item_id'],':now'=>$now,':user'=>get_current_user_id()?:null));
    $update->execute(array(':qty'=>$row['qty'],':now'=>$now,':product'=>$row['product'],':variation'=>$row['variation'],':warehouse'=>$warehouse));if(1!==$update->rowCount())throw new RuntimeException('به‌روزرسانی وضعیت بهای تمام‌شده انجام نشد.');
   }
   $audit=$db->prepare("INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,'sale_posted','sale',:id,:after,:now)");
   $audit->execute(array(':user'=>get_current_user_id()?:null,':id'=>(string)$saleId,':after'=>wp_json_encode(array('order_id'=>$orderId,'total_minor'=>$total,'cogs_total_minor'=>$costTotal,'channel'=>$sourceType)),':now'=>$now));
   return array('sales_document_id'=>$saleId,'document_number'=>$docNumber,'accounting_document_id'=>$journalId,'cogs_total_minor'=>$costTotal,'duplicate'=>false);
  });
 }

 private static function customer_party(PDO $db,array $customer,int $orderId): int {
  $phone=preg_replace('/[^0-9+]/','',(string)($customer['phone']??''));$email=sanitize_email((string)($customer['email']??''));$name=trim(sanitize_text_field((string)($customer['name']??'')));
  $found=false;
  if($phone!==''){$q=$db->prepare("SELECT p.id FROM mda_parties p JOIN mda_party_roles r ON r.party_id=p.id AND r.role_key='customer' WHERE p.phone=:phone AND p.archived_at IS NULL LIMIT 1");$q->execute(array(':phone'=>$phone));$found=$q->fetchColumn();}
  if(!$found&&$email!==''){$q=$db->prepare("SELECT p.id FROM mda_parties p JOIN mda_party_roles r ON r.party_id=p.id AND r.role_key='customer' WHERE p.email=:email AND p.archived_at IS NULL LIMIT 1");$q->execute(array(':email'=>$email));$found=$q->fetchColumn();}
  if($found)return(int)$found;
  if($name==='')$name='مشتری سفارش '.$orderId;
  $code='WC-'.str_pad((string)$orderId,10,'0',STR_PAD_LEFT);$now=gmdate('Y-m-d H:i:s');
  $insert=$db->prepare('INSERT INTO mda_parties (party_code,display_name,phone,email,is_customer,created_at,updated_at) VALUES (:code,:name,:phone,:email,1,:now,:now)');
  try{$insert->execute(array(':code'=>$code,':name'=>$name,':phone'=>$phone?:null,':email'=>$email?:null,':now'=>$now));$id=(int)$db->lastInsertId();}
  catch(PDOException $e){$lookup=$db->prepare('SELECT id FROM mda_parties WHERE party_code=:code');$lookup->execute(array(':code'=>$code));$id=(int)$lookup->fetchColumn();if(!$id)throw$e;}
  $db->prepare("INSERT OR IGNORE INTO mda_party_roles (party_id,role_key,created_at) VALUES (:id,'customer',:now)")->execute(array(':id'=>$id,':now'=>$now));
  return$id;
 }
 private static function accounts(PDO $db,array $codes): array {$out=array();$q=$db->prepare('SELECT id FROM mda_accounts WHERE account_code=:code AND is_active=1 AND archived_at IS NULL');foreach($codes as $code){$q->execute(array(':code'=>$code));$id=$q->fetchColumn();if(!$id)throw new RuntimeException('حساب سیستمی '.$code.' پیدا نشد.');$out[$code]=(int)$id;}return$out;}
 private static function safeAdd(int $a,int $b):int {if($b>0&&$a>PHP_INT_MAX-$b)throw new OverflowException('مجموع مبالغ از ظرفیت عدد صحیح بیشتر است.');return$a+$b;}
 private static function mulDivRound(int $a,int $b,int $d):int {if($a<0||$b<0||$d<1||($b>0&&$a>intdiv(PHP_INT_MAX,$b)))throw new OverflowException('محاسبه بهای تمام‌شده خارج از ظرفیت عدد صحیح است.');$p=$a*$b;$q=intdiv($p,$d);$r=$p%$d;if($r>=intdiv($d,2)+($d%2))$q++;return$q;}
}

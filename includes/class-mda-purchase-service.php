<?php
defined( 'ABSPATH' ) || exit;

/**
 * Atomic purchasing workflow. All money is stored as integer minor units;
 * quantities are integer-scaled. A posted purchase updates stock, cost state,
 * price history and the supplier/cash journal in the same SQLite transaction.
 */
final class MDA_Purchase_Service {
 public static function create_draft(array $data): int {
  $supplier=(int)($data['supplier_party_id']??0);$warehouse=(int)($data['warehouse_id']??0);
  $date=(string)($data['document_date']??current_time('Y-m-d'));$currency=strtoupper((string)($data['currency_code']??'IRR'));
  $key=trim((string)($data['idempotency_key']??''));
  $dateObj=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
  if($supplier<1||$warehouse<1||!$dateObj||$dateObj->format('Y-m-d')!==$date||!preg_match('/^[A-Z]{3}$/',$currency)||strlen($key)<8||strlen($key)>191)throw new InvalidArgumentException('اطلاعات سربرگ فاکتور خرید معتبر نیست.');
  $lines=$data['lines']??array();if(!is_array($lines)||count($lines)<1||count($lines)>200)throw new InvalidArgumentException('فاکتور باید حداقل یک و حداکثر ۲۰۰ ردیف کالا داشته باشد.');
  $clean=array();$subtotal=0;
  foreach(array_values($lines) as $i=>$line){
   $product=(int)($line['product_id']??0);$variation=(int)($line['variation_id']??0);$qty=$line['quantity_scaled']??null;$qscale=(int)($line['quantity_scale']??1000000);$unit=$line['unit_cost_minor']??null;
   if($product<1||$variation<0||!is_int($qty)||$qty<1||$qscale<1||$qscale>1000000000||!is_int($unit)||$unit<0)throw new InvalidArgumentException('شناسه کالا، مقدار، مقیاس یا قیمت خرید یکی از ردیف‌ها معتبر نیست.');
   $lineTotal=self::mulDivRound($qty,$unit,$qscale);$subtotal=self::safeAdd($subtotal,$lineTotal);
   $name=trim(sanitize_text_field((string)($line['product_name_snapshot']??'')));$sku=isset($line['sku_snapshot'])?sanitize_text_field((string)$line['sku_snapshot']):null;
   if(''===$name){if(function_exists('wc_get_product')){$p=wc_get_product($variation?:$product);if($p){$name=$p->get_name();$sku=$p->get_sku();}}}
   if(''===$name)throw new InvalidArgumentException('نام کالا برای ذخیره snapshot الزامی است.');
   $clean[]=array('line_number'=>$i+1,'product_id'=>$product,'variation_id'=>$variation,'product_name_snapshot'=>$name,'sku_snapshot'=>$sku,'quantity_scaled'=>$qty,'quantity_scale'=>$qscale,'unit_cost_minor'=>$unit,'line_total_minor'=>$lineTotal);
  }
  $ancillary=$data['ancillary_costs']??array();if(!is_array($ancillary)||count($ancillary)>50)throw new InvalidArgumentException('تعداد هزینه‌های جانبی معتبر نیست.');
  $costRows=array();$ancillaryTotal=0;
  foreach($ancillary as $cost){$amount=$cost['amount_minor']??null;$code=sanitize_key((string)($cost['cost_code']??'other'));$desc=trim(sanitize_text_field((string)($cost['description']??'')));if(!is_int($amount)||$amount<0||''===$desc)throw new InvalidArgumentException('شرح و مبلغ هزینه جانبی معتبر نیست.');$ancillaryTotal=self::safeAdd($ancillaryTotal,$amount);$costRows[]=array('cost_code'=>$code,'description'=>$desc,'amount_minor'=>$amount);}
  $total=self::safeAdd($subtotal,$ancillaryTotal);$notes=isset($data['notes'])?sanitize_textarea_field((string)$data['notes']):null;$now=gmdate('Y-m-d H:i:s');$payloadHash=hash('sha256',wp_json_encode(array($supplier,$warehouse,$date,$currency,$clean,$costRows,$notes)));
  return MDA_Database::transaction(static function(PDO $db)use($supplier,$warehouse,$date,$currency,$key,$clean,$costRows,$subtotal,$ancillaryTotal,$total,$notes,$now,$payloadHash):int{
   $old=$db->prepare('SELECT id,payload_hash FROM mda_purchase_documents WHERE idempotency_key=:key');$old->execute(array(':key'=>$key));$existing=$old->fetch();
   if($existing){if(!hash_equals((string)$existing['payload_hash'],$payloadHash))throw new RuntimeException('کلید یکتا برای فاکتور دیگری استفاده شده است.');return (int)$existing['id'];}
   $party=$db->prepare("SELECT 1 FROM mda_parties p JOIN mda_party_roles r ON r.party_id=p.id AND r.role_key='supplier' WHERE p.id=:id AND p.is_active=1 AND p.archived_at IS NULL");$party->execute(array(':id'=>$supplier));if(!$party->fetchColumn())throw new InvalidArgumentException('تأمین‌کننده فعال معتبر انتخاب نشده است.');
   $wh=$db->prepare('SELECT 1 FROM mda_warehouses WHERE id=:id AND is_active=1');$wh->execute(array(':id'=>$warehouse));if(!$wh->fetchColumn())throw new InvalidArgumentException('انبار مقصد معتبر نیست.');
   $number='PUR-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
   $q=$db->prepare("INSERT INTO mda_purchase_documents (document_number,supplier_party_id,warehouse_id,document_status,document_date,currency_code,subtotal_minor,ancillary_cost_minor,total_minor,idempotency_key,payload_hash,notes,created_by,created_at) VALUES (:number,:supplier,:warehouse,'draft',:date,:currency,:subtotal,:ancillary,:total,:key,:hash,:notes,:user,:now)");
   $q->execute(array(':number'=>$number,':supplier'=>$supplier,':warehouse'=>$warehouse,':date'=>$date,':currency'=>$currency,':subtotal'=>$subtotal,':ancillary'=>$ancillaryTotal,':total'=>$total,':key'=>$key,':hash'=>$payloadHash,':notes'=>$notes,':user'=>get_current_user_id()?:null,':now'=>$now));
   $purchase=(int)$db->lastInsertId();
   $li=$db->prepare('INSERT INTO mda_purchase_lines (purchase_id,line_number,product_id,variation_id,product_name_snapshot,sku_snapshot,quantity_scaled,quantity_scale,unit_cost_minor,line_total_minor,created_at) VALUES (:purchase,:line,:product,:variation,:name,:sku,:qty,:scale,:unit,:total,:now)');
   foreach($clean as $line)$li->execute(array(':purchase'=>$purchase,':line'=>$line['line_number'],':product'=>$line['product_id'],':variation'=>$line['variation_id'],':name'=>$line['product_name_snapshot'],':sku'=>$line['sku_snapshot'],':qty'=>$line['quantity_scaled'],':scale'=>$line['quantity_scale'],':unit'=>$line['unit_cost_minor'],':total'=>$line['line_total_minor'],':now'=>$now));
   $ci=$db->prepare('INSERT INTO mda_purchase_ancillary_costs (purchase_id,cost_code,description,amount_minor,created_at) VALUES (:purchase,:code,:description,:amount,:now)');
   foreach($costRows as $cost)$ci->execute(array(':purchase'=>$purchase,':code'=>$cost['cost_code'],':description'=>$cost['description'],':amount'=>$cost['amount_minor'],':now'=>$now));
   return $purchase;
  });
 }

 public static function post(int $purchaseId,int $paidMinor=0,int $cashAccountId=0,string $paymentMethod='cash',?string $paymentKey=null): array {
  $result=self::post_in_transaction($purchaseId,$paidMinor,$cashAccountId,$paymentMethod,$paymentKey);
  // Outside the SQLite transaction: other modules (e.g. last purchase price on products) react to a committed purchase.
  if(empty($result['duplicate'])&&function_exists('do_action')){
   try{$lines=MDA_Database::connection()->prepare('SELECT product_id,variation_id,unit_cost_minor FROM mda_purchase_lines WHERE purchase_id=:id');$lines->execute(array(':id'=>$purchaseId));do_action('mda_purchase_posted',$purchaseId,$lines->fetchAll());}catch(Throwable $e){error_log('MaliDev Accounting purchase hook: '.$e->getMessage());}
  }
  return $result;
 }
 private static function post_in_transaction(int $purchaseId,int $paidMinor,int $cashAccountId,string $paymentMethod,?string $paymentKey): array {
  if($purchaseId<1||$paidMinor<0)throw new InvalidArgumentException('مبلغ پرداخت یا شناسه فاکتور معتبر نیست.');
  if(!in_array($paymentMethod,array('cash','bank','pos','transfer'),true))throw new InvalidArgumentException('روش پرداخت معتبر نیست.');
  $paymentKey=$paymentKey?:'purchase-payment-'.$purchaseId;
  if($paidMinor>0&&($cashAccountId<1||strlen($paymentKey)<8))throw new InvalidArgumentException('برای پرداخت، حساب صندوق/بانک و کلید یکتا الزامی است.');
  $now=gmdate('Y-m-d H:i:s');
  return MDA_Database::transaction(static function(PDO $db)use($purchaseId,$paidMinor,$cashAccountId,$paymentMethod,$paymentKey,$now):array{
   $q=$db->prepare('SELECT * FROM mda_purchase_documents WHERE id=:id');$q->execute(array(':id'=>$purchaseId));$doc=$q->fetch();
   if(!$doc)throw new RuntimeException('فاکتور خرید پیدا نشد.');
   if('posted'===$doc['document_status']){if((int)$doc['paid_minor']!==$paidMinor)throw new RuntimeException('این فاکتور قبلاً با مبلغ پرداخت دیگری قطعی شده است.');return array('purchase_id'=>$purchaseId,'duplicate'=>true,'document_id'=>(int)$doc['accounting_document_id']);}
   if('draft'!==$doc['document_status'])throw new RuntimeException('فقط فاکتور پیش‌نویس قابل قطعی‌سازی است.');
   if($paidMinor>(int)$doc['total_minor'])throw new InvalidArgumentException('مبلغ پرداخت نمی‌تواند از جمع فاکتور بیشتر باشد.');
   $closed=$db->prepare("SELECT 1 FROM mda_financial_periods WHERE status='closed' AND :date BETWEEN starts_on AND ends_on");$closed->execute(array(':date'=>$doc['document_date']));if($closed->fetchColumn())throw new RuntimeException('ثبت خرید در دوره مالی بسته‌شده مجاز نیست.');
   $lines=$db->prepare('SELECT * FROM mda_purchase_lines WHERE purchase_id=:id ORDER BY line_number');$lines->execute(array(':id'=>$purchaseId));$items=$lines->fetchAll();if(!$items)throw new RuntimeException('فاکتور فاقد ردیف کالا است.');
   $supplierAccount=self::account($db,'2100',true);$inventoryAccount=self::account($db,'1300',false);$cashAccount=$paidMinor>0?self::accountById($db,$cashAccountId):null;if($paidMinor>0)self::assert_cash_balance($db,$cashAccountId,(string)$doc['currency_code'],$paidMinor);
   $mapping=$db->prepare("SELECT 1 FROM mda_party_control_accounts WHERE role_key='supplier' AND account_id=:account");$mapping->execute(array(':account'=>$supplierAccount));if(!$mapping->fetchColumn())throw new RuntimeException('حساب پرداختنی به تفصیلی تأمین‌کنندگان متصل نشده است.');
   $party=$db->prepare("SELECT 1 FROM mda_party_roles WHERE party_id=:party AND role_key='supplier'");$party->execute(array(':party'=>$doc['supplier_party_id']));if(!$party->fetchColumn())throw new RuntimeException('طرف‌حساب این فاکتور تأمین‌کننده نیست.');
   $subtotal=(int)$doc['subtotal_minor'];$ancillary=(int)$doc['ancillary_cost_minor'];$total=(int)$doc['total_minor'];$allocations=self::allocate($items,$ancillary,$subtotal);
   $documentNumber='MDA-PUR-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));$idempotency='purchase-accounting-'.$purchaseId;
   $journal=$db->prepare("INSERT INTO mda_documents (document_number,document_type,document_status,party_id,source_type,source_id,idempotency_key,payload_hash,currency_code,amount_scale,document_date,description,created_by,created_at,posted_at) VALUES (:number,'purchase','posted',:party,'purchase',:source,:key,:hash,:currency,1,:date,:description,:user,:now,:now)");
   $journal->execute(array(':number'=>$documentNumber,':party'=>$doc['supplier_party_id'],':source'=>(string)$purchaseId,':key'=>$idempotency,':hash'=>$doc['payload_hash'],':currency'=>$doc['currency_code'],':date'=>$doc['document_date'],':description'=>'ثبت فاکتور خرید '.$doc['document_number'],':user'=>get_current_user_id()?:null,':now'=>$now));
   $journalId=(int)$db->lastInsertId();$entry=$db->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,party_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,:num,:account,:party,:debit,:credit,:memo,:now)');
   $n=1;$entry->execute(array(':doc'=>$journalId,':num'=>$n++ ,':account'=>$inventoryAccount,':party'=>null,':debit'=>$total,':credit'=>0,':memo'=>'افزایش موجودی کالا بابت خرید',':now'=>$now));
   $unpaid=$total-$paidMinor;if($unpaid>0)$entry->execute(array(':doc'=>$journalId,':num'=>$n++ ,':account'=>$supplierAccount,':party'=>$doc['supplier_party_id'],':debit'=>0,':credit'=>$unpaid,':memo'=>'بدهی خرید به تأمین‌کننده',':now'=>$now));
   if($paidMinor>0)$entry->execute(array(':doc'=>$journalId,':num'=>$n++ ,':account'=>$cashAccount,':party'=>null,':debit'=>0,':credit'=>$paidMinor,':memo'=>'پرداخت نقدی/بانکی فاکتور خرید',':now'=>$now));
   $movement=$db->prepare("INSERT INTO mda_inventory_movements (product_id,variation_id,warehouse_id,movement_type,quantity_scaled,quantity_scale,unit_cost_minor,currency_code,source_type,source_id,idempotency_key,occurred_at,created_by) VALUES (:product,:variation,:warehouse,'purchase_receipt',:qty,:scale,:cost,:currency,'purchase',:source,:key,:now,:user)");
   $price=$db->prepare('INSERT INTO mda_purchase_price_history (purchase_id,purchase_line_id,product_id,variation_id,supplier_party_id,quantity_scaled,quantity_scale,unit_cost_minor,landed_unit_cost_minor,currency_code,recorded_at) VALUES (:purchase,:line,:product,:variation,:supplier,:qty,:scale,:unit,:landed,:currency,:now)');
   $costHistory=$db->prepare('INSERT INTO mda_product_cost_history (purchase_id,purchase_line_id,product_id,variation_id,warehouse_id,quantity_before_scaled,quantity_after_scaled,average_cost_before_minor,average_cost_after_minor,currency_code,recorded_at) VALUES (:purchase,:line,:product,:variation,:warehouse,:before,:after,:oldcost,:newcost,:currency,:now)');
   $state=$db->prepare('SELECT quantity_scaled,quantity_scale,average_cost_minor,currency_code FROM mda_product_cost_state WHERE product_id=:product AND variation_id=:variation AND warehouse_id=:warehouse');
   $upsert=$db->prepare('INSERT INTO mda_product_cost_state (product_id,variation_id,warehouse_id,quantity_scaled,quantity_scale,average_cost_minor,currency_code,updated_at) VALUES (:product,:variation,:warehouse,:qty,:scale,:cost,:currency,:now) ON CONFLICT(product_id,variation_id,warehouse_id) DO UPDATE SET quantity_scaled=excluded.quantity_scaled,quantity_scale=excluded.quantity_scale,average_cost_minor=excluded.average_cost_minor,currency_code=excluded.currency_code,updated_at=excluded.updated_at');
   foreach($items as $i=>$line){
    $qty=(int)$line['quantity_scaled'];$scale=(int)$line['quantity_scale'];$beforeStock=(int)$db->query('SELECT COALESCE(SUM(quantity_scaled),0) FROM mda_inventory_movements WHERE product_id='.(int)$line['product_id'].' AND variation_id='.(int)$line['variation_id'].' AND warehouse_id='.(int)$doc['warehouse_id'].' AND quantity_scale='.$scale)->fetchColumn();
    $state->execute(array(':product'=>$line['product_id'],':variation'=>$line['variation_id'],':warehouse'=>$doc['warehouse_id']));$old=$state->fetch();
    if($old){if((int)$old['quantity_scale']!==$scale||$old['currency_code']!==$doc['currency_code']||(int)$old['quantity_scaled']!==$beforeStock)throw new RuntimeException('وضعیت بهای تمام‌شده با کاردکس موجودی سازگار نیست؛ ابتدا مانده افتتاحیه و بهای آن را تعیین کنید.');$oldQty=(int)$old['quantity_scaled'];$oldCost=(int)$old['average_cost_minor'];}
    else{if($beforeStock!==0)throw new RuntimeException('برای موجودی قبلی این کالا، بهای افتتاحیه ثبت نشده است.');$oldQty=0;$oldCost=0;}
    $landed=self::mulDivRound(self::safeAdd((int)$line['line_total_minor'],(int)$allocations[$i]),$scale,$qty);
    $newQty=self::safeAdd($oldQty,$qty);$oldValue=self::safeMul($oldQty,$oldCost);$newValue=self::safeMul($qty,$landed);$newCost=self::mulDivRound(self::safeAdd($oldValue,$newValue),1,$newQty);
    $movement->execute(array(':product'=>$line['product_id'],':variation'=>$line['variation_id'],':warehouse'=>$doc['warehouse_id'],':qty'=>$qty,':scale'=>$scale,':cost'=>$landed,':currency'=>$doc['currency_code'],':source'=>$purchaseId,':key'=>'purchase-receipt-'.$purchaseId.'-'.$line['id'],':now'=>$now,':user'=>get_current_user_id()?:null));
    $price->execute(array(':purchase'=>$purchaseId,':line'=>$line['id'],':product'=>$line['product_id'],':variation'=>$line['variation_id'],':supplier'=>$doc['supplier_party_id'],':qty'=>$qty,':scale'=>$scale,':unit'=>$line['unit_cost_minor'],':landed'=>$landed,':currency'=>$doc['currency_code'],':now'=>$now));
    $costHistory->execute(array(':purchase'=>$purchaseId,':line'=>$line['id'],':product'=>$line['product_id'],':variation'=>$line['variation_id'],':warehouse'=>$doc['warehouse_id'],':before'=>$oldQty,':after'=>$newQty,':oldcost'=>$oldCost,':newcost'=>$newCost,':currency'=>$doc['currency_code'],':now'=>$now));
    $upsert->execute(array(':product'=>$line['product_id'],':variation'=>$line['variation_id'],':warehouse'=>$doc['warehouse_id'],':qty'=>$newQty,':scale'=>$scale,':cost'=>$newCost,':currency'=>$doc['currency_code'],':now'=>$now));
    $lineUpdate=$db->prepare('UPDATE mda_purchase_lines SET allocated_cost_minor=:allocation WHERE id=:id');$lineUpdate->execute(array(':allocation'=>$allocations[$i],':id'=>$line['id']));
   }
   $fulfilled=array();foreach($items as $line){$k=(int)$line['product_id'].'-'.(int)$line['variation_id'];if(isset($fulfilled[$k]))continue;$fulfilled[$k]=MDA_Supply_Service::fulfil($db,(int)$line['product_id'],(int)$line['variation_id'],(int)$doc['warehouse_id'],(string)$doc['document_date'],'purchase',(string)$purchaseId);}
   if($paidMinor>0){$payment=$db->prepare('INSERT INTO mda_purchase_payments (purchase_id,amount_minor,cash_account_id,payment_method,idempotency_key,paid_at,created_at) VALUES (:purchase,:amount,:account,:method,:key,:now,:now)');$payment->execute(array(':purchase'=>$purchaseId,':amount'=>$paidMinor,':account'=>$cashAccountId,':method'=>$paymentMethod,':key'=>$paymentKey,':now'=>$now));}
   $update=$db->prepare("UPDATE mda_purchase_documents SET document_status='posted',paid_minor=:paid,cash_account_id=:cash,accounting_document_id=:journal,posted_at=:now WHERE id=:id");
   $update->execute(array(':paid'=>$paidMinor,':cash'=>$paidMinor>0?$cashAccountId:null,':journal'=>$journalId,':now'=>$now,':id'=>$purchaseId));
   $audit=$db->prepare('INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,:action,:type,:id,:after,:now)');
   $audit->execute(array(':user'=>get_current_user_id()?:null,':action'=>'purchase_posted',':type'=>'purchase',':id'=>(string)$purchaseId,':after'=>wp_json_encode(array('document_id'=>$journalId,'paid_minor'=>$paidMinor,'total_minor'=>$total)),':now'=>$now));
   return array('purchase_id'=>$purchaseId,'document_id'=>$journalId,'duplicate'=>false,'paid_minor'=>$paidMinor,'payable_minor'=>$unpaid,'total_minor'=>$total);
  });
 }

 public static function get(int $id): ?array {
  $q=MDA_Database::connection()->prepare('SELECT p.*,s.display_name AS supplier_name,w.warehouse_name FROM mda_purchase_documents p JOIN mda_parties s ON s.id=p.supplier_party_id JOIN mda_warehouses w ON w.id=p.warehouse_id WHERE p.id=:id');$q->execute(array(':id'=>$id));$doc=$q->fetch();if(!$doc)return null;
  $l=MDA_Database::connection()->prepare('SELECT * FROM mda_purchase_lines WHERE purchase_id=:id ORDER BY line_number');$l->execute(array(':id'=>$id));$doc['lines']=$l->fetchAll();return $doc;
 }
 public static function all(int $limit=100): array {return MDA_Database::connection()->query('SELECT p.id,p.document_number,p.document_date,p.document_status,p.total_minor,p.paid_minor,s.display_name AS supplier_name FROM mda_purchase_documents p JOIN mda_parties s ON s.id=p.supplier_party_id ORDER BY p.id DESC LIMIT '.max(1,min(500,$limit)))->fetchAll();}
 public static function price_history(int $productId,int $variationId=0,int $limit=100): array {$q=MDA_Database::connection()->prepare('SELECT h.*,p.document_number,s.display_name AS supplier_name FROM mda_purchase_price_history h JOIN mda_purchase_documents p ON p.id=h.purchase_id JOIN mda_parties s ON s.id=h.supplier_party_id WHERE h.product_id=:product AND h.variation_id=:variation ORDER BY h.recorded_at DESC,h.id DESC LIMIT :limit');$q->bindValue(':product',$productId,PDO::PARAM_INT);$q->bindValue(':variation',$variationId,PDO::PARAM_INT);$q->bindValue(':limit',max(1,min(300,$limit)),PDO::PARAM_INT);$q->execute();return $q->fetchAll();}
 public static function cost_state(int $productId,int $warehouseId,int $variationId=0):?array {$q=MDA_Database::connection()->prepare('SELECT * FROM mda_product_cost_state WHERE product_id=:product AND variation_id=:variation AND warehouse_id=:warehouse');$q->execute(array(':product'=>$productId,':variation'=>$variationId,':warehouse'=>$warehouseId));$r=$q->fetch();return $r?:null;}
 private static function account(PDO $db,string $code,bool $control):int {$q=$db->prepare('SELECT id,is_control FROM mda_accounts WHERE account_code=:code AND is_active=1 AND archived_at IS NULL');$q->execute(array(':code'=>$code));$r=$q->fetch();if(!$r||((int)$r['is_control']===1)!==$control)throw new RuntimeException('حساب سیستمی '.$code.' وجود ندارد یا تنظیم آن نادرست است.');return(int)$r['id'];}
 private static function accountById(PDO $db,int $id):int {$q=$db->prepare("SELECT id FROM mda_accounts WHERE id=:id AND account_code GLOB '11*' AND account_type='asset' AND is_active=1 AND archived_at IS NULL AND is_control=0 AND allow_manual_entry=1");$q->execute(array(':id'=>$id));if(!$q->fetchColumn())throw new InvalidArgumentException('حساب پرداخت باید صندوق یا بانک فعال و مجاز برای ثبت دستی باشد.');return$id;}
 private static function assert_cash_balance(PDO $db,int $accountId,string $currency,int $amount):void {$q=$db->prepare("SELECT COALESCE(SUM(j.debit_minor-j.credit_minor),0) FROM mda_journal_entries j JOIN mda_documents d ON d.id=j.document_id WHERE j.account_id=:account AND d.currency_code=:currency AND d.document_status='posted'");$q->execute(array(':account'=>$accountId,':currency'=>$currency));if((int)$q->fetchColumn()<$amount)throw new RuntimeException('موجودی حساب صندوق/بانک برای پرداخت اولیه خرید کافی نیست.');}
 private static function allocate(array $lines,int $amount,int $subtotal):array {$out=array();$used=0;$last=count($lines)-1;foreach($lines as $i=>$line){if($i===$last){$out[]=$amount-$used;continue;}$part=$subtotal>0?self::mulDiv($amount,(int)$line['line_total_minor'],$subtotal):0;$out[]=$part;$used=self::safeAdd($used,$part);}return$out;}
 private static function safeAdd(int $a,int $b):int {if($b>0&&$a>PHP_INT_MAX-$b)throw new OverflowException('مجموع مبلغ از ظرفیت عدد صحیح بیشتر است.');return$a+$b;}
 private static function safeMul(int $a,int $b):int {if($a<0||$b<0||($a>0&&$b>intdiv(PHP_INT_MAX,$a)))throw new OverflowException('حاصل‌ضرب برای محاسبه دقیق خارج از ظرفیت عدد صحیح است.');return$a*$b;}
 private static function mulDiv(int $a,int $b,int $divisor):int {if($divisor<1)throw new InvalidArgumentException('مقسوم‌علیه نامعتبر است.');return intdiv(self::safeMul($a,$b),$divisor);}
 private static function mulDivRound(int $a,int $b,int $divisor):int {if($divisor<1)throw new InvalidArgumentException('مقسوم‌علیه نامعتبر است.');$product=self::safeMul($a,$b);$quotient=intdiv($product,$divisor);$remainder=$product%$divisor;if($remainder>=intdiv($divisor,2)+($divisor%2))$quotient=self::safeAdd($quotient,1);return$quotient;}
}

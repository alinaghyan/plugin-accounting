<?php
defined( 'ABSPATH' ) || exit;

/** Immutable product returns with original-sale revenue, tax and COGS snapshots. */
final class MDA_Sales_Return_Service {
 public static function post(int $saleId,array $lines,string $key,?string $date=null):array {
  if($saleId<1||!$lines||count($lines)>200||strlen($key)<12||strlen($key)>191)throw new InvalidArgumentException('اطلاعات مرجوعی فروش معتبر نیست.');
  $date=$date?:current_time('Y-m-d');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('تاریخ مرجوعی معتبر نیست.');
  $clean=array();foreach($lines as $line){$lineId=(int)($line['sales_line_id']??0);$qty=$line['quantity_scaled']??null;if($lineId<1||!is_int($qty)||$qty<1)throw new InvalidArgumentException('ردیف یا مقدار مرجوعی معتبر نیست.');$clean[]=array('sales_line_id'=>$lineId,'quantity_scaled'=>$qty);}
  usort($clean,static fn($a,$b)=>$a['sales_line_id']<=>$b['sales_line_id']);$hash=hash('sha256',wp_json_encode(array($saleId,$date,$clean)));$now=gmdate('Y-m-d H:i:s');
  return MDA_Database::transaction(static function(PDO $db)use($saleId,$clean,$key,$date,$hash,$now):array {
   $old=$db->prepare('SELECT id,payload_hash,accounting_document_id,total_minor FROM mda_sales_returns WHERE idempotency_key=:key');$old->execute(array(':key'=>$key));$existing=$old->fetch();
   if($existing){if(!hash_equals((string)$existing['payload_hash'],$hash))throw new RuntimeException('کلید یکتا برای مرجوعی متفاوتی استفاده شده است.');return array('return_id'=>(int)$existing['id'],'document_id'=>(int)$existing['accounting_document_id'],'total_minor'=>(int)$existing['total_minor'],'duplicate'=>true);}
   $q=$db->prepare("SELECT * FROM mda_sales_documents WHERE id=:id AND status='posted'");$q->execute(array(':id'=>$saleId));$sale=$q->fetch();if(!$sale)throw new RuntimeException('فقط فروش قطعی قابل مرجوعی است.');
   $closed=$db->prepare("SELECT 1 FROM mda_financial_periods WHERE status='closed' AND :date BETWEEN starts_on AND ends_on LIMIT 1");$closed->execute(array(':date'=>$date));if($closed->fetchColumn())throw new RuntimeException('ثبت مرجوعی در دوره مالی بسته‌شده مجاز نیست.');
   $party=$db->prepare("SELECT 1 FROM mda_party_roles WHERE party_id=:id AND role_key='customer'");$party->execute(array(':id'=>$sale['party_id']));if(!$party->fetchColumn())throw new RuntimeException('تفصیلی مشتری برای این فروش معتبر نیست.');
   $accounts=array();$accountQuery=$db->prepare("SELECT id FROM mda_accounts WHERE account_code=:code AND is_active=1 AND archived_at IS NULL");
   foreach(array('1200','1300','2200','4110','5100') as $code){$accountQuery->execute(array(':code'=>$code));$id=$accountQuery->fetchColumn();if(!$id&&$code!=='2200')throw new RuntimeException('حساب سیستمی '.$code.' تنظیم نشده است.');$accounts[$code]=(int)$id;}
   $mapping=$db->prepare("SELECT 1 FROM mda_party_control_accounts WHERE role_key='customer' AND account_id=:id");$mapping->execute(array(':id'=>$accounts['1200']));if(!$mapping->fetchColumn())throw new RuntimeException('حساب دریافتنی به تفصیلی مشتریان متصل نیست.');
   $source=$db->prepare('SELECT * FROM mda_sales_lines WHERE id=:line AND sales_document_id=:sale');
   $returned=$db->prepare('SELECT COALESCE(SUM(quantity_scaled),0) qty,COALESCE(SUM(revenue_minor),0) revenue,COALESCE(SUM(tax_minor),0) tax,COALESCE(SUM(cogs_minor),0) cogs FROM mda_sales_return_lines WHERE sales_line_id=:line');
   $stock=$db->prepare('SELECT COALESCE(SUM(quantity_scaled),0) qty,COUNT(DISTINCT quantity_scale) scales FROM mda_inventory_movements WHERE product_id=:product AND variation_id=:variation AND warehouse_id=:warehouse');
   $state=$db->prepare('SELECT quantity_scaled,quantity_scale,average_cost_minor,currency_code FROM mda_product_cost_state WHERE product_id=:product AND variation_id=:variation AND warehouse_id=:warehouse');
   $prepared=array();$seen=array();$productTotal=0;$taxTotal=0;$cogsTotal=0;
   foreach($clean as $line){
    $source->execute(array(':line'=>$line['sales_line_id'],':sale'=>$saleId));$original=$source->fetch();if(!$original)throw new InvalidArgumentException('ردیف انتخاب‌شده متعلق به این فاکتور فروش نیست.');
    $stockKey=(int)$original['product_id'].':'.(int)$original['variation_id'];if(isset($seen[$stockKey]))throw new InvalidArgumentException('برای هر کالا/تنوع فقط یک ردیف مرجوعی ثبت کنید.');$seen[$stockKey]=true;
    $returned->execute(array(':line'=>$original['id']));$prior=$returned->fetch();$remaining=(int)$original['quantity_scaled']-(int)$prior['qty'];$qty=$line['quantity_scaled'];if($qty>$remaining)throw new RuntimeException('مقدار مرجوعی از مقدار فروش مرجوع‌نشده بیشتر است.');
    if((int)$sale['tax_total_minor']>0&&(int)$sale['tax_snapshot_complete']!==1)throw new RuntimeException('مالیات ردیفی این فروش قدیمی snapshot نشده است؛ مرجوعی مالیاتی دقیق ممکن نیست و باید دستی بررسی شود.');
    $revRemaining=(int)$original['revenue_minor']-(int)$prior['revenue'];$taxRemaining=(int)$original['tax_minor']-(int)$prior['tax'];$cogsRemaining=(int)$original['cogs_minor']-(int)$prior['cogs'];
    $revenue=$qty===$remaining?$revRemaining:min($revRemaining,self::mulDivRound($qty,(int)$original['revenue_minor'],(int)$original['quantity_scaled']));
    $tax=$qty===$remaining?$taxRemaining:min($taxRemaining,self::mulDivRound($qty,(int)$original['tax_minor'],(int)$original['quantity_scaled']));
    $cogs=$qty===$remaining?$cogsRemaining:min($cogsRemaining,self::mulDivRound($qty,(int)$original['cogs_minor'],(int)$original['quantity_scaled']));
    $scale=(int)$original['quantity_scale'];$stock->execute(array(':product'=>$original['product_id'],':variation'=>$original['variation_id'],':warehouse'=>$sale['warehouse_id']));$stockRow=$stock->fetch();$balance=(int)$stockRow['qty'];
    $state->execute(array(':product'=>$original['product_id'],':variation'=>$original['variation_id'],':warehouse'=>$sale['warehouse_id']));$cost=$state->fetch();
    if(!$cost||(int)$cost['quantity_scale']!==$scale||(int)$stockRow['scales']!==1||(int)$cost['quantity_scaled']!==$balance||$cost['currency_code']!==$sale['currency_code'])throw new RuntimeException('وضعیت کاردکس یا بهای تمام‌شده این کالا ناسازگار است؛ مرجوعی متوقف شد.');
    if($qty>PHP_INT_MAX-$balance)throw new OverflowException('موجودی پس از مرجوعی از ظرفیت عددی بیشتر می‌شود.');
    $newQty=$balance+$qty;$newAverage=self::weightedAverage($balance,(int)$cost['average_cost_minor'],$qty,(int)$original['unit_cost_minor']);
    $productTotal=self::safeAdd($productTotal,$revenue);$taxTotal=self::safeAdd($taxTotal,$tax);$cogsTotal=self::safeAdd($cogsTotal,$cogs);
    $prepared[]=array('source'=>$original,'qty'=>$qty,'scale'=>$scale,'revenue'=>$revenue,'tax'=>$tax,'cogs'=>$cogs,'unit'=>(int)$original['unit_cost_minor'],'new_qty'=>$newQty,'new_average'=>$newAverage);
   }
   $total=self::safeAdd($productTotal,$taxTotal);if($total<1)throw new RuntimeException('مبلغ مرجوعی باید مثبت باشد.');
   $number='MDA-SRET-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
   $doc=$db->prepare("INSERT INTO mda_documents (document_number,document_type,document_status,party_id,source_type,source_id,idempotency_key,payload_hash,currency_code,amount_scale,document_date,description,created_by,created_at,posted_at) VALUES (:number,'sales_return','posted',:party,'sales_return',:source,:key,:hash,:currency,1,:date,:description,:user,:now,:now)");
   $doc->execute(array(':number'=>$number,':party'=>$sale['party_id'],':source'=>(string)$saleId,':key'=>'sales-return-journal-'.$key,':hash'=>$hash,':currency'=>$sale['currency_code'],':date'=>$date,':description'=>'مرجوعی فروش '.$sale['document_number'],':user'=>get_current_user_id()?:null,':now'=>$now));$journalId=(int)$db->lastInsertId();
   $entries=array();if($productTotal>0)$entries[]=array($accounts['4110'],null,0,$productTotal,'برگشت از فروش کالا');
   if($taxTotal>0){if(!$accounts['2200'])throw new RuntimeException('حساب مالیات پرداختنی برای برگشت مالیات تنظیم نشده است.');$entries[]=array($accounts['2200'],null,$taxTotal,0,'اصلاح مالیات فروش مرجوع‌شده');}
   $entries[]=array($accounts['1200'],(int)$sale['party_id'],0,$total,'کاهش دریافتنی / ایجاد بستانکاری مشتری بابت مرجوعی');
   if($cogsTotal>0){$entries[]=array($accounts['1300'],null,$cogsTotal,0,'افزایش موجودی کالای مرجوعی');$entries[]=array($accounts['5100'],null,0,$cogsTotal,'برگشت بهای تمام‌شده کالای مرجوعی');}
   $entry=$db->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,party_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,:num,:account,:party,:debit,:credit,:memo,:now)');
   foreach($entries as $i=>$line)$entry->execute(array(':doc'=>$journalId,':num'=>$i+1,':account'=>$line[0],':party'=>$line[1],':debit'=>$line[2],':credit'=>$line[3],':memo'=>$line[4],':now'=>$now));
   $head=$db->prepare('INSERT INTO mda_sales_returns (sales_document_id,order_id,party_id,warehouse_id,return_number,document_date,currency_code,product_total_minor,tax_total_minor,total_minor,cogs_reversed_minor,idempotency_key,payload_hash,accounting_document_id,created_by,created_at) VALUES (:sale,:order,:party,:warehouse,:number,:date,:currency,:products,:tax,:total,:cogs,:key,:hash,:journal,:user,:now)');
   $head->execute(array(':sale'=>$saleId,':order'=>$sale['order_id'],':party'=>$sale['party_id'],':warehouse'=>$sale['warehouse_id'],':number'=>$number,':date'=>$date,':currency'=>$sale['currency_code'],':products'=>$productTotal,':tax'=>$taxTotal,':total'=>$total,':cogs'=>$cogsTotal,':key'=>$key,':hash'=>$hash,':journal'=>$journalId,':user'=>get_current_user_id()?:null,':now'=>$now));$returnId=(int)$db->lastInsertId();
   $insert=$db->prepare('INSERT INTO mda_sales_return_lines (sales_return_id,sales_line_id,product_id,variation_id,quantity_scaled,quantity_scale,revenue_minor,tax_minor,cogs_minor,unit_cost_minor,created_at) VALUES (:return,:line,:product,:variation,:qty,:scale,:revenue,:tax,:cogs,:unit,:now)');
   $movement=$db->prepare("INSERT INTO mda_inventory_movements (product_id,variation_id,warehouse_id,movement_type,quantity_scaled,quantity_scale,unit_cost_minor,currency_code,source_type,source_id,idempotency_key,occurred_at,created_by) VALUES (:product,:variation,:warehouse,'customer_return',:qty,:scale,:unit,:currency,'sales_return',:source,:key,:now,:user)");
   $update=$db->prepare('UPDATE mda_product_cost_state SET quantity_scaled=:qty,average_cost_minor=:average,updated_at=:now WHERE product_id=:product AND variation_id=:variation AND warehouse_id=:warehouse');
   foreach($prepared as $item){$line=$item['source'];$insert->execute(array(':return'=>$returnId,':line'=>$line['id'],':product'=>$line['product_id'],':variation'=>$line['variation_id'],':qty'=>$item['qty'],':scale'=>$item['scale'],':revenue'=>$item['revenue'],':tax'=>$item['tax'],':cogs'=>$item['cogs'],':unit'=>$item['unit'],':now'=>$now));
    $movement->execute(array(':product'=>$line['product_id'],':variation'=>$line['variation_id'],':warehouse'=>$sale['warehouse_id'],':qty'=>$item['qty'],':scale'=>$item['scale'],':unit'=>$item['unit'],':currency'=>$sale['currency_code'],':source'=>(string)$returnId,':key'=>'customer-return-'.$returnId.'-'.$line['id'],':now'=>$now,':user'=>get_current_user_id()?:null));
    $update->execute(array(':qty'=>$item['new_qty'],':average'=>$item['new_average'],':now'=>$now,':product'=>$line['product_id'],':variation'=>$line['variation_id'],':warehouse'=>$sale['warehouse_id']));if($update->rowCount()!==1)throw new RuntimeException('به‌روزرسانی میانگین موزون پس از مرجوعی انجام نشد.');
   }
   $db->prepare("INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,'sales_return_posted','sales_return',:id,:json,:now)")->execute(array(':user'=>get_current_user_id()?:null,':id'=>(string)$returnId,':json'=>wp_json_encode(array('sale_id'=>$saleId,'total_minor'=>$total,'cogs_reversed_minor'=>$cogsTotal,'journal_id'=>$journalId)),':now'=>$now));
   return array('return_id'=>$returnId,'document_id'=>$journalId,'total_minor'=>$total,'cogs_reversed_minor'=>$cogsTotal,'duplicate'=>false);
  });
 }
 public static function sales(int $limit=200):array {
  return MDA_Database::connection()->query('SELECT s.id,s.document_number,s.order_id,s.document_date,s.currency_code,s.total_minor,s.source_type,p.display_name,COALESCE(SUM(r.total_minor),0) returned_minor FROM mda_sales_documents s JOIN mda_parties p ON p.id=s.party_id LEFT JOIN mda_sales_returns r ON r.sales_document_id=s.id GROUP BY s.id ORDER BY s.id DESC LIMIT '.max(1,min(500,$limit)))->fetchAll();
 }
 public static function sale(int $saleId):?array {$q=MDA_Database::connection()->prepare('SELECT s.*,p.display_name,p.phone FROM mda_sales_documents s JOIN mda_parties p ON p.id=s.party_id WHERE s.id=:id');$q->execute(array(':id'=>$saleId));$row=$q->fetch();return$row?:null;}
 public static function sale_lines(int $saleId):array {
  $q=MDA_Database::connection()->prepare('SELECT l.*,COALESCE(SUM(r.quantity_scaled),0) returned_quantity,COALESCE(SUM(r.revenue_minor),0) returned_revenue,COALESCE(SUM(r.tax_minor),0) returned_tax FROM mda_sales_lines l LEFT JOIN mda_sales_return_lines r ON r.sales_line_id=l.id WHERE l.sales_document_id=:id GROUP BY l.id ORDER BY l.id');$q->execute(array(':id'=>$saleId));return$q->fetchAll();
 }
 public static function returns(int $saleId):array {$q=MDA_Database::connection()->prepare('SELECT r.*,d.document_number AS accounting_document_number,COALESCE((SELECT SUM(f.amount_minor) FROM mda_sales_refunds f WHERE f.sales_return_id=r.id),0) refunded_minor FROM mda_sales_returns r JOIN mda_documents d ON d.id=r.accounting_document_id WHERE r.sales_document_id=:id ORDER BY r.id DESC');$q->execute(array(':id'=>$saleId));return$q->fetchAll();}
 public static function return_record(int $returnId):?array {$q=MDA_Database::connection()->prepare('SELECT r.*,s.document_number AS sale_number,p.display_name FROM mda_sales_returns r JOIN mda_sales_documents s ON s.id=r.sales_document_id JOIN mda_parties p ON p.id=r.party_id WHERE r.id=:id');$q->execute(array(':id'=>$returnId));$row=$q->fetch();return$row?:null;}
 public static function return_lines(int $returnId):array {$q=MDA_Database::connection()->prepare('SELECT rl.*,l.product_name_snapshot,l.sku_snapshot FROM mda_sales_return_lines rl JOIN mda_sales_lines l ON l.id=rl.sales_line_id WHERE rl.sales_return_id=:id');$q->execute(array(':id'=>$returnId));return$q->fetchAll();}
 private static function safeAdd(int $a,int $b):int {if($b>0&&$a>PHP_INT_MAX-$b)throw new OverflowException('مجموع مبلغ مرجوعی بیش از ظرفیت عدد صحیح است.');return$a+$b;}
 private static function mulDivRound(int $a,int $b,int $d):int {if($a<0||$b<0||$d<1||($a>0&&$b>intdiv(PHP_INT_MAX,$a)))throw new OverflowException('محاسبه مبلغ مرجوعی بیش از ظرفیت است.');$v=$a*$b;$q=intdiv($v,$d);$r=$v%$d;if($r>=intdiv($d,2)+($d%2))$q++;return$q;}
 private static function weightedAverage(int $oldQty,int $oldCost,int $newQty,int $newCost):int {
  if($newQty<1||$oldQty<0||$oldCost<0||$newCost<0)throw new InvalidArgumentException('ورودی میانگین موزون معتبر نیست.');
  if($oldQty===0)return$newCost;$total=$oldQty+$newQty;if($total<=$oldQty)throw new OverflowException('مقدار موجودی بیش از ظرفیت است.');
  $delta=$newCost-$oldCost;$abs=abs($delta);if($abs>intdiv(PHP_INT_MAX,$newQty))throw new OverflowException('محاسبه میانگین موزون بیش از ظرفیت است.');
  $part=$abs*$newQty;$adjust=intdiv($part,$total);$rem=$part%$total;if($rem>=intdiv($total,2)+($total%2))$adjust++;
  return $delta>=0?$oldCost+$adjust:max(0,$oldCost-$adjust);
 }
}

/** Pays out only an actual customer credit balance, never an assumed WooCommerce gateway settlement. */
final class MDA_Sales_Refund_Service {
 public static function record(int $returnId,int $amount,int $cashAccountId,string $method,string $key,?string $date=null):array {
  if($returnId<1||$amount<1||$cashAccountId<1||!in_array($method,array('cash','bank','pos','transfer'),true)||strlen($key)<12||strlen($key)>191)throw new InvalidArgumentException('اطلاعات استرداد وجه معتبر نیست.');
  $date=$date?:current_time('Y-m-d');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('تاریخ استرداد معتبر نیست.');
  $hash=hash('sha256',wp_json_encode(array($returnId,$amount,$cashAccountId,$method,$date)));$now=gmdate('Y-m-d H:i:s');
  return MDA_Database::transaction(static function(PDO $db)use($returnId,$amount,$cashAccountId,$method,$key,$date,$hash,$now):array {
   $old=$db->prepare('SELECT id,payload_hash,accounting_document_id FROM mda_sales_refunds WHERE idempotency_key=:key');$old->execute(array(':key'=>$key));$existing=$old->fetch();
   if($existing){if(!hash_equals((string)$existing['payload_hash'],$hash))throw new RuntimeException('کلید یکتا برای استرداد متفاوتی استفاده شده است.');return array('refund_id'=>(int)$existing['id'],'document_id'=>(int)$existing['accounting_document_id'],'duplicate'=>true);}
   $q=$db->prepare('SELECT * FROM mda_sales_returns WHERE id=:id');$q->execute(array(':id'=>$returnId));$ret=$q->fetch();if(!$ret)throw new RuntimeException('سند مرجوعی فروش پیدا نشد.');
   $closed=$db->prepare("SELECT 1 FROM mda_financial_periods WHERE status='closed' AND :date BETWEEN starts_on AND ends_on LIMIT 1");$closed->execute(array(':date'=>$date));if($closed->fetchColumn())throw new RuntimeException('استرداد وجه در دوره مالی بسته‌شده مجاز نیست.');
   $paid=$db->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM mda_sales_refunds WHERE sales_return_id=:id');$paid->execute(array(':id'=>$returnId));$already=(int)$paid->fetchColumn();if($amount>(int)$ret['total_minor']-$already)throw new InvalidArgumentException('مجموع استردادها نمی‌تواند از مبلغ مرجوعی بیشتر شود.');
   $ar=$db->prepare("SELECT id FROM mda_accounts WHERE account_code='1200' AND account_type='asset' AND is_active=1 AND archived_at IS NULL AND is_control=1");$ar->execute();$arId=(int)$ar->fetchColumn();if(!$arId)throw new RuntimeException('حساب دریافتنی مشتریان تنظیم نشده است.');
   $mapping=$db->prepare("SELECT 1 FROM mda_party_control_accounts WHERE role_key='customer' AND account_id=:id");$mapping->execute(array(':id'=>$arId));if(!$mapping->fetchColumn())throw new RuntimeException('حساب دریافتنی به تفصیلی مشتریان متصل نیست.');
   $credit=$db->prepare('SELECT COALESCE(SUM(credit_minor),0)-COALESCE(SUM(debit_minor),0) FROM mda_journal_entries WHERE account_id=:account AND party_id=:party');$credit->execute(array(':account'=>$arId,':party'=>$ret['party_id']));$available=(int)$credit->fetchColumn();if($amount>$available)throw new RuntimeException('موجودی بستانکاری مشتری برای استرداد وجه کافی نیست؛ ابتدا مانده حساب مشتری را بررسی کنید.');
   $cash=$db->prepare("SELECT id FROM mda_accounts WHERE id=:id AND account_type='asset' AND is_active=1 AND archived_at IS NULL AND is_control=0");$cash->execute(array(':id'=>$cashAccountId));if(!$cash->fetchColumn())throw new InvalidArgumentException('حساب صندوق/بانک فعال نیست.');
   $number='MDA-SRF-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
   $doc=$db->prepare("INSERT INTO mda_documents (document_number,document_type,document_status,party_id,source_type,source_id,idempotency_key,payload_hash,currency_code,amount_scale,document_date,description,created_by,created_at,posted_at) VALUES (:number,'customer_refund','posted',:party,'sales_refund',:source,:key,:hash,:currency,1,:date,:description,:user,:now,:now)");
   $doc->execute(array(':number'=>$number,':party'=>$ret['party_id'],':source'=>(string)$returnId,':key'=>'sales-refund-journal-'.$key,':hash'=>$hash,':currency'=>$ret['currency_code'],':date'=>$date,':description'=>'استرداد وجه بابت مرجوعی '.$ret['return_number'],':user'=>get_current_user_id()?:null,':now'=>$now));$journalId=(int)$db->lastInsertId();
   $entry=$db->prepare('INSERT INTO mda_journal_entries (document_id,entry_number,account_id,party_id,debit_minor,credit_minor,memo,created_at) VALUES (:doc,:n,:account,:party,:debit,:credit,:memo,:now)');
   $entry->execute(array(':doc'=>$journalId,':n'=>1,':account'=>$arId,':party'=>$ret['party_id'],':debit'=>$amount,':credit'=>0,':memo'=>'تسویه بستانکاری مشتری بابت مرجوعی '.$ret['return_number'],':now'=>$now));
   $entry->execute(array(':doc'=>$journalId,':n'=>2,':account'=>$cashAccountId,':party'=>null,':debit'=>0,':credit'=>$amount,':memo'=>'استرداد وجه به مشتری '.$ret['party_id'],':now'=>$now));
   $insert=$db->prepare('INSERT INTO mda_sales_refunds (sales_return_id,sales_document_id,party_id,amount_minor,cash_account_id,payment_method,payment_date,idempotency_key,payload_hash,accounting_document_id,created_by,created_at) VALUES (:return,:sale,:party,:amount,:cash,:method,:date,:key,:hash,:journal,:user,:now)');
   $insert->execute(array(':return'=>$returnId,':sale'=>$ret['sales_document_id'],':party'=>$ret['party_id'],':amount'=>$amount,':cash'=>$cashAccountId,':method'=>$method,':date'=>$date,':key'=>$key,':hash'=>$hash,':journal'=>$journalId,':user'=>get_current_user_id()?:null,':now'=>$now));$id=(int)$db->lastInsertId();
   $db->prepare("INSERT INTO mda_audit_logs (user_id,action,object_type,object_id,after_json,created_at) VALUES (:user,'sales_refund_posted','sales_refund',:id,:json,:now)")->execute(array(':user'=>get_current_user_id()?:null,':id'=>(string)$id,':json'=>wp_json_encode(array('return_id'=>$returnId,'amount_minor'=>$amount,'cash_account_id'=>$cashAccountId)),':now'=>$now));
   return array('refund_id'=>$id,'document_id'=>$journalId,'duplicate'=>false);
  });
 }
 public static function refunds(int $returnId):array {$q=MDA_Database::connection()->prepare('SELECT f.*,a.account_code,a.account_name,d.document_number AS accounting_document_number FROM mda_sales_refunds f JOIN mda_accounts a ON a.id=f.cash_account_id JOIN mda_documents d ON d.id=f.accounting_document_id WHERE f.sales_return_id=:id ORDER BY f.id DESC');$q->execute(array(':id'=>$returnId));return$q->fetchAll();}
}

<?php
defined( 'ABSPATH' ) || exit;

/** One-way, auditable stock synchronization from SQLite inventory to WooCommerce CRUD. */
final class MDA_Stock_Sync_Service {
 private const BATCH_SIZE=100;
 public static function run_batch(int $warehouseId,int $limit=100):array {
  if(!class_exists('WooCommerce')||!function_exists('wc_get_product'))throw new RuntimeException('WooCommerce برای همگام‌سازی موجودی فعال نیست.');
  if(!MDA_Database::is_available())throw new RuntimeException('PDO_SQLite برای همگام‌سازی موجودی فعال نیست.');
  if($warehouseId<1)throw new InvalidArgumentException('انبار انتخاب‌شده معتبر نیست.');
  $limit=max(1,min(self::BATCH_SIZE,$limit));$db=MDA_Database::connection();$now=gmdate('Y-m-d H:i:s');
  $wh=$db->prepare('SELECT warehouse_name FROM mda_warehouses WHERE id=:id AND is_active=1');$wh->execute(array(':id'=>$warehouseId));$warehouseName=$wh->fetchColumn();if(!$warehouseName)throw new InvalidArgumentException('انبار وجود ندارد یا غیرفعال است.');
  $pending=(int)$db->query("SELECT COUNT(*) FROM mda_integration_outbox WHERE status IN ('pending','processing','failed')")->fetchColumn();
  $started=$now;$counts=array('scanned'=>0,'synced'=>0,'skipped'=>0,'failed'=>0);$cursorKey='wc_stock_sync_cursor_'.$warehouseId;$cursorQ=$db->prepare('SELECT setting_value FROM mda_settings WHERE setting_key=:key');$cursorQ->execute(array(':key'=>$cursorKey));$cursor=json_decode((string)$cursorQ->fetchColumn(),true);if(!is_array($cursor))$cursor=array('product_id'=>0,'variation_id'=>-1);
  $pageRows=array();$status='blocked';$message='';
  if($pending>0){$message='تا زمان تعیین تکلیف رویدادهای pending/processing/failed صف فروش، همگام‌سازی موجودی متوقف است.';}
  else {
   $missing=$db->prepare('SELECT DISTINCT m.product_id,m.variation_id FROM mda_inventory_movements m LEFT JOIN mda_product_cost_state c ON c.product_id=m.product_id AND c.variation_id=m.variation_id AND c.warehouse_id=m.warehouse_id WHERE m.warehouse_id=:warehouse AND c.product_id IS NULL LIMIT 1');$missing->execute(array(':warehouse'=>$warehouseId));$missingRow=$missing->fetch();
   if($missingRow){$status='failed';$message='برای محصول '.(int)$missingRow['product_id'].' وضعیت بهای تمام‌شده SQLite وجود ندارد؛ ابتدا کاردکس را اصلاح کنید.';}
   else {
    $q=$db->prepare('SELECT c.product_id,c.variation_id,c.quantity_scaled,c.quantity_scale,c.currency_code,COALESCE(SUM(m.quantity_scaled),0) ledger_quantity,COUNT(DISTINCT m.quantity_scale) scale_count FROM mda_product_cost_state c LEFT JOIN mda_inventory_movements m ON m.product_id=c.product_id AND m.variation_id=c.variation_id AND m.warehouse_id=c.warehouse_id WHERE c.warehouse_id=:warehouse AND (c.product_id>:product OR (c.product_id=:product AND c.variation_id>:variation)) GROUP BY c.product_id,c.variation_id ORDER BY c.product_id,c.variation_id LIMIT :limit');
    $q->bindValue(':warehouse',$warehouseId,PDO::PARAM_INT);$q->bindValue(':product',(int)$cursor['product_id'],PDO::PARAM_INT);$q->bindValue(':variation',(int)$cursor['variation_id'],PDO::PARAM_INT);$q->bindValue(':limit',$limit,PDO::PARAM_INT);$q->execute();$pageRows=$q->fetchAll();
    $insert=$db->prepare('INSERT INTO mda_stock_sync_items (run_id,warehouse_id,product_id,variation_id,quantity_scaled,quantity_scale,sqlite_quantity,woo_quantity_before,woo_quantity_after,status,message,created_at) VALUES (:run,:warehouse,:product,:variation,:qty,:scale,:sqlite,:before,:after,:status,:message,:now)');
    foreach($pageRows as $row){
     $counts['scanned']++;$productId=(int)$row['product_id'];$variationId=(int)$row['variation_id'];$qty=(int)$row['quantity_scaled'];$scale=(int)$row['quantity_scale'];$before=null;$after=null;$itemStatus='failed';$itemMessage='';
     try{
      if($scale<1||!self::isPowerOfTen($scale)||(int)$row['scale_count']!==1||(int)$row['ledger_quantity']!==$qty)throw new RuntimeException('کاردکس و وضعیت بهای تمام‌شده با هم سازگار نیستند یا مقیاس مقدار پشتیبانی نمی‌شود.');
      if($qty<0)throw new RuntimeException('موجودی SQLite منفی است؛ همگام‌سازی خودکار مجاز نیست.');
      $product=wc_get_product($variationId?:$productId);if(!$product||!is_object($product))throw new RuntimeException('محصول/تنوع در WooCommerce پیدا نشد.');
      $manages=method_exists($product,'managing_stock')?$product->managing_stock():(method_exists($product,'get_manage_stock')?(bool)$product->get_manage_stock():false);
      if(!$manages){$itemStatus='skipped';$itemMessage='مدیریت موجودی برای این محصول در WooCommerce خاموش است.';}
      elseif(method_exists($product,'get_manage_stock')&&$product->get_manage_stock()==='parent'){$itemStatus='skipped';$itemMessage='موجودی این تنوع از محصول والد به ارث می‌رسد؛ تفکیک آن در کاردکس SQLite پشتیبانی نشده است.';}
      else{
       $before=(string)$product->get_stock_quantity();$target=self::formatQuantity($qty,$scale);
       $product->set_stock_quantity($target);$product->set_stock_status($qty>0?'instock':'outofstock');$product->save();
       $after=(string)$product->get_stock_quantity();$itemStatus='synced';$itemMessage='موجودی WooCommerce از مانده دفتر SQLite به‌روزرسانی شد.';
      }
     }catch(Throwable $e){$itemStatus='failed';$itemMessage=substr($e->getMessage(),0,500);}
     $counts[$itemStatus==='synced'?'synced':($itemStatus==='skipped'?'skipped':'failed')]++;
     $insert->execute(array(':run'=>0,':warehouse'=>$warehouseId,':product'=>$productId,':variation'=>$variationId,':qty'=>$qty,':scale'=>$scale,':sqlite'=>self::formatQuantity(max(0,$qty),max(1,$scale)),':before'=>$before,':after'=>$after,':status'=>$itemStatus,':message'=>$itemMessage,':now'=>gmdate('Y-m-d H:i:s')));
    }
    $last=end($pageRows);$hasMore=count($pageRows)===$limit;
    $nextCursor=$hasMore?array('product_id'=>(int)$last['product_id'],'variation_id'=>(int)$last['variation_id']):array('product_id'=>0,'variation_id'=>-1);
    $db->prepare('INSERT OR REPLACE INTO mda_settings (setting_key,setting_value,updated_at) VALUES (:key,:value,:now)')->execute(array(':key'=>$cursorKey,':value'=>wp_json_encode($nextCursor),':now'=>gmdate('Y-m-d H:i:s')));
    $status=$counts['failed']>0?'partial':($hasMore?'partial':'completed');$message=$hasMore?'دسته بعدی برای ادامه همگام‌سازی برنامه‌ریزی می‌شود.':'این دسته از انبار انتخاب‌شده همگام شد.';
   }
  }
  $finished=gmdate('Y-m-d H:i:s');$db->prepare('INSERT INTO mda_stock_sync_runs (warehouse_id,started_at,finished_at,scanned_count,synced_count,skipped_count,failed_count,pending_events_count,next_product_id,next_variation_id,status,message,created_at) VALUES (:warehouse,:started,:finished,:scanned,:synced,:skipped,:failed,:pending,:product,:variation,:status,:message,:created)')->execute(array(':warehouse'=>$warehouseId,':started'=>$started,':finished'=>$finished,':scanned'=>$counts['scanned'],':synced'=>$counts['synced'],':skipped'=>$counts['skipped'],':failed'=>$counts['failed'],':pending'=>$pending,':product'=>(int)$cursor['product_id'],':variation'=>(int)$cursor['variation_id'],':status'=>$status,':message'=>$message,':created'=>$finished));$runId=(int)$db->lastInsertId();
  if($pageRows){$db->prepare('UPDATE mda_stock_sync_items SET run_id=:run WHERE run_id=0 AND created_at>=:started')->execute(array(':run'=>$runId,':started'=>$started));}
  if($status==='partial'&&$pending===0&&!wp_next_scheduled('mda_woocommerce_stock_sync_continue'))wp_schedule_single_event(time()+30,'mda_woocommerce_stock_sync_continue',array($warehouseId));
  return array_merge($counts,array('run_id'=>$runId,'warehouse'=>$warehouseName,'status'=>$status,'message'=>$message,'pending_events'=>$pending));
 }
 public static function warehouses():array {return MDA_Inventory_Service::warehouses();}
 public static function runs(int $limit=30):array {return MDA_Database::connection()->query('SELECT r.*,w.warehouse_name FROM mda_stock_sync_runs r JOIN mda_warehouses w ON w.id=r.warehouse_id ORDER BY r.id DESC LIMIT '.max(1,min(100,$limit)))->fetchAll();}
 public static function items(int $runId):array {$q=MDA_Database::connection()->prepare('SELECT * FROM mda_stock_sync_items WHERE run_id=:id ORDER BY id');$q->execute(array(':id'=>$runId));return$q->fetchAll();}
 private static function isPowerOfTen(int $scale):bool {if($scale<1)return false;while($scale>1&&$scale%10===0)$scale=intdiv($scale,10);return$scale===1;}
 private static function formatQuantity(int $quantity,int $scale):string {
  if($scale<1||!self::isPowerOfTen($scale))throw new InvalidArgumentException('مقیاس تعداد باید توان ده باشد.');
  $digits=strlen((string)$scale)-1;if($digits===0)return(string)$quantity;
  $whole=intdiv($quantity,$scale);$remainder=$quantity%$scale;if($remainder===0)return(string)$whole;
  $fraction=rtrim(str_pad((string)$remainder,$digits,'0',STR_PAD_LEFT),'0');return(string)$whole.'.'.$fraction;
 }
}

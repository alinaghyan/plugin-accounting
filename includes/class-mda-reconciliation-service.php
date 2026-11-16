<?php
defined( 'ABSPATH' ) || exit;

/** Finds eligible WooCommerce orders that have not reached a posted SQLite sale document. */
final class MDA_Reconciliation_Service {
 private const LOOKBACK_DAYS=90;
 private const PAGE_SIZE=100;
 public static function run_batch(int $limit=100):array {
  if(!class_exists('WooCommerce')||!function_exists('wc_get_orders'))throw new RuntimeException('WooCommerce برای تطبیق سفارش‌ها فعال نیست.');
  if(!MDA_Database::is_available())throw new RuntimeException('PDO_SQLite برای تطبیق سفارش‌ها فعال نیست.');
  $limit=max(1,min(self::PAGE_SIZE,$limit));$db=MDA_Database::connection();$pageKey='wc_reconcile_page_'.self::LOOKBACK_DAYS;
  $q=$db->prepare('SELECT setting_value FROM mda_settings WHERE setting_key=:key');$q->execute(array(':key'=>$pageKey));$page=max(1,(int)$q->fetchColumn());
  $windowStart=gmdate('Y-m-d',time()-(self::LOOKBACK_DAYS*86400));$started=gmdate('Y-m-d H:i:s');$counts=array('scanned'=>0,'already_posted'=>0,'queued'=>0,'pending'=>0,'failed'=>0,'skipped'=>0);$errors=array();
  $result=wc_get_orders(array('status'=>class_exists('MDA_Order_Statuses')?MDA_Order_Statuses::sale_statuses():array('processing','completed'),'limit'=>$limit,'page'=>$page,'paginate'=>true,'orderby'=>'ID','order'=>'ASC','date_created'=>'>'.$windowStart,'return'=>'objects'));
  if(!is_object($result)||!isset($result->orders))throw new RuntimeException('نتیجه جست‌وجوی سفارش‌های WooCommerce قابل خواندن نیست.');
  $orders=(array)$result->orders;$totalPages=max(0,(int)($result->max_num_pages??0));
  foreach($orders as $order){
   if(!is_object($order)||!method_exists($order,'get_id'))continue;$orderId=(int)$order->get_id();if($orderId<1)continue;$counts['scanned']++;
   try{
    $sale=$db->prepare('SELECT id FROM mda_sales_documents WHERE order_id=:id');$sale->execute(array(':id'=>$orderId));
    if($sale->fetchColumn()){$counts['already_posted']++;continue;}
    $eventKey='wc-sale-'.$orderId.'-v1';$event=$db->prepare('SELECT status,last_error FROM mda_integration_outbox WHERE event_key=:key');$event->execute(array(':key'=>$eventKey));$row=$event->fetch();
    if($row){
     if($row['status']==='completed'){$counts['failed']++;$errors[]=array('order_id'=>$orderId,'reason'=>'رویداد تکمیل‌شده است اما سند فروش SQLite پیدا نشد.');}
     elseif($row['status']==='failed'){$counts['failed']++;$errors[]=array('order_id'=>$orderId,'reason'=>'رویداد در صف ناموفق است: '.(string)$row['last_error']);}
     else $counts['pending']++;
     continue;
    }
    if(method_exists($order,'get_total_refunded')&&(float)$order->get_total_refunded()>0){$counts['skipped']++;$errors[]=array('order_id'=>$orderId,'reason'=>'سفارش قبل از ثبت حسابداری دارای استرداد است؛ بررسی دستی لازم است.');continue;}
    MDA_Outbox_Service::enqueue_order($order);
    $verify=$db->prepare('SELECT status FROM mda_integration_outbox WHERE event_key=:key');$verify->execute(array(':key'=>$eventKey));$status=$verify->fetchColumn();
    if($status==='pending')$counts['queued']++;elseif($status==='completed')$counts['already_posted']++;else{$counts['failed']++;$errors[]=array('order_id'=>$orderId,'reason'=>'رویداد پس از enqueue در صف پیدا نشد.');}
   }catch(Throwable $e){$counts['failed']++;if(count($errors)<30)$errors[]=array('order_id'=>$orderId,'reason'=>substr($e->getMessage(),0,500));}
  }
  $nextPage=($totalPages>0&&$page<$totalPages)?$page+1:1;$status=$nextPage>1?'partial':($counts['failed']>0||$counts['skipped']>0?'partial':'completed');
  $db->prepare('INSERT OR REPLACE INTO mda_settings (setting_key,setting_value,updated_at) VALUES (:key,:value,:now)')->execute(array(':key'=>$pageKey,':value'=>(string)$nextPage,':now'=>gmdate('Y-m-d H:i:s')));
  $finished=gmdate('Y-m-d H:i:s');$db->prepare('INSERT INTO mda_reconciliation_runs (started_at,finished_at,window_start,page_number,total_pages,scanned_count,already_posted_count,queued_count,pending_count,failed_count,skipped_count,errors_json,status,created_at) VALUES (:started,:finished,:window,:page,:pages,:scanned,:posted,:queued,:pending,:failed,:skipped,:errors,:status,:created)')->execute(array(':started'=>$started,':finished'=>$finished,':window'=>$windowStart,':page'=>$page,':pages'=>$totalPages,':scanned'=>$counts['scanned'],':posted'=>$counts['already_posted'],':queued'=>$counts['queued'],':pending'=>$counts['pending'],':failed'=>$counts['failed'],':skipped'=>$counts['skipped'],':errors'=>wp_json_encode($errors),':status'=>$status,':created'=>$finished));
  if($nextPage>1&&!wp_next_scheduled('mda_reconcile_woocommerce_continue'))wp_schedule_single_event(time()+30,'mda_reconcile_woocommerce_continue');
  return array_merge($counts,array('page'=>$page,'next_page'=>$nextPage,'total_pages'=>$totalPages,'window_start'=>$windowStart,'status'=>$status,'errors'=>$errors));
 }
 public static function runs(int $limit=30):array {
  return MDA_Database::connection()->query('SELECT * FROM mda_reconciliation_runs ORDER BY id DESC LIMIT '.max(1,min(100,$limit)))->fetchAll();
 }
 public static function state():array {
  $q=MDA_Database::connection()->prepare('SELECT setting_value FROM mda_settings WHERE setting_key=:key');$q->execute(array(':key'=>'wc_reconcile_page_'.self::LOOKBACK_DAYS));return array('next_page'=>max(1,(int)$q->fetchColumn()),'lookback_days'=>self::LOOKBACK_DAYS,'page_size'=>self::PAGE_SIZE);
 }
}

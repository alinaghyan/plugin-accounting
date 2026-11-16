<?php
defined( 'ABSPATH' ) || exit;

/** Durable SQLite outbox with retry-safe, idempotent sale posting. */
final class MDA_Outbox_Service {
 public static function enqueue_order($order): void {
  if(!$order||!is_object($order)||!method_exists($order,'get_id'))return;
  $status=$order->get_status();if(!in_array($status,array('processing','completed'),true))return;
  if((float)$order->get_total_refunded()>0)throw new RuntimeException('سفارش دارای استرداد قبلی است؛ ابتدا باید گردش مرجوعی تعریف شود.');
  $currency=strtoupper((string)$order->get_currency());if(!in_array($currency,array('IRR','IRT'),true))throw new RuntimeException('در این نسخه فروش فقط با واحد پول IRR یا IRT پشتیبانی می‌شود.');
  $warehouse=(int)$order->get_meta('_mda_sales_warehouse_id',true);
  if($warehouse<1){$q=MDA_Database::connection()->query("SELECT id FROM mda_warehouses WHERE warehouse_code='MAIN' AND is_active=1 LIMIT 1");$warehouse=(int)$q->fetchColumn();}
  $items=array();foreach($order->get_items('line_item') as $itemId=>$item){
   $product=$item->get_product();$qty=(string)wc_format_decimal($item->get_quantity(),6);
   $scaled=self::scale_quantity($qty);$items[]=array('order_item_id'=>(int)$itemId,'product_id'=>(int)$item->get_product_id(),'variation_id'=>(int)$item->get_variation_id(),'name'=>(string)$item->get_name(),'sku'=>$product?$product->get_sku():null,'quantity_scaled'=>$scaled,'quantity_scale'=>1000000,'revenue_minor'=>self::money($item->get_total()),'tax_minor'=>self::money($item->get_total_tax()),);
  }
  $shipping=0;foreach($order->get_items('shipping') as $item)$shipping+=self::money($item->get_total());
  $fees=0;foreach($order->get_items('fee') as $item)$fees+=self::money($item->get_total());
  $created=$order->get_date_created();$orderDate=$created?$created->date('Y-m-d'):current_time('Y-m-d');
  $payload=array('order_id'=>(int)$order->get_id(),'currency'=>$currency,'date'=>$orderDate,'warehouse_id'=>$warehouse,'channel'=>in_array($order->get_created_via(),array('mda_phone','mda_in_person'),true)?($order->get_created_via()==='mda_phone'?'phone':'in_person'):'website','customer'=>array('name'=>trim($order->get_billing_first_name().' '.$order->get_billing_last_name()),'phone'=>(string)$order->get_billing_phone(),'email'=>(string)$order->get_billing_email()),'created_by_user_id'=>(int)$order->get_meta('_mda_created_by_user_id',true),'items'=>$items,'shipping_minor'=>$shipping,'fees_minor'=>$fees,'tax_minor'=>self::money($order->get_total_tax()),'total_minor'=>self::money($order->get_total()),'idempotency_key'=>'wc-sale-'.$order->get_id().'-v1');
  $json=wp_json_encode($payload);$now=gmdate('Y-m-d H:i:s');$db=MDA_Database::connection();
  $q=$db->prepare('SELECT payload_json FROM mda_integration_outbox WHERE event_key=:key');$q->execute(array(':key'=>$payload['idempotency_key']));$existing=$q->fetchColumn();
  if($existing!==false){$old=json_decode((string)$existing,true);if(!is_array($old)||!hash_equals(hash('sha256',wp_json_encode($old)),hash('sha256',$json)))throw new RuntimeException('کلید سفارش قبلاً با snapshot متفاوتی وارد صف شده است.');return;}
  $insert=$db->prepare("INSERT INTO mda_integration_outbox (event_key,event_type,aggregate_type,aggregate_id,payload_json,status,attempts,available_at,created_at) VALUES (:key,'sale.post','woocommerce_order',:id,:payload,'pending',0,:now,:now)");
  $insert->execute(array(':key'=>$payload['idempotency_key'],':id'=>(string)$order->get_id(),':payload'=>$json,':now'=>$now));
  if(!wp_next_scheduled('mda_process_outbox_batch'))wp_schedule_single_event(time()+5,'mda_process_outbox_batch');
 }
 public static function enqueue_gateway_payment($order,string $gateway,string $reference):void {
  if(!$order||!is_object($order)||!method_exists($order,'get_id'))return;
  $orderId=(int)$order->get_id();$gateway=sanitize_key($gateway);$reference=trim($reference);$currency=strtoupper((string)$order->get_currency());
  if($orderId<1||$gateway===''||$reference===''||strlen($reference)>120||!$order->is_paid()||!in_array($currency,array('IRR','IRT'),true))return;
  $gross=self::money($order->get_total());if($gross<1)return;
  $key='wc-gateway-capture-'.$orderId.'-'.$gateway;
  $payload=array('order_id'=>$orderId,'gateway_key'=>$gateway,'gross_minor'=>$gross,'currency_code'=>$currency,'reference_number'=>$reference,'idempotency_key'=>$key);
  $db=MDA_Database::connection();$q=$db->prepare('SELECT payload_json FROM mda_integration_outbox WHERE event_key=:key');$q->execute(array(':key'=>$key));$old=$q->fetchColumn();$json=wp_json_encode($payload);
  if($old!==false){$previous=json_decode((string)$old,true);if(!is_array($previous)||!hash_equals(hash('sha256',wp_json_encode($previous)),hash('sha256',$json)))throw new RuntimeException('کلید پرداخت درگاه قبلاً با اطلاعات متفاوت ثبت شده است.');return;}
  $now=gmdate('Y-m-d H:i:s');$q=$db->prepare("INSERT INTO mda_integration_outbox (event_key,event_type,aggregate_type,aggregate_id,payload_json,status,attempts,available_at,created_at) VALUES (:key,'gateway.capture','woocommerce_order',:id,:payload,'pending',0,:now,:now)");
  $q->execute(array(':key'=>$key,':id'=>(string)$orderId,':payload'=>$json,':now'=>$now));
  if(!wp_next_scheduled('mda_process_outbox_batch'))wp_schedule_single_event(time()+5,'mda_process_outbox_batch');
 }
 public static function process_batch(int $limit=10): array {
  $db=MDA_Database::connection();$now=gmdate('Y-m-d H:i:s');
  // Recover workers that died after claiming an event.
  $db->prepare("UPDATE mda_integration_outbox SET status='pending',locked_at=NULL WHERE status='processing' AND locked_at < :stale")->execute(array(':stale'=>gmdate('Y-m-d H:i:s',time()-600)));
  $q=$db->prepare("SELECT id,event_key,event_type,payload_json,attempts,max_attempts FROM mda_integration_outbox WHERE status IN ('pending','failed') AND available_at<=:now AND attempts<max_attempts ORDER BY id LIMIT :limit");
  $q->bindValue(':now',$now);$q->bindValue(':limit',max(1,min(50,$limit)),PDO::PARAM_INT);$q->execute();$events=$q->fetchAll();$results=array();
  foreach($events as $event){
   $claim=$db->prepare("UPDATE mda_integration_outbox SET status='processing',locked_at=:now,last_attempt_at=:now,attempts=attempts+1 WHERE id=:id AND status IN ('pending','failed')");
   $claim->execute(array(':now'=>$now,':id'=>$event['id']));if($claim->rowCount()!==1)continue;
   try{
    $payload=json_decode((string)$event['payload_json'],true);
    if(!is_array($payload))throw new RuntimeException('محتوای رویداد outbox قابل خواندن نیست.');
    if($event['event_type']==='sale.post')MDA_Sales_Service::post_order_payload($payload);
    elseif($event['event_type']==='gateway.capture'){
     $sale=$db->prepare("SELECT id FROM mda_sales_documents WHERE order_id=:order AND status='posted'");$sale->execute(array(':order'=>(int)$payload['order_id']));
     if(!$sale->fetchColumn()){$saleEvent=$db->prepare("SELECT payload_json FROM mda_integration_outbox WHERE event_key=:key AND event_type='sale.post'");$saleEvent->execute(array(':key'=>'wc-sale-'.(int)$payload['order_id'].'-v1'));$salePayload=json_decode((string)$saleEvent->fetchColumn(),true);if(!is_array($salePayload))throw new RuntimeException('رویداد فروش سفارش هنوز در صف ثبت نشده است.');MDA_Sales_Service::post_order_payload($salePayload);}
     MDA_Gateway_Settlement_Service::capture((int)$payload['order_id'],(string)$payload['gateway_key'],(int)$payload['gross_minor'],(string)$payload['reference_number'],(string)$payload['idempotency_key']);
    } else throw new RuntimeException('نوع رویداد outbox پشتیبانی نمی‌شود.');
    // Posting is atomic inside MDA_Sales_Service. If the worker stops here,
    // retry safely observes the same immutable sale via its unique order key.
    $db->prepare("UPDATE mda_integration_outbox SET status='completed',processed_at=:now,locked_at=NULL,last_error=NULL WHERE id=:id AND status='processing'")->execute(array(':now'=>gmdate('Y-m-d H:i:s'),':id'=>$event['id']));
    $results[]=array('id'=>(int)$event['id'],'status'=>'completed');
   }catch(Throwable $e){
    $attempt=(int)$event['attempts']+1;$delay=min(86400,30*(2**min(10,$attempt-1)));$status=$attempt >= (int)$event['max_attempts']?'failed':'pending';
    $db->prepare('UPDATE mda_integration_outbox SET status=:status,available_at=:available,locked_at=NULL,last_error=:error WHERE id=:id')->execute(array(':status'=>$status,':available'=>gmdate('Y-m-d H:i:s',time()+$delay),':error'=>substr($e->getMessage(),0,1000),':id'=>$event['id']));
    $results[]=array('id'=>(int)$event['id'],'status'=>$status,'error'=>$e->getMessage());
   }
  }
  $remaining=(int)$db->query("SELECT COUNT(*) FROM mda_integration_outbox WHERE status IN ('pending','failed') AND attempts<max_attempts")->fetchColumn();
  if($remaining>0&&!wp_next_scheduled('mda_process_outbox_batch'))wp_schedule_single_event(time()+60,'mda_process_outbox_batch');
  return$results;
 }
 private static function money($value): int {
  $raw=wc_format_decimal($value,0);if(!preg_match('/^-?\d+$/',(string)$raw))throw new InvalidArgumentException('مبلغ سفارش باید عدد صحیح در واحد پول فروشگاه باشد.');$n=filter_var($raw,FILTER_VALIDATE_INT);if(false===$n||$n<0)throw new InvalidArgumentException('مبلغ سفارش خارج از محدوده مجاز است.');return(int)$n;
 }
 private static function scale_quantity(string $value): int {
  if(!preg_match('/^(\d+)(?:\.(\d{1,6}))?$/',$value))throw new InvalidArgumentException('مقدار سفارش بیش از ۶ رقم اعشار یا نامعتبر است.');
  $p=explode('.',$value);$whole=(int)$p[0];if($whole>intdiv(PHP_INT_MAX,1000000))throw new OverflowException('مقدار سفارش بیش از ظرفیت است.');$scaled=$whole*1000000+(int)str_pad($p[1]??'',6,'0');if($scaled<1)throw new InvalidArgumentException('مقدار سفارش باید مثبت باشد.');return$scaled;
 }
}

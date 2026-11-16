<?php
defined( 'ABSPATH' ) || exit;

/** HPOS-compatible quick-entry screen for phone and in-person orders. */
final class MDA_Phone_Sales_Admin {
 public static function init(): void {
  add_action('admin_enqueue_scripts',static function($hook){if(false!==strpos((string)$hook,'mda-phone-sales')&&wp_script_is('wc-enhanced-select','registered')){wp_enqueue_script('wc-enhanced-select');wp_enqueue_style('woocommerce_admin_styles');}},20);
  add_action('admin_menu',array(__CLASS__,'menu'));
  add_action('admin_post_mda_create_phone_sale',array(__CLASS__,'create'));
  add_action('admin_post_mda_retry_outbox_event',array(__CLASS__,'retry_outbox_event'));
 }
 public static function menu(): void {
  add_submenu_page(MDA_Admin_Shell::HIDDEN_PARENT,'فروش تلفنی / حضوری','فروش تلفنی / حضوری',MDA_Capabilities::for_page('mda-phone-sales'),'mda-phone-sales',array(__CLASS__,'render'));
 }
 private static function guard(): void {if(!MDA_Capabilities::user_can_action())wp_die(esc_html__('شما اجازه دسترسی به این بخش را ندارید.','malidev-accounting'));}
 public static function render(): void {
  self::guard();
  if(!class_exists('WooCommerce')){echo '<div class="wrap" dir="rtl"><h1>فروش تلفنی / حضوری</h1><div class="notice notice-error"><p>برای ثبت فروش، WooCommerce باید فعال باشد.</p></div></div>';return;}
  if(!MDA_Database::is_available()){echo '<div class="wrap" dir="rtl"><h1>فروش تلفنی / حضوری</h1><div class="notice notice-error"><p>PDO_SQLite فعال نیست.</p></div></div>';return;}
  $warehouses=MDA_Inventory_Service::warehouses();$db=MDA_Database::connection();
  $pending=(int)$db->query("SELECT COUNT(*) FROM mda_integration_outbox WHERE status IN ('pending','processing')")->fetchColumn();
  $failed=(int)$db->query("SELECT COUNT(*) FROM mda_integration_outbox WHERE status='failed'")->fetchColumn();
  $recent=$db->query('SELECT document_number,source_type,order_id,document_date,total_minor,cogs_total_minor FROM mda_sales_documents ORDER BY id DESC LIMIT 10')->fetchAll();$failedEvents=$db->query("SELECT id,event_type,aggregate_id,attempts,last_error,created_at FROM mda_integration_outbox WHERE status='failed' ORDER BY id DESC LIMIT 10")->fetchAll();
  ?>
  <div class="wrap" dir="rtl"><h1>ثبت فروش تلفنی / حضوری</h1>
  <p>این فرم سفارش واقعی WooCommerce می‌سازد؛ سفارش در گزارش‌ها و وضعیت‌های ووکامرس باقی می‌ماند. ثبت سند حسابداری و خروج انبار به‌صورت غیرهم‌زمان از صف SQLite انجام می‌شود.</p>
  <?php if(!empty($_GET['mda_created'])){$createdId=absint($_GET['mda_created']);echo '<div class="notice notice-success mda-created"><p>سفارش <a href="'.esc_url(MDA_Integrations::order_url($createdId)).'">#'.(int)$createdId.'</a> ثبت شد و برای ثبت حسابداری در صف قرار گرفت. '.MDA_Integrations::print_buttons($createdId).'</p></div>';} ?>
  <?php if(!empty($_GET['mda_error'])): ?><div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['mda_error']))); ?></p></div><?php endif; ?>
  <table class="widefat striped" style="max-width:650px"><tbody><tr><th>رویدادهای در انتظار</th><td><?php echo (int)$pending; ?></td></tr><tr><th>رویدادهای نیازمند بررسی</th><td><?php echo (int)$failed; ?></td></tr></tbody></table>
  <div style="background:#fff;padding:18px;max-width:1050px;margin:18px 0">
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
   <input type="hidden" name="action" value="mda_create_phone_sale"><?php wp_nonce_field('mda_create_phone_sale'); ?>
   <input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
   <h2>مشخصات مشتری</h2>
   <p><label>نام مشتری <input class="regular-text" name="customer_name" required></label>
   <label>شماره تماس <input class="regular-text" name="customer_phone" required inputmode="tel"></label></p>
   <p><label>انبار خروج <select name="warehouse_id" required><?php foreach($warehouses as $w): if((int)$w['is_active']): ?><option value="<?php echo (int)$w['id']; ?>" <?php selected($w['warehouse_code'],'MAIN'); ?>><?php echo esc_html($w['warehouse_name']); ?></option><?php endif; endforeach; ?></select></label>
   <label>نوع فروش <select name="channel"><option value="phone">تلفنی</option><option value="in_person">حضوری</option></select></label>
   <label>سطح قیمت <select name="price_level"><option value="">قیمت فروش فروشگاه</option><?php foreach(MDA_Price_Service::levels() as $levelKey=>$level): ?><option value="<?php echo esc_attr($levelKey); ?>"><?php echo esc_html($level['label']); ?></option><?php endforeach; ?></select></label></p>
   <h2>اقلام فروش</h2>
   <p>اگر قیمت واحد را خالی بگذارید، قیمت از «سطح قیمت» انتخاب‌شده (یا قیمت فروش فروشگاه) برداشته می‌شود. قیمت دستی با واحد پول WooCommerce وارد شود. تخفیف را در قیمت نهایی واحد لحاظ کنید. در این نسخه مالیات و هزینه ارسال برای فروش سریع صفر است.</p>
   <table class="widefat striped mda-sale-lines"><thead><tr><th>کالا (جستجوی نام یا SKU)</th><th>تعداد</th><th>قیمت واحد (واحد پول فروشگاه)</th></tr></thead><tbody>
   <?php for($i=0;$i<8;$i++): ?><tr><td><select class="wc-product-search" style="width:100%;min-width:320px" name="lines[<?php echo $i; ?>][item]" data-placeholder="نام کالا یا SKU…" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true"></select></td><td><input type="text" name="lines[<?php echo $i; ?>][quantity]" placeholder="1" size="6"></td><td><input type="text" inputmode="numeric" class="mda-amount" placeholder="خودکار" name="lines[<?php echo $i; ?>][unit_price_minor]"></td></tr><?php endfor; ?>
   </tbody></table>
   <p><button class="button button-primary" type="submit">ایجاد سفارش و ارسال به حسابداری</button></p>
  </form></div>
  <?php if(!empty($_GET['retry'])): ?><div class="notice notice-success"><p>درخواست تلاش مجدد به صف اضافه شد.</p></div><?php endif; ?>
  <?php if($failedEvents): ?><h2>رویدادهای ناموفق صف همگام‌سازی</h2><table class="widefat striped"><thead><tr><th>شناسه</th><th>نوع</th><th>سفارش</th><th>تلاش‌ها</th><th>آخرین خطا</th><th>عملیات</th></tr></thead><tbody><?php foreach($failedEvents as $event): ?><tr><td><?php echo (int)$event['id']; ?></td><td><?php echo esc_html($event['event_type']); ?></td><td><?php echo esc_html($event['aggregate_id']); ?></td><td><?php echo (int)$event['attempts']; ?></td><td><?php echo esc_html($event['last_error']); ?></td><td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_retry_outbox_event"><input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>"><?php wp_nonce_field('mda_retry_outbox_'.(int)$event['id']); ?><button class="button">تلاش مجدد</button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <h2>آخرین فروش‌های ثبت‌شده در حسابداری</h2>
  <table class="widefat striped"><thead><tr><th>سند</th><th>کانال</th><th>شماره سفارش</th><th>تاریخ</th><th>فروش (واحد پول فروشگاه)</th><th>COGS (واحد پول فروشگاه)</th><th>چاپ</th></tr></thead><tbody>
  <?php foreach($recent as $r): ?><tr><td><?php echo esc_html($r['document_number']); ?></td><td><?php echo $r['source_type']==='phone'?'تلفنی':($r['source_type']==='in_person'?'حضوری':'وب‌سایت'); ?></td><td><a href="<?php echo esc_url(admin_url('admin.php?page=wc-orders&action=edit&id='.(int)$r['order_id'])); ?>">#<?php echo (int)$r['order_id']; ?></a></td><td><?php echo esc_html($r['document_date']); ?></td><td><?php echo esc_html(number_format((int)$r['total_minor'])); ?></td><td><?php echo esc_html(number_format((int)$r['cogs_total_minor'])); ?></td><td><?php echo MDA_Integrations::print_buttons((int)$r['order_id']); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></td></tr><?php endforeach; ?>
  <?php if(!$recent): ?><tr><td colspan="7">هنوز فروش حسابداری ثبت نشده است.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php
 }
 public static function retry_outbox_event(): void {
  self::guard();$id=absint($_POST['event_id']??0);check_admin_referer('mda_retry_outbox_'.$id);
  try{$db=MDA_Database::connection();$q=$db->prepare("UPDATE mda_integration_outbox SET status='pending',attempts=0,max_attempts=12,available_at=:now,locked_at=NULL,last_error=NULL WHERE id=:id AND status='failed'");$q->execute(array(':now'=>gmdate('Y-m-d H:i:s'),':id'=>$id));if($q->rowCount()!==1)throw new RuntimeException('رویداد ناموفق پیدا نشد یا وضعیت آن قابل تلاش مجدد نیست.');if(!wp_next_scheduled('mda_process_outbox_batch'))wp_schedule_single_event(time()+5,'mda_process_outbox_batch');wp_safe_redirect(admin_url('admin.php?page=mda-phone-sales&retry=queued'));exit;}catch(Throwable $e){wp_safe_redirect(add_query_arg(array('page'=>'mda-phone-sales','mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php')));exit;}
 }
 public static function create(): void {
  self::guard();check_admin_referer('mda_create_phone_sale');
  $key=sanitize_text_field(wp_unslash($_POST['request_key']??''));$name=sanitize_text_field(wp_unslash($_POST['customer_name']??''));$phone=preg_replace('/[^0-9+]/','',(string)wp_unslash($_POST['customer_phone']??''));$warehouse=absint($_POST['warehouse_id']??0);$channel=sanitize_key(wp_unslash($_POST['channel']??'phone'));$priceLevel=sanitize_key(wp_unslash($_POST['price_level']??''));
  try {
   if(!class_exists('WooCommerce')||!MDA_Database::is_available())throw new RuntimeException('WooCommerce و PDO_SQLite باید فعال باشند.');
   if(strlen($key)<16||$name===''||$phone===''||$warehouse<1||!in_array($channel,array('phone','in_person'),true))throw new InvalidArgumentException('مشخصات مشتری، انبار یا کلید یکتای درخواست معتبر نیست.');
   $raw=(array)wp_unslash($_POST['lines']??array());$lines=array();$payloadLines=array();
   foreach($raw as $row){$item=absint($row['item']??0);$productId=absint($row['product_id']??0);$variationId=absint($row['variation_id']??0);
    if($item){$picked=wc_get_product($item);if(!$picked)throw new InvalidArgumentException('کالای انتخاب‌شده پیدا نشد.');if($picked->is_type('variation')){$productId=(int)$picked->get_parent_id();$variationId=$item;}else{$productId=$item;$variationId=0;}}
    if(!$productId)continue;$qtyText=strtr(trim((string)($row['quantity']??'')),array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',','=>'.','٫'=>'.'));if(!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/',$qtyText))throw new InvalidArgumentException('تعداد یکی از کالاها معتبر نیست.');
    $parts=explode('.',$qtyText);$qtyScaled=(int)$parts[0]*1000000+(int)str_pad($parts[1]??'',6,'0');if($qtyScaled<1)throw new InvalidArgumentException('تعداد کالا باید مثبت باشد.');
    $product=wc_get_product($variationId?:$productId);if(!$product||!$product->exists())throw new InvalidArgumentException('یکی از محصولات یا تنوع‌های انتخاب‌شده وجود ندارد.');
    $unitText=preg_replace('/[^0-9]/','',MDA_Jalali::latin_digits((string)($row['unit_price_minor']??'')));
    if(''===$unitText){$auto=MDA_Price_Service::level_price($product,$priceLevel);if(''===$auto||!is_numeric($auto))throw new InvalidArgumentException('برای «'.$product->get_name().'» قیمتی تعریف نشده؛ قیمت واحد را دستی وارد کنید.');$unitText=(string)(int)round((float)$auto);}
    $unit=filter_var($unitText,FILTER_VALIDATE_INT);if(false===$unit||$unit<0)throw new InvalidArgumentException('قیمت واحد باید عدد صحیح نامنفی در واحد پول فروشگاه باشد.');
    if($unit>0&&$qtyScaled>intdiv(PHP_INT_MAX,$unit))throw new OverflowException('مبلغ ردیف فروش بیش از ظرفیت مجاز است.');
    $lineTotal=intdiv($unit*$qtyScaled,1000000);$lines[]=array('product'=>$product,'qty'=>(float)$qtyText,'total'=>(string)$lineTotal);$payloadLines[]=array($productId,$variationId,$qtyScaled,$unit);
   }
   if(!$lines)throw new InvalidArgumentException('حداقل یک کالا برای فروش انتخاب کنید.');
   $hash=hash('sha256',wp_json_encode(array($name,$phone,$warehouse,$channel,$payloadLines)));
   $db=MDA_Database::connection();$q=$db->prepare('SELECT payload_hash,order_id,status FROM mda_phone_sale_requests WHERE request_key=:key');$q->execute(array(':key'=>$key));$existing=$q->fetch();
   if($existing){
    if(!hash_equals((string)$existing['payload_hash'],$hash))throw new RuntimeException('این کلید درخواست قبلاً برای اطلاعات دیگری استفاده شده است.');
    if((int)$existing['order_id']>0){wp_safe_redirect(get_edit_post_link((int)$existing['order_id'])?:admin_url('admin.php?page=wc-orders&action=edit&id='.(int)$existing['order_id']));exit;}
    $found=wc_get_orders(array('limit'=>1,'return'=>'ids','meta_query'=>array(array('key'=>'_mda_phone_request_key','value'=>$key))));
    if($found){$orderId=(int)$found[0];$db->prepare("UPDATE mda_phone_sale_requests SET order_id=:id,status='created',updated_at=:now WHERE request_key=:key")->execute(array(':id'=>$orderId,':now'=>gmdate('Y-m-d H:i:s'),':key'=>$key));wp_safe_redirect(get_edit_post_link($orderId)?:admin_url('admin.php?page=wc-orders&action=edit&id='.$orderId));exit;}
    if($existing['status']==='creating')throw new RuntimeException('درخواست هم‌زمان در حال ایجاد سفارش است؛ چند لحظه بعد دوباره بررسی کنید.');
    $db->prepare("UPDATE mda_phone_sale_requests SET status='creating',last_error=NULL,updated_at=:now WHERE request_key=:key")->execute(array(':now'=>gmdate('Y-m-d H:i:s'),':key'=>$key));
   }else{
    $now=gmdate('Y-m-d H:i:s');$db->prepare("INSERT INTO mda_phone_sale_requests (request_key,payload_hash,status,created_at,updated_at) VALUES (:key,:hash,'creating',:now,:now)")->execute(array(':key'=>$key,':hash'=>$hash,':now'=>$now));
   }
   $order=wc_create_order(array('status'=>'pending','created_via'=>$channel==='phone'?'mda_phone':'mda_in_person'));
   if(is_wp_error($order))throw new RuntimeException($order->get_error_message());
   $order->set_created_via($channel==='phone'?'mda_phone':'mda_in_person');$order->set_currency(get_woocommerce_currency());$order->set_billing_first_name($name);$order->set_billing_phone($phone);$order->set_payment_method('cod');$order->set_payment_method_title($channel==='phone'?'فروش تلفنی':'فروش حضوری');
   $order->update_meta_data('_mda_phone_request_key',$key);$order->update_meta_data('_mda_sales_warehouse_id',$warehouse);$order->update_meta_data('_mda_created_by_user_id',get_current_user_id());
   foreach($lines as $line)$order->add_product($line['product'],$line['qty'],array('subtotal'=>$line['total'],'total'=>$line['total']));
   $order->calculate_totals(false);$order->save();$orderId=(int)$order->get_id();
   $db->prepare("UPDATE mda_phone_sale_requests SET order_id=:id,status='created',updated_at=:now WHERE request_key=:key")->execute(array(':id'=>$orderId,':now'=>gmdate('Y-m-d H:i:s'),':key'=>$key));
   
   $order->update_status('processing','ثبت فروش تلفنی/حضوری از MaliDev Accounting');
   wp_safe_redirect(add_query_arg(array('page'=>'mda-phone-sales','mda_created'=>$orderId),admin_url('admin.php')));exit;
  }catch(Throwable $e){
   if(isset($key)&&$key!==''){try{MDA_Database::connection()->prepare("UPDATE mda_phone_sale_requests SET status='failed',last_error=:error,updated_at=:now WHERE request_key=:key AND status='creating'")->execute(array(':error'=>substr($e->getMessage(),0,1000),':now'=>gmdate('Y-m-d H:i:s'),':key'=>$key));}catch(Throwable $ignored){}}
   wp_safe_redirect(add_query_arg(array('page'=>'mda-phone-sales','mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php')));exit;
  }
 }
}

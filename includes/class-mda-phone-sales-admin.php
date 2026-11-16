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
 /** Statuses offered on the manual-order form (sale statuses post immediately). */
 public static function statuses(): array {
  $all=function_exists('wc_get_order_statuses')?wc_get_order_statuses():array();$out=array();
  foreach(array_merge(array('processing','completed'),class_exists('MDA_Order_Statuses')?array_diff(MDA_Order_Statuses::sale_statuses(),array('processing','completed')):array(),array('on-hold','pending')) as $k){if(isset($all['wc-'.$k]))$out[$k]=$all['wc-'.$k];}
  return $out;
 }
 public static function payment_methods(): array {
  return array('cash'=>'نقدی','pos'=>'کارت‌خوان','card'=>'کارت به کارت','credit'=>'اعتباری (نسیه)','cod'=>'پرداخت هنگام تحویل');
 }
 public static function render(): void {
  self::guard();
  echo '<div class="wrap" dir="rtl"><h1>ثبت سفارش دستی</h1>';
  if(!class_exists('WooCommerce')){echo '<div class="notice notice-error"><p>برای ثبت سفارش، WooCommerce باید فعال باشد.</p></div></div>';return;}
  if(!MDA_Database::is_available()){echo '<div class="notice notice-error"><p>PDO_SQLite فعال نیست.</p></div></div>';return;}
  $warehouses=array_filter(MDA_Inventory_Service::warehouses(),static fn($w)=>(int)$w['is_active']===1);$online=MDA_Settings::online_warehouse_id();$db=MDA_Database::connection();
  $pending=(int)$db->query("SELECT COUNT(*) FROM mda_integration_outbox WHERE status IN ('pending','processing')")->fetchColumn();
  $failed=(int)$db->query("SELECT COUNT(*) FROM mda_integration_outbox WHERE status='failed'")->fetchColumn();
  $recent=$db->query('SELECT document_number,source_type,order_id,document_date,total_minor,cogs_total_minor FROM mda_sales_documents ORDER BY id DESC LIMIT 10')->fetchAll();
  $failedEvents=$db->query("SELECT id,event_type,aggregate_id,attempts,last_error FROM mda_integration_outbox WHERE status='failed' ORDER BY id DESC LIMIT 20")->fetchAll();
  $cur=get_woocommerce_currency_symbol();
  // phpcs:disable WordPress.Security.NonceVerification.Recommended -- notices only.
  if(!empty($_GET['mda_created'])){$createdId=absint($_GET['mda_created']);echo '<div class="notice notice-success mda-created"><p>سفارش <a href="'.esc_url(MDA_Integrations::order_url($createdId)).'"><strong>#'.(int)$createdId.'</strong></a> ثبت شد و در فهرست سفارش‌های ووکامرس با برچسب «دستی» دیده می‌شود. '.MDA_Integrations::print_buttons($createdId).'</p></div>';} // phpcs:ignore WordPress.Security.EscapeOutput -- print_buttons() returns escaped HTML.
  if(!empty($_GET['mda_error']))echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['mda_error']))).'</p></div>';
  if(!empty($_GET['retry']))echo '<div class="notice notice-success"><p>درخواست تلاش مجدد به صف اضافه شد.</p></div>';
  // phpcs:enable
  ?>
  <div class="mda-grid mda-grid-3">
   <div class="mda-card mda-kpi is-teal"><span class="mda-kpi__icon" aria-hidden="true"><span class="dashicons dashicons-update"></span></span><div class="mda-kpi__body"><span class="mda-kpi__label">در صف ثبت حسابداری</span><span class="mda-kpi__value"><?php echo esc_html(number_format_i18n($pending)); ?></span><span class="mda-kpi__sub">چند ثانیه بعد خودکار ثبت می‌شوند</span></div></div>
   <div class="mda-card mda-kpi <?php echo $failed?'is-bad':'is-ok'; ?>"><span class="mda-kpi__icon" aria-hidden="true"><span class="dashicons <?php echo $failed?'dashicons-warning':'dashicons-yes-alt'; ?>"></span></span><div class="mda-kpi__body"><span class="mda-kpi__label">نیازمند بررسی</span><span class="mda-kpi__value"><?php echo esc_html(number_format_i18n($failed)); ?></span><span class="mda-kpi__sub"><?php echo $failed?'پایین همین صفحه، «تلاش مجدد» بزنید':'همه سفارش‌ها ثبت شده‌اند'; ?></span></div></div>
   <div class="mda-card mda-kpi is-info"><span class="mda-kpi__icon" aria-hidden="true"><span class="dashicons dashicons-admin-users"></span></span><div class="mda-kpi__body"><span class="mda-kpi__label">ثبت‌کننده</span><span class="mda-kpi__value" style="font-size:17px"><?php echo esc_html(wp_get_current_user()->display_name); ?></span><span class="mda-kpi__sub">نام شما روی سفارش ثبت می‌شود</span></div></div>
  </div>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
   <input type="hidden" name="action" value="mda_create_phone_sale"><?php wp_nonce_field('mda_create_phone_sale'); ?>
   <input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
   <div class="mda-grid-2">
    <div class="mda-card"><h2>مشتری</h2><div class="mda-fields">
     <label>نام و نام خانوادگی<input name="customer_name" required autocomplete="off"></label>
     <label>شماره موبایل<input name="customer_phone" required inputmode="tel" dir="ltr" placeholder="09xxxxxxxxx"></label>
     <label>ایمیل (اختیاری)<input type="email" name="customer_email" dir="ltr"></label>
     <label>شهر (اختیاری)<input name="customer_city"></label>
     <label class="is-wide">نشانی (اختیاری)<input name="customer_address" maxlength="250"></label>
    </div></div>
    <div class="mda-card"><h2>سفارش</h2><div class="mda-fields">
     <label>نوع سفارش<select name="channel"><option value="phone">تلفنی</option><option value="in_person">حضوری</option></select></label>
     <label>وضعیت سفارش<select name="order_status"><?php foreach(self::statuses() as $k=>$l): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($l); ?></option><?php endforeach; ?></select><small>«در حال انجام» و «تکمیل‌شده» همین حالا در حسابداری ثبت می‌شوند.</small></label>
     <label>روش پرداخت<select name="payment"><?php foreach(self::payment_methods() as $k=>$l): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label>
     <label>انبار خروج<select name="warehouse_id" required><?php foreach($warehouses as $w): ?><option value="<?php echo (int)$w['id']; ?>" <?php selected((int)$w['id'],$online); ?>><?php echo esc_html($w['warehouse_name']); ?></option><?php endforeach; ?></select></label>
     <label>سطح قیمت<select name="price_level"><option value="">قیمت فروش فروشگاه</option><?php foreach(MDA_Price_Service::levels() as $levelKey=>$level): ?><option value="<?php echo esc_attr($levelKey); ?>"><?php echo esc_html($level['label']); ?></option><?php endforeach; ?></select></label>
     <label>هزینه ارسال (<?php echo esc_html(html_entity_decode($cur)); ?>)<input name="shipping_total" dir="ltr" inputmode="numeric" placeholder="0"></label>
     <label class="is-wide">یادداشت (اختیاری)<input name="order_note" maxlength="300" placeholder="مثلاً: ارسال با پیک، تحویل عصر"></label>
    </div></div>
   </div>
   <div class="mda-card"><div class="mda-card__head"><h2>اقلام سفارش</h2><span class="mda-muted" style="font-size:12px">قیمت خالی = قیمت سطح انتخاب‌شده · تعداد اعشاری مجاز است</span></div>
    <table class="widefat mda-sale-lines"><thead><tr><th style="width:55%">کالا (جستجوی نام یا SKU)</th><th>تعداد</th><th>قیمت واحد (<?php echo esc_html(html_entity_decode($cur)); ?>)</th></tr></thead><tbody>
    <?php for($i=0;$i<8;$i++): ?><tr<?php echo $i>2?' class="mda-extra-line" hidden':''; ?>><td><select class="wc-product-search" style="width:100%" name="lines[<?php echo (int)$i; ?>][item]" data-placeholder="نام کالا یا SKU…" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true"></select></td><td><input name="lines[<?php echo (int)$i; ?>][quantity]" dir="ltr" inputmode="decimal" placeholder="1" style="width:100%"></td><td><input name="lines[<?php echo (int)$i; ?>][unit_price_minor]" dir="ltr" inputmode="numeric" placeholder="خودکار" style="width:100%"></td></tr><?php endfor; ?>
    </tbody></table>
    <p style="margin-top:16px"><button type="button" class="button mda-more-lines"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>ردیف بیشتر</button> <button class="button button-primary" type="submit"><span class="dashicons dashicons-yes" aria-hidden="true"></span>ثبت سفارش</button></p>
   </div>
  </form>
  <script>document.querySelector('.mda-more-lines')?.addEventListener('click',function(){var h=document.querySelectorAll('.mda-extra-line[hidden]');if(h[0])h[0].hidden=false;if(h.length<=1)this.remove();});</script>
  <?php if($failedEvents): ?><h2>رویدادهای ناموفق صف همگام‌سازی</h2><table class="widefat"><thead><tr><th>شناسه</th><th>نوع</th><th>سفارش</th><th>تلاش‌ها</th><th>آخرین خطا</th><th>عملیات</th></tr></thead><tbody><?php foreach($failedEvents as $event): ?><tr><td><?php echo (int)$event['id']; ?></td><td><?php echo esc_html($event['event_type']); ?></td><td><?php echo esc_html($event['aggregate_id']); ?></td><td><?php echo (int)$event['attempts']; ?></td><td><?php echo esc_html((string)$event['last_error']); ?></td><td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_retry_outbox_event"><input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>"><?php wp_nonce_field('mda_retry_outbox_'.(int)$event['id']); ?><button class="button button-small">تلاش مجدد</button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <h2>آخرین فروش‌های ثبت‌شده در حسابداری</h2>
  <table class="widefat"><thead><tr><th>سند</th><th>کانال</th><th>سفارش</th><th>تاریخ</th><th>فروش</th><th>بهای تمام‌شده</th><th>چاپ</th></tr></thead><tbody>
  <?php foreach($recent as $r): ?><tr><td><?php echo esc_html($r['document_number']); ?></td><td><span class="mda-pill"><?php echo esc_html(array('phone'=>'تلفنی','in_person'=>'حضوری')[$r['source_type']]??'سایت'); ?></span></td><td><a href="<?php echo esc_url(MDA_Integrations::order_url((int)$r['order_id'])); ?>">#<?php echo (int)$r['order_id']; ?></a></td><td><?php echo esc_html(MDA_Jalali::format($r['document_date'])); ?></td><td><?php echo esc_html(number_format_i18n((int)$r['total_minor'])); ?></td><td><?php echo esc_html(number_format_i18n((int)$r['cogs_total_minor'])); ?></td><td><?php echo MDA_Integrations::print_buttons((int)$r['order_id']); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr><?php endforeach; ?>
  <?php if(!$recent): ?><tr><td colspan="7"><div class="mda-empty">هنوز فروشی در حسابداری ثبت نشده است.</div></td></tr><?php endif; ?>
  </tbody></table></div>
  <?php
 }
 public static function retry_outbox_event(): void {
  self::guard();$id=absint($_POST['event_id']??0);check_admin_referer('mda_retry_outbox_'.$id);
  try{$db=MDA_Database::connection();$q=$db->prepare("UPDATE mda_integration_outbox SET status='pending',attempts=0,max_attempts=12,available_at=:now,locked_at=NULL,last_error=NULL WHERE id=:id AND status='failed'");$q->execute(array(':now'=>gmdate('Y-m-d H:i:s'),':id'=>$id));if($q->rowCount()!==1)throw new RuntimeException('رویداد ناموفق پیدا نشد یا وضعیت آن قابل تلاش مجدد نیست.');if(!wp_next_scheduled('mda_process_outbox_batch'))wp_schedule_single_event(time()+5,'mda_process_outbox_batch');wp_safe_redirect(admin_url('admin.php?page=mda-phone-sales&retry=queued'));exit;}catch(Throwable $e){wp_safe_redirect(add_query_arg(array('page'=>'mda-phone-sales','mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php')));exit;}
 }
 public static function create(): void {
  self::guard();check_admin_referer('mda_create_phone_sale');
  $key=sanitize_text_field(wp_unslash($_POST['request_key']??''));$name=sanitize_text_field(wp_unslash($_POST['customer_name']??''));$phone=preg_replace('/[^0-9+]/','',(string)wp_unslash($_POST['customer_phone']??''));$warehouse=absint($_POST['warehouse_id']??0);$channel=sanitize_key(wp_unslash($_POST['channel']??'phone'));$priceLevel=sanitize_key(wp_unslash($_POST['price_level']??''));$status=sanitize_key(wp_unslash($_POST['order_status']??'processing'));$payment=sanitize_key(wp_unslash($_POST['payment']??'cash'));$email=sanitize_email(wp_unslash($_POST['customer_email']??''));$city=sanitize_text_field(wp_unslash($_POST['customer_city']??''));$address=sanitize_text_field(wp_unslash($_POST['customer_address']??''));$note=sanitize_textarea_field(wp_unslash($_POST['order_note']??''));$shipText=preg_replace('/[^0-9]/','',MDA_Jalali::latin_digits((string)wp_unslash($_POST['shipping_total']??'')));
  try {
   if(!class_exists('WooCommerce')||!MDA_Database::is_available())throw new RuntimeException('WooCommerce و PDO_SQLite باید فعال باشند.');
   if(strlen($key)<16||$name===''||$phone===''||$warehouse<1||!in_array($channel,array('phone','in_person'),true))throw new InvalidArgumentException('مشخصات مشتری، انبار یا کلید یکتای درخواست معتبر نیست.');
   if(!isset(self::statuses()[$status]))$status='processing';$methods=self::payment_methods();if(!isset($methods[$payment]))$payment='cash';
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
   $order->set_created_via($channel==='phone'?'mda_phone':'mda_in_person');$order->set_currency(get_woocommerce_currency());$order->set_billing_first_name($name);$order->set_billing_phone($phone);$order->set_payment_method('cod'===$payment?'cod':'mda_'.$payment);$order->set_payment_method_title($methods[$payment]);if($email!=='')$order->set_billing_email($email);if($city!==''){$order->set_billing_city($city);$order->set_shipping_city($city);}if($address!==''){$order->set_billing_address_1($address);$order->set_shipping_address_1($address);}$order->set_shipping_first_name($name);$order->set_shipping_phone($phone);
   $order->update_meta_data('_mda_phone_request_key',$key);$order->update_meta_data('_mda_sales_warehouse_id',$warehouse);$order->update_meta_data('_mda_created_by_user_id',get_current_user_id());$order->update_meta_data('_mda_manual_order',$channel);
   foreach($lines as $line)$order->add_product($line['product'],$line['qty'],array('subtotal'=>$line['total'],'total'=>$line['total']));
   if($shipText!==''&&(int)$shipText>0){$ship=new WC_Order_Item_Shipping();$ship->set_method_title('ارسال');$ship->set_method_id('flat_rate');$ship->set_total((string)(int)$shipText);$order->add_item($ship);}
   if($note!=='')$order->set_customer_note($note);
   $order->calculate_totals(false);$order->save();$orderId=(int)$order->get_id();
   $db->prepare("UPDATE mda_phone_sale_requests SET order_id=:id,status='created',updated_at=:now WHERE request_key=:key")->execute(array(':id'=>$orderId,':now'=>gmdate('Y-m-d H:i:s'),':key'=>$key));
   
   $by=wp_get_current_user();$order->add_order_note(sprintf('سفارش دستی (%s)؛ ثبت‌شده توسط %s',$channel==='phone'?'تلفنی':'حضوری',$by->display_name),0,false);
   $order->update_status($status,'ثبت سفارش دستی از حسابداری MaliDev');
   wp_safe_redirect(add_query_arg(array('page'=>'mda-phone-sales','mda_created'=>$orderId),admin_url('admin.php')));exit;
  }catch(Throwable $e){
   if(isset($key)&&$key!==''){try{MDA_Database::connection()->prepare("UPDATE mda_phone_sale_requests SET status='failed',last_error=:error,updated_at=:now WHERE request_key=:key AND status='creating'")->execute(array(':error'=>substr($e->getMessage(),0,1000),':now'=>gmdate('Y-m-d H:i:s'),':key'=>$key));}catch(Throwable $ignored){}}
   wp_safe_redirect(add_query_arg(array('page'=>'mda-phone-sales','mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php')));exit;
  }
 }
}

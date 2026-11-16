<?php
defined( 'ABSPATH' ) || exit;

final class MDA_Admin {
 public static function init(): void {
  add_action('admin_menu',array(__CLASS__,'menu'));
  add_action('admin_post_mda_save_party',array(__CLASS__,'save_party'));
  add_action('admin_post_mda_archive_party',array(__CLASS__,'archive_party'));
  add_action('admin_post_mda_save_account',array(__CLASS__,'save_account'));
  add_action('admin_post_mda_create_purchase',array(__CLASS__,'create_purchase'));
  add_action('admin_post_mda_post_purchase',array(__CLASS__,'post_purchase'));
  add_action('admin_post_mda_record_purchase_payment',array(__CLASS__,'record_purchase_payment'));
  add_action('admin_post_mda_post_purchase_return',array(__CLASS__,'post_purchase_return'));
 }

 public static function menu(): void {
  add_menu_page(__('حسابداری MaliDev','malidev-accounting'),__('حسابداری','malidev-accounting'),MDA_Capabilities::MANAGE,'mda-accounting',array(__CLASS__,'render'),'dashicons-chart-area',56);
  add_submenu_page('mda-accounting','طرف‌حساب‌ها','طرف‌حساب‌ها',MDA_Capabilities::MANAGE,'mda-parties',array(__CLASS__,'render_parties'));
  add_submenu_page('mda-accounting','کدینگ حساب‌ها','کدینگ حساب‌ها',MDA_Capabilities::MANAGE,'mda-accounts',array(__CLASS__,'render_accounts'));
  add_submenu_page('mda-accounting','گردش حساب اشخاص','گردش حساب اشخاص',MDA_Capabilities::MANAGE,'mda-party-ledger',array(__CLASS__,'render_party_ledger'));
  add_submenu_page('mda-accounting','خرید و فاکتور خرید','خرید و انبار',MDA_Capabilities::MANAGE,'mda-purchases',array(__CLASS__,'render_purchases'));
 }

 private static function guard(): void {
  if(!current_user_can(MDA_Capabilities::MANAGE)) wp_die(esc_html__('شما اجازه دسترسی به این بخش را ندارید.','malidev-accounting'));
 }
 public static function render(): void {
  self::guard();$available=MDA_Database::is_available();$connected=false;$error='';$integrity='';
  if($available){try{$pdo=MDA_Database::connection();$connected=true;$integrity=(string)$pdo->query('PRAGMA quick_check')->fetchColumn();}catch(Throwable $e){$error=$e->getMessage();}}
  ?>
  <div class="wrap" dir="rtl"><h1>حسابداری MaliDev</h1><p>نسخه توسعه‌ای هسته حسابداری؛ ثبت خرید و قطعی‌سازی آن فعال است. اتصال فروش ووکامرس هنوز فعال نشده است.</p>
  <table class="widefat striped" style="max-width:900px"><tbody>
  <tr><th>نسخه افزونه</th><td><?php echo esc_html(MDA_VERSION); ?></td></tr>
  <tr><th>PDO_SQLite</th><td><?php echo $available?'فعال':'غیرفعال'; ?></td></tr>
  <tr><th>اتصال دیتابیس</th><td><?php echo $connected?'برقرار':'برقرار نیست'; ?></td></tr>
  <?php if($connected): ?><tr><th>سلامت SQLite</th><td><?php echo esc_html($integrity); ?></td></tr><?php endif; ?>
  <?php if($error): ?><tr><th>خطا</th><td><?php echo esc_html($error); ?></td></tr><?php endif; ?>
  </tbody></table>
  <p>برای جلوگیری از ثبت ناقص، اتصال خودکار سفارش‌های WooCommerce تا تکمیل صف همگام‌سازی غیرفعال است.</p></div>
  <?php
 }

 public static function save_party(): void {
  self::guard();check_admin_referer('mda_save_party');
  try{$id=!empty($_POST['party_id'])?absint($_POST['party_id']):null;MDA_Party_Service::save(wp_unslash($_POST),$id);$url=add_query_arg('mda_notice','party_saved',admin_url('admin.php?page=mda-parties'));}
  catch(Throwable $e){$url=add_query_arg(array('mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php?page=mda-parties'));}
  wp_safe_redirect($url);exit;
 }

 public static function archive_party(): void {
  self::guard();$id=absint($_GET['party_id']??0);check_admin_referer('mda_archive_party_'.$id);
  try{MDA_Party_Service::archive($id);$url=add_query_arg('mda_notice','party_archived',admin_url('admin.php?page=mda-parties'));}
  catch(Throwable $e){$url=add_query_arg('mda_error',rawurlencode($e->getMessage()),admin_url('admin.php?page=mda-parties'));}
  wp_safe_redirect($url);exit;
 }

 public static function save_account(): void {
  self::guard();check_admin_referer('mda_save_account');
  try{$id=!empty($_POST['account_id'])?absint($_POST['account_id']):null;MDA_Account_Service::save(wp_unslash($_POST),$id);$url=add_query_arg('mda_notice','account_saved',admin_url('admin.php?page=mda-accounts'));}
  catch(Throwable $e){$url=add_query_arg('mda_error',rawurlencode($e->getMessage()),admin_url('admin.php?page=mda-accounts'));}
  wp_safe_redirect($url);exit;
 }


 public static function create_purchase(): void {
  self::guard();check_admin_referer('mda_create_purchase');
  try {
   $lines=array();$raw=wp_unslash($_POST['lines']??array());
   foreach((array)$raw as $line){$product=absint($line['product_id']??0);if(!$product)continue;$qty=self::parse_quantity((string)($line['quantity']??''));$unit=filter_var((string)($line['unit_cost_minor']??''),FILTER_VALIDATE_INT);if(false===$unit||$unit<0)throw new InvalidArgumentException('قیمت خرید باید عدد صحیح نامنفی به واحد خرد پول باشد.');
    $variation=absint($line['variation_id']??0);$name='';$sku=null;if(function_exists('wc_get_product')){$p=wc_get_product($variation?:$product);if($p){$name=$p->get_name();$sku=$p->get_sku();}}
    $lines[]=array('product_id'=>$product,'variation_id'=>$variation,'product_name_snapshot'=>$name,'sku_snapshot'=>$sku,'quantity_scaled'=>$qty,'quantity_scale'=>1000000,'unit_cost_minor'=>(int)$unit);
   }
   $costs=array();$costAmount=filter_var((string)($_POST['ancillary_amount_minor']??'0'),FILTER_VALIDATE_INT);if(false===$costAmount||$costAmount<0)throw new InvalidArgumentException('مبلغ هزینه جانبی معتبر نیست.');
   if($costAmount>0)$costs[]=array('cost_code'=>sanitize_key(wp_unslash($_POST['ancillary_code']??'other')),'description'=>sanitize_text_field(wp_unslash($_POST['ancillary_description']??'هزینه جانبی خرید')),'amount_minor'=>(int)$costAmount);
   $id=MDA_Purchase_Service::create_draft(array('supplier_party_id'=>absint($_POST['supplier_party_id']??0),'warehouse_id'=>absint($_POST['warehouse_id']??0),'document_date'=>sanitize_text_field(wp_unslash($_POST['document_date']??'')),'currency_code'=>'IRR','idempotency_key'=>'purchase-draft-'.wp_generate_uuid4(),'lines'=>$lines,'ancillary_costs'=>$costs,'notes'=>sanitize_textarea_field(wp_unslash($_POST['notes']??''))));
   $url=add_query_arg(array('page'=>'mda-purchases','mda_notice'=>'purchase_draft_created','purchase_id'=>$id),admin_url('admin.php'));
  }catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-purchases','mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));}
  wp_safe_redirect($url);exit;
 }

 public static function post_purchase(): void {
  self::guard();$id=absint($_POST['purchase_id']??0);check_admin_referer('mda_post_purchase_'.$id);
  try{$paid=filter_var((string)($_POST['paid_minor']??'0'),FILTER_VALIDATE_INT);if(false===$paid||$paid<0)throw new InvalidArgumentException('مبلغ پرداخت معتبر نیست.');
   $result=MDA_Purchase_Service::post($id,(int)$paid,absint($_POST['cash_account_id']??0),sanitize_key(wp_unslash($_POST['payment_method']??'cash')),'purchase-payment-'.$id);
   $url=add_query_arg(array('page'=>'mda-purchases','mda_notice'=>$result['duplicate']?'purchase_already_posted':'purchase_posted'),admin_url('admin.php'));
  }catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-purchases','mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));}
  wp_safe_redirect($url);exit;
 }

 public static function record_purchase_payment(): void {
  self::guard();$id=absint($_POST['purchase_id']??0);check_admin_referer('mda_record_purchase_payment_'.$id);
  try {
   $amount=filter_var((string)($_POST['amount_minor']??''),FILTER_VALIDATE_INT);
   if(false===$amount||$amount<1)throw new InvalidArgumentException('مبلغ پرداخت باید عدد صحیح مثبت باشد.');
   $date=sanitize_text_field(wp_unslash($_POST['payment_date']??''));
   $method=sanitize_key(wp_unslash($_POST['payment_method']??'cash'));
   $result=MDA_Purchase_Settlement_Service::record_payment($id,(int)$amount,absint($_POST['cash_account_id']??0),$method,'purchase-stage-payment-'.wp_generate_uuid4(),$date);
   $url=add_query_arg(array('page'=>'mda-purchases','purchase_id'=>$id,'mda_notice'=>$result['duplicate']?'payment_duplicate':'payment_recorded'),admin_url('admin.php'));
  } catch(Throwable $e) {$url=add_query_arg(array('page'=>'mda-purchases','purchase_id'=>$id,'mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));}
  wp_safe_redirect($url);exit;
 }

 public static function post_purchase_return(): void {
  self::guard();$id=absint($_POST['purchase_id']??0);check_admin_referer('mda_post_purchase_return_'.$id);
  try {
   $raw=wp_unslash($_POST['return_lines']??array());$lines=array();
   foreach((array)$raw as $lineId=>$row){$quantity=trim((string)($row['quantity']??''));if(''===$quantity)continue;$lines[]=array('purchase_line_id'=>absint($lineId),'quantity_scaled'=>self::parse_quantity($quantity));}
   if(!$lines)throw new InvalidArgumentException('حداقل مقدار یک کالا برای مرجوعی را وارد کنید.');
   $date=sanitize_text_field(wp_unslash($_POST['return_date']??''));
   $result=MDA_Purchase_Return_Service::post($id,$lines,'purchase-return-'.wp_generate_uuid4(),$date);
   $url=add_query_arg(array('page'=>'mda-purchases','purchase_id'=>$id,'mda_notice'=>'purchase_return_posted','return_id'=>$result['return_id']),admin_url('admin.php'));
  } catch(Throwable $e) {$url=add_query_arg(array('page'=>'mda-purchases','purchase_id'=>$id,'mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));}
  wp_safe_redirect($url);exit;
 }

 private static function parse_quantity(string $value): int {
  $value=strtr(trim($value),array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',','=>'.','٫'=>'.'));
  if(!preg_match('/^(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,6})?$/',$value))throw new InvalidArgumentException('مقدار کالا باید عدد مثبت با حداکثر ۶ رقم اعشار باشد.');
  $parts=explode('.',$value);$whole=(int)$parts[0];$fraction=str_pad($parts[1]??'',6,'0');if($whole>intdiv(PHP_INT_MAX,1000000))throw new OverflowException('مقدار کالا بیش از حد مجاز است.');
  $scaled=$whole*1000000+(int)$fraction;if($scaled<1)throw new InvalidArgumentException('مقدار کالا باید بزرگ‌تر از صفر باشد.');return $scaled;
 }

 public static function render_purchases(): void {
  self::guard();if(!MDA_Database::is_available()){echo '<div class="wrap"><div class="notice notice-error"><p>PDO_SQLite فعال نیست.</p></div></div>';return;}
  $db=MDA_Database::connection();
  $suppliers=$db->query("SELECT p.id,p.display_name,p.party_code FROM mda_parties p JOIN mda_party_roles r ON r.party_id=p.id AND r.role_key='supplier' WHERE p.is_active=1 AND p.archived_at IS NULL ORDER BY p.display_name")->fetchAll();
  $warehouses=MDA_Inventory_Service::warehouses();
  $cash=$db->query("SELECT id,account_code,account_name FROM mda_accounts WHERE account_type='asset' AND is_control=0 AND is_active=1 AND archived_at IS NULL ORDER BY account_code")->fetchAll();
  $rows=MDA_Purchase_Service::all(100);$purchaseId=absint($_GET['purchase_id']??0);$purchase=$purchaseId?MDA_Purchase_Service::get($purchaseId):null;
  $draft=$purchase&&'draft'===$purchase['document_status']?$purchase:null;
  $posted=$purchase&&'posted'===$purchase['document_status']?$purchase:null;
  $fmt=static function($v):string{return number_format((int)$v,0,'.',',');};
  ?>
  <div class="wrap" dir="rtl"><h1>خرید و انبار</h1><?php self::notices(); ?>
  <p>ثبت خرید، رسید انبار، تاریخچه قیمت و میانگین موزون در زمان قطعی‌سازی انجام می‌شود. پرداخت‌های بعدی و مرجوعی نیز به‌صورت اسناد مستقل و غیرقابل‌ویرایش ثبت می‌شوند. مبالغ به ریال هستند.</p>
  <div style="background:#fff;padding:18px;max-width:1100px;margin:16px 0"><h2>ثبت پیش‌نویس فاکتور خرید</h2>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_create_purchase"><?php wp_nonce_field('mda_create_purchase'); ?>
  <p><label>تأمین‌کننده <select name="supplier_party_id" required><option value="">انتخاب تأمین‌کننده</option><?php foreach($suppliers as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo esc_html($s['party_code'].' — '.$s['display_name']); ?></option><?php endforeach; ?></select></label>
  <label>انبار مقصد <select name="warehouse_id" required><?php foreach($warehouses as $w): if((int)$w['is_active']): ?><option value="<?php echo (int)$w['id']; ?>"><?php echo esc_html($w['warehouse_name']); ?></option><?php endif; endforeach; ?></select></label>
  <label>تاریخ <input type="date" name="document_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" required></label></p>
  <table class="widefat striped"><thead><tr><th>شناسه محصول ووکامرس</th><th>شناسه تنوع (اختیاری)</th><th>تعداد</th><th>قیمت واحد خرید (ریال)</th></tr></thead><tbody>
  <?php for($i=0;$i<5;$i++): ?><tr><td><input type="number" min="1" name="lines[<?php echo $i; ?>][product_id]"></td><td><input type="number" min="0" name="lines[<?php echo $i; ?>][variation_id]" value="0"></td><td><input type="text" placeholder="مثلاً 2.5" name="lines[<?php echo $i; ?>][quantity]"></td><td><input type="number" min="0" step="1" name="lines[<?php echo $i; ?>][unit_cost_minor]"></td></tr><?php endfor; ?>
  </tbody></table>
  <p><label>شرح هزینه جانبی <input class="regular-text" name="ancillary_description" value="حمل و بسته‌بندی"></label>
  <label>نوع هزینه <select name="ancillary_code"><option value="shipping">حمل</option><option value="packaging">بسته‌بندی</option><option value="other">سایر</option></select></label>
  <label>مبلغ هزینه جانبی (ریال) <input type="number" min="0" step="1" name="ancillary_amount_minor" value="0"></label></p>
  <p><label>یادداشت<br><textarea class="large-text" rows="2" name="notes"></textarea></label></p><button class="button button-primary">ذخیره پیش‌نویس</button></form></div>
  <?php if($draft): ?>
  <div style="background:#fff;padding:18px;max-width:1100px;margin:16px 0"><h2>قطعی‌سازی <?php echo esc_html($draft['document_number']); ?></h2>
  <p>تأمین‌کننده: <?php echo esc_html($draft['supplier_name']); ?> | انبار: <?php echo esc_html($draft['warehouse_name']); ?> | جمع کالا: <?php echo esc_html($fmt($draft['subtotal_minor'])); ?> | هزینه جانبی: <?php echo esc_html($fmt($draft['ancillary_cost_minor'])); ?> | جمع نهایی: <strong><?php echo esc_html($fmt($draft['total_minor'])); ?> ریال</strong></p>
  <table class="widefat striped"><thead><tr><th>کالا</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr></thead><tbody><?php foreach($draft['lines'] as $l): ?><tr><td><?php echo esc_html($l['product_name_snapshot'].' ('.$l['product_id'].')'); ?></td><td><?php echo esc_html(number_format((int)$l['quantity_scaled']/1000000,6)); ?></td><td><?php echo esc_html($fmt($l['unit_cost_minor'])); ?></td><td><?php echo esc_html($fmt($l['line_total_minor'])); ?></td></tr><?php endforeach; ?></tbody></table>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:14px"><input type="hidden" name="action" value="mda_post_purchase"><input type="hidden" name="purchase_id" value="<?php echo (int)$draft['id']; ?>"><?php wp_nonce_field('mda_post_purchase_'.(int)$draft['id']); ?>
  <label>مبلغ پرداخت‌شده (ریال) <input type="number" min="0" max="<?php echo (int)$draft['total_minor']; ?>" name="paid_minor" value="0"></label>
  <label>صندوق/بانک <select name="cash_account_id"><option value="0">بدون پرداخت (نسیه)</option><?php foreach($cash as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo esc_html($a['account_code'].' — '.$a['account_name']); ?></option><?php endforeach; ?></select></label>
  <label>روش پرداخت <select name="payment_method"><option value="cash">نقد</option><option value="bank">بانک</option><option value="pos">کارتخوان</option><option value="transfer">انتقال</option></select></label>
  <p><strong>هشدار:</strong> قطعی‌سازی، موجودی و سند مالی را ثبت می‌کند و قابل ویرایش/حذف نیست.</p><button class="button button-primary" onclick="return confirm('فاکتور قطعی شود و موجودی و سند مالی ثبت شود؟')">قطعی‌سازی فاکتور</button></form></div>
  <?php elseif($posted): ?>
  <?php $summary=MDA_Purchase_Settlement_Service::summary((int)$posted['id']);$payments=MDA_Purchase_Settlement_Service::payments((int)$posted['id']); ?>
  <div style="background:#fff;padding:18px;max-width:1150px;margin:16px 0"><h2>جزئیات فاکتور قطعی <?php echo esc_html($posted['document_number']); ?></h2>
  <p>تأمین‌کننده: <?php echo esc_html($posted['supplier_name']); ?> | انبار: <?php echo esc_html($posted['warehouse_name']); ?> | تاریخ: <?php echo esc_html($posted['document_date']); ?></p>
  <p>مبلغ فاکتور: <strong><?php echo esc_html($fmt($summary['total_minor'])); ?> ریال</strong> | پرداخت‌شده: <?php echo esc_html($fmt($summary['paid_minor'])); ?> ریال | مانده: <strong><?php echo esc_html($fmt($summary['payable_minor'])); ?> ریال</strong></p>
  <h3>سوابق پرداخت</h3><table class="widefat striped"><thead><tr><th>تاریخ</th><th>مبلغ (ریال)</th><th>حساب</th><th>روش</th><th>سند حسابداری</th></tr></thead><tbody>
  <?php foreach($payments as $payment): ?><tr><td><?php echo esc_html(substr((string)$payment['paid_at'],0,10)); ?></td><td><?php echo esc_html($fmt($payment['amount_minor'])); ?></td><td><?php echo esc_html($payment['account_code'].' — '.$payment['account_name']); ?></td><td><?php echo esc_html(array('cash'=>'نقد','bank'=>'بانک','pos'=>'کارتخوان','transfer'=>'انتقال')[$payment['payment_method']]??$payment['payment_method']); ?></td><td><?php echo esc_html($payment['accounting_document_number']??'—'); ?></td></tr><?php endforeach; ?>
  <?php if(!$payments): ?><tr><td colspan="5">پرداختی ثبت نشده است.</td></tr><?php endif; ?></tbody></table>
  <?php if($summary['payable_minor']>0): ?><h3>ثبت پرداخت جدید</h3><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_record_purchase_payment"><input type="hidden" name="purchase_id" value="<?php echo (int)$posted['id']; ?>"><?php wp_nonce_field('mda_record_purchase_payment_'.(int)$posted['id']); ?>
  <label>مبلغ پرداخت (ریال) <input required type="number" min="1" max="<?php echo (int)$summary['payable_minor']; ?>" name="amount_minor"></label>
  <label>تاریخ پرداخت <input required type="date" name="payment_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></label>
  <label>صندوق/بانک <select required name="cash_account_id"><option value="">انتخاب حساب</option><?php foreach($cash as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo esc_html($a['account_code'].' — '.$a['account_name']); ?></option><?php endforeach; ?></select></label>
  <label>روش پرداخت <select name="payment_method"><option value="cash">نقد</option><option value="bank">بانک</option><option value="pos">کارتخوان</option><option value="transfer">انتقال</option></select></label>
  <p><button class="button button-primary">ثبت پرداخت</button></p></form><?php else: ?><p><strong>این فاکتور تسویه شده است.</strong></p><?php endif; ?>
  <h3>ثبت مرجوعی کالا به تأمین‌کننده</h3><p>مقدار مرجوعی بر اساس موجودی قابل‌مرجوعی و بهای میانگین دفتری فعلی محاسبه می‌شود. برای یک کالا/تنوع که در چند ردیف فاکتور آمده، در هر سند فقط یکی از ردیف‌ها را انتخاب کنید. این عملیات موجودی را کاهش داده و سند اصلاحی مستقل ایجاد می‌کند؛ وجه نقدی به‌صورت خودکار مسترد نمی‌شود.</p>
  <?php
  $returnedQuery=$db->prepare('SELECT COALESCE(SUM(rl.quantity_scaled),0) FROM mda_purchase_return_lines rl JOIN mda_purchase_returns r ON r.id=rl.return_id WHERE rl.purchase_line_id=:line');
  $stockQuery=$db->prepare('SELECT COALESCE(SUM(quantity_scaled),0) FROM mda_inventory_movements WHERE product_id=:product AND variation_id=:variation AND warehouse_id=:warehouse AND quantity_scale=:scale');
  $returnRows=$db->prepare('SELECT r.return_number,r.document_date,rl.line_total_minor,rl.product_id,rl.variation_id,rl.quantity_scaled,rl.quantity_scale FROM mda_purchase_returns r JOIN mda_purchase_return_lines rl ON rl.return_id=r.id WHERE r.purchase_id=:id ORDER BY r.id,rl.id');
  $returnRows->execute(array(':id'=>$posted['id']));$returnHistory=$returnRows->fetchAll();
  ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_post_purchase_return"><input type="hidden" name="purchase_id" value="<?php echo (int)$posted['id']; ?>"><?php wp_nonce_field('mda_post_purchase_return_'.(int)$posted['id']); ?>
  <table class="widefat striped"><thead><tr><th>کالا</th><th>خرید اولیه</th><th>مرجوع‌شده</th><th>موجودی فعلی انبار</th><th>حداکثر قابل ثبت</th><th>مقدار مرجوعی</th></tr></thead><tbody>
  <?php foreach($posted['lines'] as $line):
   $returnedQuery->execute(array(':line'=>$line['id']));$returnedQty=(int)$returnedQuery->fetchColumn();
   $stockQuery->execute(array(':product'=>$line['product_id'],':variation'=>$line['variation_id'],':warehouse'=>$posted['warehouse_id'],':scale'=>$line['quantity_scale']));$stockQty=max(0,(int)$stockQuery->fetchColumn());
   $remaining=max(0,(int)$line['quantity_scaled']-$returnedQty);$maximum=min($remaining,$stockQty);
  ?>
  <tr><td><?php echo esc_html($line['product_name_snapshot'].' (#'.$line['product_id'].')'); ?></td><td><?php echo esc_html(number_format((int)$line['quantity_scaled']/1000000,6)); ?></td><td><?php echo esc_html(number_format($returnedQty/1000000,6)); ?></td><td><?php echo esc_html(number_format($stockQty/1000000,6)); ?></td><td><?php echo esc_html(number_format($maximum/1000000,6)); ?></td><td><?php if($maximum>0): ?><input type="text" inputmode="decimal" name="return_lines[<?php echo (int)$line['id']; ?>][quantity]" placeholder="مثلاً 0.5" aria-label="<?php echo esc_attr('مقدار مرجوعی '.$line['product_name_snapshot']); ?>"><?php else: ?>—<?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
  <p><label>تاریخ مرجوعی <input type="date" required name="return_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></label></p>
  <p><button class="button button-secondary" onclick="return confirm('سند مرجوعی قطعی و خروج موجودی ثبت شود؟')">ثبت مرجوعی و سند اصلاحی</button></p></form>
  <?php if($returnHistory): ?><h3>سوابق مرجوعی</h3><table class="widefat striped"><thead><tr><th>شماره سند</th><th>تاریخ</th><th>محصول</th><th>مقدار</th><th>مبلغ سند (ریال)</th></tr></thead><tbody><?php foreach($returnHistory as $rh): ?><tr><td><?php echo esc_html($rh['return_number']); ?></td><td><?php echo esc_html($rh['document_date']); ?></td><td><?php echo esc_html('#'.$rh['product_id'].' / '.$rh['variation_id']); ?></td><td><?php echo esc_html(number_format((int)$rh['quantity_scaled']/(int)$rh['quantity_scale'],6)); ?></td><td><?php echo esc_html($fmt($rh['line_total_minor'])); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mda-purchases')); ?>">بازگشت به فهرست خرید</a></p></div>
  <?php endif; ?>
  <h2>فاکتورهای خرید</h2><table class="widefat striped"><thead><tr><th>شماره</th><th>تاریخ</th><th>تأمین‌کننده</th><th>مبلغ کل</th><th>پرداخت</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
  <?php foreach($rows as $r): ?><tr><td><?php echo esc_html($r['document_number']); ?></td><td><?php echo esc_html($r['document_date']); ?></td><td><?php echo esc_html($r['supplier_name']); ?></td><td><?php echo esc_html($fmt($r['total_minor'])); ?></td><td><?php echo esc_html($fmt($r['paid_minor'])); ?></td><td><?php echo 'posted'===$r['document_status']?'قطعی':'پیش‌نویس'; ?></td><td><a href="<?php echo esc_url(add_query_arg(array('page'=>'mda-purchases','purchase_id'=>(int)$r['id']),admin_url('admin.php'))); ?>"><?php echo 'draft'===$r['document_status']?'بررسی و قطعی‌سازی':'جزئیات / پرداخت / مرجوعی'; ?></a></td></tr><?php endforeach; ?>
  <?php if(!$rows): ?><tr><td colspan="7">فاکتوری ثبت نشده است.</td></tr><?php endif; ?></tbody></table></div>
  <?php
 }

 public static function render_parties(): void {
  self::guard();if(!MDA_Database::is_available()){echo '<div class="wrap"><div class="notice notice-error"><p>برای مدیریت طرف‌حساب‌ها باید PDO_SQLite فعال باشد.</p></div></div>';return;}$edit=!empty($_GET['edit'])?MDA_Party_Service::get(absint($_GET['edit'])):null;$roles=array('customer'=>'مشتری','supplier'=>'تأمین‌کننده','employee'=>'کارمند','partner'=>'شریک','other'=>'سایر');$rows=MDA_Party_Service::search(sanitize_text_field(wp_unslash($_GET['s']??'')));
  ?>
  <div class="wrap" dir="rtl"><h1>مدیریت طرف‌حساب‌ها</h1><?php self::notices(); ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;background:#fff;padding:18px;margin:16px 0">
  <input type="hidden" name="action" value="mda_save_party"><input type="hidden" name="party_id" value="<?php echo esc_attr($edit['id']??''); ?>"><?php wp_nonce_field('mda_save_party'); ?>
  <h2><?php echo $edit?'ویرایش طرف‌حساب':'افزودن طرف‌حساب'; ?></h2>
  <p><label>نام / عنوان <input required class="regular-text" name="display_name" value="<?php echo esc_attr($edit['display_name']??''); ?>"></label>
  <label>نوع <select name="party_kind"><option value="person" <?php selected($edit['party_kind']??'person','person'); ?>>شخص</option><option value="company" <?php selected($edit['party_kind']??'person','company'); ?>>شرکت</option></select></label></p>
  <p><label>کد (خودکار) <input class="regular-text" name="party_code" value="<?php echo esc_attr($edit['party_code']??''); ?>" <?php echo $edit?'readonly':''; ?>></label>
  <label>تلفن <input class="regular-text" name="phone" value="<?php echo esc_attr($edit['phone']??''); ?>"></label>
  <label>ایمیل <input type="email" class="regular-text" name="email" value="<?php echo esc_attr($edit['email']??''); ?>"></label></p>
  <p><label>نام شرکت <input class="regular-text" name="company_name" value="<?php echo esc_attr($edit['company_name']??''); ?>"></label>
  <label>شناسه اقتصادی <input class="regular-text" name="economic_code" value="<?php echo esc_attr($edit['economic_code']??''); ?>"></label>
  <label>کدپستی <input class="regular-text" name="postal_code" value="<?php echo esc_attr($edit['postal_code']??''); ?>"></label></p>
  <p><label>نقش‌ها: </label><?php foreach($roles as $key=>$label): ?><label style="margin-left:14px"><input type="checkbox" name="roles[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key,$edit['roles']??array(),true)); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></p>
  <p><label>نشانی<br><textarea name="address" rows="2" class="large-text"><?php echo esc_textarea($edit['address']??''); ?></textarea></label></p>
  <p><label>یادداشت<br><textarea name="notes" rows="2" class="large-text"><?php echo esc_textarea($edit['notes']??''); ?></textarea></label></p>
  <p><button class="button button-primary">ذخیره طرف‌حساب</button> <?php if($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mda-parties')); ?>">انصراف</a><?php endif; ?></p></form>
  <form method="get"><input type="hidden" name="page" value="mda-parties"><input type="search" name="s" value="<?php echo esc_attr($_GET['s']??''); ?>" placeholder="جست‌وجوی نام، کد یا تلفن"><button class="button">جست‌وجو</button></form>
  <table class="widefat striped" style="margin-top:12px"><thead><tr><th>کد</th><th>نام</th><th>تلفن</th><th>نقش‌ها</th><th>عملیات</th></tr></thead><tbody>
  <?php foreach($rows as $row): ?><tr><td><?php echo esc_html($row['party_code']); ?></td><td><?php echo esc_html($row['display_name']); ?></td><td><?php echo esc_html($row['phone']??''); ?></td><td><?php echo esc_html(str_replace(array('customer','supplier','employee','partner','other'),array('مشتری','تأمین‌کننده','کارمند','شریک','سایر'),(string)$row['roles'])); ?></td><td><a href="<?php echo esc_url(add_query_arg(array('page'=>'mda-parties','edit'=>(int)$row['id']),admin_url('admin.php'))); ?>">ویرایش</a> | <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action'=>'mda_archive_party','party_id'=>(int)$row['id']),admin_url('admin-post.php')),'mda_archive_party_'.(int)$row['id'])); ?>" onclick="return confirm('طرف‌حساب بایگانی شود؟')">بایگانی</a></td></tr><?php endforeach; ?>
  <?php if(empty($rows)): ?><tr><td colspan="5">طرف‌حسابی ثبت نشده است.</td></tr><?php endif; ?></tbody></table></div>
  <?php
 }

 public static function render_accounts(): void {
  self::guard();if(!MDA_Database::is_available()){echo '<div class="wrap"><div class="notice notice-error"><p>برای مدیریت کدینگ حساب‌ها باید PDO_SQLite فعال باشد.</p></div></div>';return;}$rows=MDA_Account_Service::all(true);$types=array('asset'=>'دارایی','liability'=>'بدهی','equity'=>'حقوق مالکانه','income'=>'درآمد','expense'=>'هزینه');
  ?>
  <div class="wrap" dir="rtl"><h1>کدینگ حساب‌ها</h1><?php self::notices(); ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;background:#fff;padding:18px;margin:16px 0">
  <input type="hidden" name="action" value="mda_save_account"><?php wp_nonce_field('mda_save_account'); ?><h2>افزودن حساب</h2>
  <p><label>کد حساب <input required name="account_code" class="regular-text"></label> <label>نام حساب <input required name="account_name" class="regular-text"></label></p>
  <p><label>گروه <select name="account_type"><?php foreach($types as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
  <label>حساب پدر <select name="parent_id"><option value="">بدون والد</option><?php foreach($rows as $r): if((int)$r['is_active']===1): ?><option value="<?php echo (int)$r['id']; ?>"><?php echo esc_html($r['account_code'].' — '.$r['account_name']); ?></option><?php endif; endforeach; ?></select></label>
  <label>ماهیت <select name="normal_balance"><option value="debit">بدهکار</option><option value="credit">بستانکار</option></select></label></p>
  <p><label><input type="checkbox" name="is_control" value="1"> حساب کنترلی</label> <label><input type="checkbox" name="allow_manual_entry" value="1" checked> ثبت مستقیم مجاز</label></p>
  <p><label>توضیحات<br><textarea name="description" class="large-text" rows="2"></textarea></label></p><button class="button button-primary">افزودن حساب</button></form>
  <table class="widefat striped"><thead><tr><th>کد</th><th>نام حساب</th><th>گروه</th><th>حساب پدر</th><th>ماهیت</th><th>وضعیت</th><th>ویژگی</th></tr></thead><tbody>
  <?php foreach($rows as $r): ?><tr><td><?php echo esc_html($r['account_code']); ?></td><td><?php echo esc_html($r['account_name']); ?></td><td><?php echo esc_html($types[$r['account_type']]??$r['account_type']); ?></td><td><?php echo esc_html(trim(($r['parent_code']??'').' '.($r['parent_name']??''))); ?></td><td><?php echo 'debit'===$r['normal_balance']?'بدهکار':'بستانکار'; ?></td><td><?php echo (int)$r['is_active']?'فعال':'غیرفعال'; ?></td><td><?php echo (int)$r['is_system']?'سیستمی':''; ?> <?php echo (int)$r['is_control']?'کنترلی':''; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php
 }

 public static function render_party_ledger(): void {
  self::guard();
  if(!MDA_Database::is_available()){echo '<div class="wrap"><div class="notice notice-error"><p>برای مشاهده گردش حساب باید PDO_SQLite فعال باشد.</p></div></div>';return;}
  $parties=MDA_Party_Service::search('',200);$role=sanitize_key(wp_unslash($_GET['role']??'customer'));if(!in_array($role,array('customer','supplier'),true))$role='customer';
  $party_id=absint($_GET['party_id']??0);$from=sanitize_text_field(wp_unslash($_GET['from']??current_time('Y-m-01')));$to=sanitize_text_field(wp_unslash($_GET['to']??current_time('Y-m-d')));$statement=null;$error='';
  if($party_id){try{$statement=MDA_Party_Ledger_Service::statement($party_id,$role,$from,$to);}catch(Throwable $e){$error=$e->getMessage();}}
  $fmt=static function($amount): string {return number_format((int)$amount,0,'.',',');};$balance_label=static function($amount,$normal): string {$is_normal=(int)$amount>=0;$is_debit=('debit'===$normal)===$is_normal;return $is_debit?'بدهکار':'بستانکار';};
  ?>
  <div class="wrap" dir="rtl"><h1>گردش حساب اشخاص</h1><p>این گزارش مستقیماً از اسناد قطعی دفتر روزنامه محاسبه می‌شود؛ مانده جداگانه‌ای ذخیره نمی‌شود.</p>
  <?php self::notices(); if($error): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
  <form method="get" style="background:#fff;padding:16px;max-width:1000px;margin:14px 0"><input type="hidden" name="page" value="mda-party-ledger">
  <label>طرف‌حساب <select name="party_id" required><option value="">انتخاب کنید</option><?php foreach($parties as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php selected($party_id,(int)$p['id']); ?>><?php echo esc_html($p['party_code'].' — '.$p['display_name']); ?></option><?php endforeach; ?></select></label>
  <label>نوع حساب <select name="role"><option value="customer" <?php selected($role,'customer'); ?>>مشتری / دریافتنی</option><option value="supplier" <?php selected($role,'supplier'); ?>>تأمین‌کننده / پرداختنی</option></select></label>
  <label>از تاریخ <input type="date" name="from" value="<?php echo esc_attr($from); ?>" required></label>
  <label>تا تاریخ <input type="date" name="to" value="<?php echo esc_attr($to); ?>" required></label>
  <button class="button button-primary">نمایش گردش</button></form>
  <?php if($statement): ?>
  <h2><?php echo esc_html($statement['party']['display_name']); ?> — <?php echo 'customer'===$role?'حساب دریافتنی':'حساب پرداختنی'; ?></h2>
  <p>حساب کنترلی: <?php echo esc_html($statement['party']['account_code'].' — '.$statement['party']['account_name']); ?></p>
  <table class="widefat striped" style="max-width:1000px"><tbody>
  <tr><th>مانده اول دوره</th><td><?php echo esc_html($fmt(abs($statement['opening_balance_minor']))); ?> <?php echo esc_html($balance_label($statement['opening_balance_minor'],$statement['party']['normal_balance'])); ?></td></tr>
  <tr><th>مانده پایان دوره</th><td><strong><?php echo esc_html($fmt(abs($statement['closing_balance_minor']))); ?> <?php echo esc_html($balance_label($statement['closing_balance_minor'],$statement['party']['normal_balance'])); ?></strong></td></tr>
  </tbody></table>
  <table class="widefat striped" style="margin-top:12px;max-width:1200px"><thead><tr><th>تاریخ</th><th>شماره سند</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th><th>مانده</th></tr></thead><tbody>
  <?php foreach($statement['lines'] as $line): ?><tr><td><?php echo esc_html($line['document_date']); ?></td><td><?php echo esc_html($line['document_number']); ?></td><td><?php echo esc_html($line['memo']?:$line['description']); ?></td><td><?php echo esc_html($fmt($line['debit_minor'])); ?></td><td><?php echo esc_html($fmt($line['credit_minor'])); ?></td><td><?php echo esc_html($fmt(abs($line['running_balance_minor'])).' '.$balance_label($line['running_balance_minor'],$statement['party']['normal_balance'])); ?></td></tr><?php endforeach; ?>
  <?php if(empty($statement['lines'])): ?><tr><td colspan="6">در بازه انتخاب‌شده گردش ثبت‌شده‌ای وجود ندارد.</td></tr><?php endif; ?></tbody></table>
  <?php endif; ?>
  <?php if(!$statement): $balances=MDA_Party_Ledger_Service::balances(200); ?>
  <h2>مانده تفصیلی ثبت‌شده</h2><table class="widefat striped" style="max-width:1100px"><thead><tr><th>کد</th><th>طرف‌حساب</th><th>نقش</th><th>حساب کنترلی</th><th>بدهکار</th><th>بستانکار</th><th>مانده</th></tr></thead><tbody>
  <?php foreach($balances as $b): $is_debit=('debit'===$b['normal_balance'])===((int)$b['balance_minor']>=0); ?>
  <tr><td><?php echo esc_html($b['party_code']); ?></td><td><?php echo esc_html($b['display_name']); ?></td><td><?php echo 'customer'===$b['role_key']?'مشتری':'تأمین‌کننده'; ?></td><td><?php echo esc_html($b['account_code'].' — '.$b['account_name']); ?></td><td><?php echo esc_html($fmt($b['debit_total'])); ?></td><td><?php echo esc_html($fmt($b['credit_total'])); ?></td><td><?php echo esc_html($fmt(abs($b['balance_minor'])).' '.($is_debit?'بدهکار':'بستانکار')); ?></td></tr>
  <?php endforeach; if(empty($balances)): ?><tr><td colspan="7">هنوز گردش تفصیلی قطعی ثبت نشده است.</td></tr><?php endif; ?></tbody></table>
  <?php endif; ?></div>
  <?php
 }

 private static function notices(): void {
  if(!empty($_GET['mda_notice'])) {
   $messages=array('party_saved'=>'طرف‌حساب ذخیره شد.','party_archived'=>'طرف‌حساب بایگانی شد.','account_saved'=>'حساب ذخیره شد.','purchase_draft_created'=>'پیش‌نویس خرید ایجاد شد.','purchase_posted'=>'فاکتور قطعی و موجودی ثبت شد.','purchase_already_posted'=>'این فاکتور قبلاً قطعی شده است.','payment_recorded'=>'پرداخت مرحله‌ای ثبت شد.','payment_duplicate'=>'این پرداخت قبلاً ثبت شده است.','purchase_return_posted'=>'مرجوعی و سند اصلاحی ثبت شد.');
   $key=sanitize_key(wp_unslash($_GET['mda_notice']));$message=$messages[$key]??'عملیات با موفقیت انجام شد.';
   echo '<div class="notice notice-success"><p>'.esc_html($message).'</p></div>';
  }
  if(!empty($_GET['mda_error'])) echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['mda_error']))).'</p></div>';
 }
}

<?php
defined( 'ABSPATH' ) || exit;

final class MDA_Admin {
 public static function init(): void {
  add_action('admin_menu',array(__CLASS__,'menu'));
  add_action('admin_enqueue_scripts',array(__CLASS__,'enqueue_assets'));
  add_filter('admin_body_class',array(__CLASS__,'body_class'));
  add_action('admin_post_mda_save_party',array(__CLASS__,'save_party'));
  add_action('admin_post_mda_archive_party',array(__CLASS__,'archive_party'));
  add_action('admin_post_mda_save_account',array(__CLASS__,'save_account'));
  add_action('admin_post_mda_create_purchase',array(__CLASS__,'create_purchase'));
  add_action('admin_post_mda_post_purchase',array(__CLASS__,'post_purchase'));
  add_action('admin_post_mda_record_purchase_payment',array(__CLASS__,'record_purchase_payment'));
  add_action('admin_post_mda_post_purchase_return',array(__CLASS__,'post_purchase_return'));
  add_action('admin_post_mda_record_sales_receipt',array(__CLASS__,'record_sales_receipt'));
  add_action('admin_post_mda_post_sales_return',array(__CLASS__,'post_sales_return'));
  add_action('admin_post_mda_record_sales_refund',array(__CLASS__,'record_sales_refund'));
  add_action('admin_post_mda_run_woocommerce_reconciliation',array(__CLASS__,'run_woocommerce_reconciliation'));
  add_action('admin_post_mda_run_woocommerce_stock_sync',array(__CLASS__,'run_woocommerce_stock_sync'));
  add_action('admin_post_mda_record_cash_transaction',array(__CLASS__,'record_cash_transaction'));
  add_action('admin_post_mda_import_bank_statement',array(__CLASS__,'import_bank_statement'));
  add_action('admin_post_mda_match_bank_statement_line',array(__CLASS__,'match_bank_statement_line'));
  add_action('admin_post_mda_unmatch_bank_statement_line',array(__CLASS__,'unmatch_bank_statement_line'));
  add_action('admin_post_mda_ignore_bank_statement_line',array(__CLASS__,'ignore_bank_statement_line'));
  add_action('admin_post_mda_unignore_bank_statement_line',array(__CLASS__,'unignore_bank_statement_line'));
 }

 public static function menu(): void {
  add_submenu_page(MDA_Admin_Shell::HIDDEN_PARENT,'طرف‌حساب‌ها','طرف‌حساب‌ها',MDA_Capabilities::for_page('mda-parties'),'mda-parties',array(__CLASS__,'render_parties'));
  add_submenu_page(MDA_Admin_Shell::HIDDEN_PARENT,'کدینگ حساب‌ها','کدینگ حساب‌ها',MDA_Capabilities::for_page('mda-accounts'),'mda-accounts',array(__CLASS__,'render_accounts'));
  add_submenu_page(MDA_Admin_Shell::HIDDEN_PARENT,'گردش حساب اشخاص','گردش حساب اشخاص',MDA_Capabilities::for_page('mda-party-ledger'),'mda-party-ledger',array(__CLASS__,'render_party_ledger'));
  add_submenu_page(MDA_Admin_Shell::HIDDEN_PARENT,'خرید و فاکتور خرید','خرید و انبار',MDA_Capabilities::for_page('mda-purchases'),'mda-purchases',array(__CLASS__,'render_purchases'));
  add_submenu_page(MDA_Admin_Shell::HIDDEN_PARENT,'دریافت از مشتری','دریافت از مشتری',MDA_Capabilities::for_page('mda-sales-receipts'),'mda-sales-receipts',array(__CLASS__,'render_sales_receipts'));
  add_submenu_page(MDA_Admin_Shell::HIDDEN_PARENT,'مرجوعی فروش و استرداد','مرجوعی فروش',MDA_Capabilities::for_page('mda-sales-returns'),'mda-sales-returns',array(__CLASS__,'render_sales_returns'));
  add_submenu_page(MDA_Admin_Shell::HIDDEN_PARENT,'تطبیق سفارش‌های ووکامرس','تطبیق سفارش‌ها',MDA_Capabilities::for_page('mda-reconciliation'),'mda-reconciliation',array(__CLASS__,'render_reconciliation'));
  add_submenu_page(MDA_Admin_Shell::HIDDEN_PARENT,'همگام‌سازی موجودی ووکامرس','همگام‌سازی موجودی',MDA_Capabilities::for_page('mda-stock-sync'),'mda-stock-sync',array(__CLASS__,'render_stock_sync'));
  add_submenu_page(MDA_Admin_Shell::HIDDEN_PARENT,'گزارش‌های مالی','گزارش‌های مالی',MDA_Capabilities::for_page('mda-reports'),'mda-reports',array(__CLASS__,'render_reports'));
  add_submenu_page(MDA_Admin_Shell::HIDDEN_PARENT,'صندوق، بانک و هزینه‌ها','صندوق و بانک',MDA_Capabilities::for_page('mda-cashbook'),'mda-cashbook',array(__CLASS__,'render_cashbook'));
  add_submenu_page(MDA_Admin_Shell::HIDDEN_PARENT,'مغایرت‌گیری بانکی','مغایرت‌گیری بانکی',MDA_Capabilities::for_page('mda-bank-reconciliation'),'mda-bank-reconciliation',array(__CLASS__,'render_bank_reconciliation'));
 }

 public static function body_class(string $classes): string {
  $screen=function_exists('get_current_screen')?get_current_screen():null;
  if($screen && (strpos((string)$screen->id,'mda-')!==false || strpos((string)$screen->id,'mda_accounting')!==false))$classes.=' wca-skin mda-admin';
  return $classes;
 }
 public static function enqueue_assets(string $hook): void {
  $screen=function_exists('get_current_screen')?get_current_screen():null;
  if(!$screen || (strpos((string)$screen->id,'mda-')===false && strpos((string)$screen->id,'mda_accounting')===false))return;
  if(false!==strpos((string)$screen->id,'mda-purchases')&&wp_script_is('wc-enhanced-select','registered')){wp_enqueue_script('wc-enhanced-select');wp_enqueue_style('woocommerce_admin_styles');}
  $base=MDA_PATH.'assets/';
  $skin=MDA_URL.'assets/skin/';
  wp_enqueue_style('woocamers-bootstrap',$skin.'vendor/bootstrap.scoped.rtl.min.css',array(),'5.3.8');
  wp_enqueue_style('woocamers-admin-ui',$skin.'admin-ui.css',array('woocamers-bootstrap'),MDA_VERSION);
  wp_enqueue_script('woocamers-bootstrap',$skin.'vendor/bootstrap.bundle.min.js',array(),'5.3.8',true);
  wp_enqueue_script('woocamers-admin-ui',$skin.'admin-ui.js',array('jquery','woocamers-bootstrap'),MDA_VERSION,true);
  wp_enqueue_style('mda-admin',MDA_URL.'assets/css/admin.css',array('woocamers-admin-ui'),(string)filemtime($base.'css/admin.css'));
  wp_enqueue_script('mda-admin',MDA_URL.'assets/js/admin.js',array('jquery','woocamers-admin-ui'),(string)filemtime($base.'js/admin.js'),true);
  wp_enqueue_script('mda-jalali',MDA_URL.'assets/js/mda-jalali.js',array('mda-admin'),(string)filemtime($base.'js/mda-jalali.js'),true);
  $jalali=1;try{$jalali=(int)MDA_Settings::get('jalali_dates');}catch(Throwable $e){}
  wp_add_inline_script('mda-jalali','window.mdaSettings='.wp_json_encode(array('jalali'=>$jalali)).';','before');
 }
 private static function guard(): void {
  if(!MDA_Capabilities::user_can_action()) wp_die(esc_html__('شما اجازه دسترسی به این بخش را ندارید.','malidev-accounting'));
 }
 public static function render(): void {
  self::guard();
  $available=MDA_Database::is_available();$error='';$integrity='—';
  if($available){try{$integrity=(string)MDA_Database::connection()->query('PRAGMA quick_check')->fetchColumn();}catch(Throwable $e){$error=$e->getMessage();}}
  $cur=MDA_Settings::currency_label();
  $money=static function(int $v)use($cur):string{return number_format_i18n($v).'<small>'.esc_html($cur).'</small>';};
  $today=$available?MDA_Dashboard_Service::sales_since(current_time('Y-m-d')):array('count'=>0,'total'=>0,'profit'=>0);
  $month=$available?MDA_Dashboard_Service::sales_since(MDA_Dashboard_Service::month_start()):array('count'=>0,'total'=>0,'profit'=>0);
  $ar=$available?MDA_Dashboard_Service::balance('12'):0;$ap=$available?-MDA_Dashboard_Service::balance('21'):0;$cash=$available?MDA_Dashboard_Service::balance('11'):0;
  $att=$available?MDA_Dashboard_Service::attention():array();$days=$available?MDA_Dashboard_Service::daily_sales(14):array();$recent=$available?MDA_Dashboard_Service::recent_sales(6):array();
  $max=max(1,...array_map(static fn($d)=>$d['total'],$days?:array(array('total'=>0))));
  $margin=$month['total']>0?round($month['profit']/max(1,$month['total'])*100):0;
  $u=static fn(string $slug):string=>admin_url('admin.php?page='.$slug);
  $kpis=array(
   array('is-teal','dashicons-cart','فروش امروز',$money($today['total']),number_format_i18n($today['count']).' سفارش'),
   array('','dashicons-chart-line','فروش این ماه',$money($month['total']),number_format_i18n($month['count']).' سفارش'),
   array('is-ok','dashicons-chart-pie','سود ناخالص این ماه',$money($month['profit']),'حاشیه سود '.number_format_i18n($margin).'٪'),
   array('is-info','dashicons-bank','صندوق و بانک',$money($cash),'مانده حساب‌های ۱۱۰۰'),
   array('is-violet','dashicons-groups','طلب از مشتریان',$money($ar),'حساب‌های دریافتنی'),
   array('is-warn','dashicons-businessperson','بدهی به تأمین‌کنندگان',$money($ap),'حساب‌های پرداختنی'),
  );
  $tasks=array(
   array('failed','dashicons-warning','is-bad','رویداد ناموفق صف فروش','mda-phone-sales'),
   array('review','dashicons-flag','is-warn','مرجوعی نیازمند بررسی','mda-settings'),
   array('backorders','dashicons-clock','is-warn','سفارش معوق (منتظر خرید)','mda-inventory'),
   array('cheques','dashicons-calendar-alt','is-info','چک تا ۷ روز آینده','mda-cheques'),
   array('low_stock','dashicons-archive','is-violet','کالای کم‌موجودی','mda-products'),
   array('pending','dashicons-update','is-teal','سفارش در صف ثبت حسابداری','mda-phone-sales'),
  );
  $actions=array(
   array('','dashicons-plus-alt2','ثبت سفارش دستی','سفارش تلفنی یا حضوری که در فهرست سفارش‌های ووکامرس هم ثبت می‌شود.','mda-phone-sales'),
   array('is-teal','dashicons-download','ثبت خرید','فاکتور خرید، ورود کالا به انبار و بدهی به تأمین‌کننده.','mda-purchases'),
   array('is-info','dashicons-screenoptions','اتاق کنترل محصولات','قیمت، موجودی، تأمین‌کننده و تخفیف همه کالاها در یک جدول.','mda-products'),
   array('is-ok','dashicons-money-alt','دریافت از مشتری','ثبت دریافت‌های نقدی، کارتی یا چک و مانده هر فاکتور.','mda-sales-receipts'),
   array('is-violet','dashicons-archive','انبارها و انتقال','موجودی هر انبار و جابه‌جایی کالا بین انبارها.','mda-warehouses'),
   array('is-warn','dashicons-chart-bar','گزارش سود و زیان','سود هر کانال، هر محصول و هر سفارش.','mda-reports'),
  );
  ?>
  <div class="wrap mda-dashboard" dir="rtl"><h1 class="screen-reader-text">داشبورد حسابداری</h1><hr class="wp-header-end">
   <section class="mda-hero"><div class="mda-hero__row"><div><span class="mda-hero__badge">MALIDEV ACCOUNTING · نسخه <?php echo esc_html(MDA_VERSION); ?></span><h2>مدیریت مالی فروشگاه، یکپارچه و دقیق</h2><p>خرید، فروش، انبار، خزانه و سود فروشگاه در یک نگاه. سفارش‌های ووکامرس خودکار ثبت حسابداری می‌شوند.</p></div>
    <div class="mda-hero__cta"><a href="<?php echo esc_url($u('mda-phone-sales')); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>سفارش دستی</a><a class="is-ghost" href="<?php echo esc_url($u('mda-reports')); ?>"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>گزارش‌ها</a></div></div></section>
   <?php if(!$available): ?><div class="notice notice-error"><p>افزونه PDO_SQLite روی سرور فعال نیست؛ از میزبان بخواهید آن را فعال کند.</p></div><?php endif; ?>
   <div class="mda-grid mda-grid-3">
    <?php foreach($kpis as $k): ?><div class="mda-card mda-kpi <?php echo esc_attr($k[0]); ?>"><span class="mda-kpi__icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr($k[1]); ?>"></span></span><div class="mda-kpi__body"><span class="mda-kpi__label"><?php echo esc_html($k[2]); ?></span><span class="mda-kpi__value"><?php echo wp_kses_post($k[3]); ?></span><span class="mda-kpi__sub"><?php echo esc_html($k[4]); ?></span></div></div><?php endforeach; ?>
   </div>
   <div class="mda-grid-2">
    <div class="mda-card"><div class="mda-card__head"><h3>فروش ۱۴ روز اخیر</h3><a href="<?php echo esc_url($u('mda-reports')); ?>">گزارش کامل ←</a></div>
     <div class="mda-chart" role="img" aria-label="نمودار فروش روزانه">
      <?php foreach($days as $i=>$d): $h=$d['total']>0?max(4,round($d['total']/$max*100)):3; ?>
       <div class="mda-chart__bar<?php echo $d['total']>0?'':' is-zero'; ?>" title="<?php echo esc_attr(MDA_Jalali::format($d['date']).' — '.number_format_i18n($d['total']).' '.$cur); ?>"><i style="height:<?php echo (int)$h; ?>%;animation-delay:<?php echo (int)($i*30); ?>ms"></i><span><?php echo esc_html(mb_substr(MDA_Jalali::format($d['date']),-2)); ?></span></div>
      <?php endforeach; ?>
     </div></div>
    <div class="mda-card"><div class="mda-card__head"><h3>نیازمند رسیدگی</h3></div><ul class="mda-list">
     <?php foreach($tasks as $t): $n=(int)($att[$t[0]]??0); ?><li><span class="mda-list__label <?php echo esc_attr($t[2]); ?>"><span class="mda-kpi__icon" style="width:32px;height:32px;border-radius:10px" aria-hidden="true"><span class="dashicons <?php echo esc_attr($t[1]); ?>" style="font-size:16px;width:16px;height:16px"></span></span><a href="<?php echo esc_url($u($t[4])); ?>" style="text-decoration:none;color:inherit"><?php echo esc_html($t[3]); ?></a></span><span class="mda-pill <?php echo $n>0?($t[2]==='is-bad'?'mda-pill--bad':'mda-pill--warn'):'mda-pill--ok'; ?>"><?php echo esc_html(number_format_i18n($n)); ?></span></li><?php endforeach; ?>
    </ul></div>
   </div>
   <h2 class="mda-section-title">کارهای پرتکرار</h2>
   <div class="mda-grid mda-grid-3">
    <?php foreach($actions as $a): if(!current_user_can(MDA_Capabilities::for_page($a[4])))continue; ?><a class="mda-card mda-action <?php echo esc_attr($a[0]); ?>" href="<?php echo esc_url($u($a[4])); ?>"><span class="mda-action__icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr($a[1]); ?>"></span></span><span class="mda-action__body"><strong><?php echo esc_html($a[2]); ?></strong><span><?php echo esc_html($a[3]); ?></span></span><span class="mda-action__arrow dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></a><?php endforeach; ?>
   </div>
   <div class="mda-grid-2">
    <div class="mda-card"><div class="mda-card__head"><h3>آخرین فروش‌های ثبت‌شده</h3><a href="<?php echo esc_url(MDA_Integrations::orders_url()); ?>">همه سفارش‌ها ←</a></div>
     <?php if($recent): ?><ul class="mda-list"><?php foreach($recent as $r): $ch=array('phone'=>'تلفنی','in_person'=>'حضوری','woocommerce'=>'سایت')[$r['source_type']]??''; ?><li><span class="mda-list__label"><a href="<?php echo esc_url(MDA_Integrations::order_url((int)$r['order_id'])); ?>">#<?php echo esc_html((string)$r['order_id']); ?></a> <span><?php echo esc_html((string)($r['display_name']?:'—')); ?></span><span class="mda-pill"><?php echo esc_html($ch); ?></span></span><span class="mda-list__value"><?php echo esc_html(number_format_i18n((int)$r['total_minor'])); ?> <small class="mda-muted"><?php echo esc_html(MDA_Jalali::format($r['document_date'])); ?></small></span></li><?php endforeach; ?></ul>
     <?php else: ?><div class="mda-empty"><span class="dashicons dashicons-cart" aria-hidden="true"></span>هنوز فروشی در حسابداری ثبت نشده است.</div><?php endif; ?></div>
    <div class="mda-card"><div class="mda-card__head"><h3>وضعیت سامانه</h3></div><ul class="mda-list">
     <li><span class="mda-list__label">نسخه افزونه</span><span class="mda-list__value"><?php echo esc_html(MDA_VERSION); ?></span></li>
     <li><span class="mda-list__label">پایگاه داده حسابداری (SQLite)</span><span class="mda-pill <?php echo $available?'mda-pill--ok':'mda-pill--bad'; ?>"><span class="mda-dot"></span><?php echo $available?'فعال':'غیرفعال'; ?></span></li>
     <li><span class="mda-list__label">سلامت پایگاه داده</span><span class="mda-pill <?php echo 'ok'===$integrity?'mda-pill--ok':'mda-pill--warn'; ?>"><span class="mda-dot"></span><?php echo 'ok'===$integrity?'سالم':esc_html($integrity); ?></span></li>
     <li><span class="mda-list__label">انبار فروش آنلاین</span><span class="mda-list__value"><?php $wid=$available?MDA_Settings::online_warehouse_id():0;$wn='—';if($wid){foreach(MDA_Inventory_Service::warehouses() as $w){if((int)$w['id']===$wid)$wn=$w['warehouse_name'];}} echo esc_html($wn); ?></span></li>
     <li><span class="mda-list__label">واحد پول</span><span class="mda-list__value"><?php echo esc_html($cur); ?></span></li>
    </ul><?php if($error): ?><div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div><?php endif; ?></div>
   </div>
  </div>
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
   foreach((array)$raw as $line){$item=absint($line['item']??0);if($item&&function_exists('wc_get_product')){$picked=wc_get_product($item);if(!$picked)throw new InvalidArgumentException('کالای انتخاب‌شده پیدا نشد.');if($picked->is_type('variable'))throw new InvalidArgumentException('برای «'.$picked->get_name().'» تنوع مشخص (رنگ، سایز و…) را انتخاب کنید.');$line['product_id']=$picked->is_type('variation')?$picked->get_parent_id():$item;$line['variation_id']=$picked->is_type('variation')?$item:0;}
    $line['unit_cost_minor']=preg_replace('/[^0-9]/','',MDA_Jalali::latin_digits((string)($line['unit_cost_minor']??'')));
    $product=absint($line['product_id']??0);if(!$product)continue;$qty=self::parse_quantity((string)($line['quantity']??''));$unit=filter_var((string)($line['unit_cost_minor']??''),FILTER_VALIDATE_INT);if(false===$unit||$unit<0)throw new InvalidArgumentException('قیمت خرید باید عدد صحیح نامنفی به واحد خرد پول باشد.');
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
   $requestKey=sanitize_text_field(wp_unslash($_POST['request_key']??''));if(!preg_match('/^[A-Za-z0-9._:-]{16,191}$/D',$requestKey))throw new InvalidArgumentException('کلید یکتای درخواست پرداخت معتبر نیست.');
   $result=MDA_Purchase_Settlement_Service::record_payment($id,(int)$amount,absint($_POST['cash_account_id']??0),$method,'purchase-stage-payment-'.$requestKey,$date);
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
   $requestKey=sanitize_text_field(wp_unslash($_POST['request_key']??''));if(!preg_match('/^[A-Za-z0-9._:-]{16,191}$/D',$requestKey))throw new InvalidArgumentException('کلید یکتای درخواست مرجوعی معتبر نیست.');
   $result=MDA_Purchase_Return_Service::post($id,$lines,'purchase-return-'.$requestKey,$date);
   $url=add_query_arg(array('page'=>'mda-purchases','purchase_id'=>$id,'mda_notice'=>$result['duplicate']?'purchase_return_duplicate':'purchase_return_posted','return_id'=>$result['return_id']),admin_url('admin.php'));
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
  $cash=$db->query("SELECT id,account_code,account_name FROM mda_accounts WHERE account_code GLOB '11*' AND account_type='asset' AND is_control=0 AND allow_manual_entry=1 AND is_active=1 AND archived_at IS NULL ORDER BY account_code")->fetchAll();
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
  <label>انبار مقصد <select name="warehouse_id" required><?php foreach($warehouses as $w): if((int)$w['is_active']): ?><option value="<?php echo (int)$w['id']; ?>" <?php selected((int)$w['id'],MDA_Settings::online_warehouse_id()); ?>><?php echo esc_html($w['warehouse_name']); ?></option><?php endif; endforeach; ?></select></label>
  <label>تاریخ <input type="date" name="document_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" required></label></p>
  <table class="widefat striped"><thead><tr><th style="width:50%">کالا (جستجوی نام یا SKU)</th><th>تعداد</th><th>قیمت واحد خرید (ریال)</th></tr></thead><tbody>
  <?php for($i=0;$i<5;$i++): ?><tr><td><select class="wc-product-search" style="width:100%" name="lines[<?php echo $i; ?>][item]" data-placeholder="نام کالا یا SKU…" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true"></select></td><td><input type="text" dir="ltr" inputmode="decimal" placeholder="مثلاً 2.5" name="lines[<?php echo $i; ?>][quantity]"></td><td><input type="text" dir="ltr" inputmode="numeric" name="lines[<?php echo $i; ?>][unit_cost_minor]"></td></tr><?php endfor; ?>
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
  <p>مبلغ فاکتور: <strong><?php echo esc_html($fmt($summary['total_minor'])); ?> ریال</strong> | پرداخت‌شده: <?php echo esc_html($fmt($summary['paid_minor'])); ?> ریال | مرجوع‌شده: <?php echo esc_html($fmt($summary['returned_minor'])); ?> ریال | مانده خالص: <strong><?php echo esc_html($fmt($summary['payable_minor'])); ?> ریال</strong><?php if($summary['payable_minor']<0): ?> (بستانکاری تأمین‌کننده)<?php endif; ?></p>
  <h3>سوابق پرداخت</h3><table class="widefat striped"><thead><tr><th>تاریخ</th><th>مبلغ (ریال)</th><th>حساب</th><th>روش</th><th>سند حسابداری</th></tr></thead><tbody>
  <?php foreach($payments as $payment): ?><tr><td><?php echo esc_html(substr((string)$payment['paid_at'],0,10)); ?></td><td><?php echo esc_html($fmt($payment['amount_minor'])); ?></td><td><?php echo esc_html($payment['account_code'].' — '.$payment['account_name']); ?></td><td><?php echo esc_html(array('cash'=>'نقد','bank'=>'بانک','pos'=>'کارتخوان','transfer'=>'انتقال')[$payment['payment_method']]??$payment['payment_method']); ?></td><td><?php echo esc_html($payment['accounting_document_number']??'—'); ?></td></tr><?php endforeach; ?>
  <?php if(!$payments): ?><tr><td colspan="5">پرداختی ثبت نشده است.</td></tr><?php endif; ?></tbody></table>
  <?php if($summary['payable_minor']>0): ?><h3>ثبت پرداخت جدید</h3><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_record_purchase_payment"><input type="hidden" name="purchase_id" value="<?php echo (int)$posted['id']; ?>"><?php wp_nonce_field('mda_record_purchase_payment_'.(int)$posted['id']); ?><input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
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
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_post_purchase_return"><input type="hidden" name="purchase_id" value="<?php echo (int)$posted['id']; ?>"><?php wp_nonce_field('mda_post_purchase_return_'.(int)$posted['id']); ?><input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
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

 public static function record_sales_receipt(): void {
  self::guard();$id=absint($_POST['sale_id']??0);check_admin_referer('mda_record_sales_receipt_'.$id);
  try{$amount=filter_var((string)($_POST['amount_minor']??''),FILTER_VALIDATE_INT);if(false===$amount||$amount<1)throw new InvalidArgumentException('مبلغ دریافت باید عدد صحیح مثبت باشد.');
   $date=sanitize_text_field(wp_unslash($_POST['payment_date']??''));$method=sanitize_key(wp_unslash($_POST['payment_method']??'cash'));$requestKey=sanitize_text_field(wp_unslash($_POST['request_key']??''));if(strlen($requestKey)<16||strlen($requestKey)>191)throw new InvalidArgumentException('کلید یکتای درخواست معتبر نیست.');
   $result=MDA_Sales_Receipt_Service::record($id,(int)$amount,absint($_POST['cash_account_id']??0),$method,'sales-receipt-'.$requestKey,$date);
   $url=add_query_arg(array('page'=>'mda-sales-receipts','sale_id'=>$id,'mda_notice'=>$result['duplicate']?'sales_receipt_duplicate':'sales_receipt_recorded'),admin_url('admin.php'));
  }catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-sales-receipts','sale_id'=>$id,'mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));}
  wp_safe_redirect($url);exit;
 }
 public static function render_sales_receipts(): void {
  self::guard();if(!MDA_Database::is_available()){echo '<div class="wrap"><div class="notice notice-error"><p>PDO_SQLite فعال نیست.</p></div></div>';return;}
  $db=MDA_Database::connection();$rows=MDA_Sales_Receipt_Service::all(200);$saleId=absint($_GET['sale_id']??0);$sale=null;$summary=null;$receipts=array();
  if($saleId){$q=$db->prepare('SELECT s.*,p.display_name,p.phone FROM mda_sales_documents s JOIN mda_parties p ON p.id=s.party_id WHERE s.id=:id');$q->execute(array(':id'=>$saleId));$sale=$q->fetch();if($sale){$summary=MDA_Sales_Receipt_Service::summary($saleId);$receipts=MDA_Sales_Receipt_Service::receipts($saleId);}}
  $cash=$db->query("SELECT id,account_code,account_name FROM mda_accounts WHERE account_code GLOB '11*' AND account_type='asset' AND is_control=0 AND allow_manual_entry=1 AND is_active=1 AND archived_at IS NULL ORDER BY account_code")->fetchAll();$fmt=static function($v):string{return number_format((int)$v,0,'.',',');};
  ?>
  <div class="wrap" dir="rtl"><h1>دریافت از مشتری</h1><p>ثبت فروش به معنی وصول وجه نیست. دریافت‌ها به‌صورت سند مستقل و تغییرناپذیر ثبت می‌شوند؛ تسویه درگاه تا زمان اتصال و تطبیق خودکار، در این فرم ثبت نمی‌شود.</p><?php self::notices(); ?>
  <?php if($sale&&$summary): ?><div style="background:#fff;padding:18px;max-width:1100px;margin:16px 0"><h2>فروش <?php echo esc_html($sale['document_number']); ?> — سفارش #<?php echo (int)$sale['order_id']; ?></h2>
  <p>مشتری: <?php echo esc_html($sale['display_name']); ?> | مبلغ فروش: <strong><?php echo esc_html($fmt($summary['total_minor'])); ?> <?php echo esc_html($sale['currency_code']); ?></strong> | دریافت‌شده: <?php echo esc_html($fmt($summary['received_minor'])); ?> | مانده: <strong><?php echo esc_html($fmt($summary['receivable_minor'])); ?></strong></p>
  <?php if($summary['receivable_minor']>0): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_record_sales_receipt"><input type="hidden" name="sale_id" value="<?php echo (int)$saleId; ?>"><?php wp_nonce_field('mda_record_sales_receipt_'.$saleId); ?><input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
  <p><label>مبلغ دریافت (<?php echo esc_html($sale['currency_code']); ?>) <input required type="number" min="1" max="<?php echo (int)$summary['receivable_minor']; ?>" name="amount_minor"></label>
  <label>صندوق/بانک <select name="cash_account_id" required><option value="">انتخاب حساب</option><?php foreach($cash as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo esc_html($a['account_code'].' — '.$a['account_name']); ?></option><?php endforeach; ?></select></label>
  <label>روش دریافت <select name="payment_method"><option value="cash">نقد</option><option value="bank">بانک</option><option value="pos">کارتخوان</option><option value="transfer">انتقال</option></select></label>
  <label>تاریخ دریافت <input type="date" required name="payment_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></label></p><button class="button button-primary">ثبت دریافت و سند حسابداری</button></form><?php else: ?><p>این فاکتور تسویه شده است.</p><?php endif; ?>
  <h3>سوابق دریافت</h3><table class="widefat striped"><thead><tr><th>تاریخ</th><th>مبلغ</th><th>حساب</th><th>روش</th><th>سند</th></tr></thead><tbody><?php foreach($receipts as $r): ?><tr><td><?php echo esc_html($r['payment_date']); ?></td><td><?php echo esc_html($fmt($r['amount_minor'])); ?></td><td><?php echo esc_html($r['account_code'].' — '.$r['account_name']); ?></td><td><?php echo esc_html(array('cash'=>'نقد','bank'=>'بانک','pos'=>'کارتخوان','transfer'=>'انتقال','gateway'=>'درگاه')[$r['payment_method']]??$r['payment_method']); ?></td><td><?php echo esc_html($r['accounting_document_number']); ?></td></tr><?php endforeach; ?><?php if(!$receipts): ?><tr><td colspan="5">دریافتی ثبت نشده است.</td></tr><?php endif; ?></tbody></table></div><?php endif; ?>
  <h2>فروش‌ها و مانده دریافتنی</h2><table class="widefat striped"><thead><tr><th>سند فروش</th><th>سفارش</th><th>مشتری</th><th>تاریخ</th><th>مبلغ</th><th>دریافت</th><th>مانده</th><th>عملیات</th></tr></thead><tbody>
  <?php foreach($rows as $r): $balance=(int)$r['total_minor']-(int)$r['received_minor']; ?><tr><td><?php echo esc_html($r['document_number']); ?></td><td>#<?php echo (int)$r['order_id']; ?></td><td><?php echo esc_html($r['display_name']); ?></td><td><?php echo esc_html($r['document_date']); ?></td><td><?php echo esc_html($fmt($r['total_minor']).' '.$r['currency_code']); ?></td><td><?php echo esc_html($fmt($r['received_minor'])); ?></td><td><strong><?php echo esc_html($fmt($balance)); ?></strong></td><td><a href="<?php echo esc_url(add_query_arg(array('page'=>'mda-sales-receipts','sale_id'=>(int)$r['id']),admin_url('admin.php'))); ?>">جزئیات / ثبت دریافت</a></td></tr><?php endforeach; ?>
  <?php if(!$rows): ?><tr><td colspan="8">فروشی ثبت نشده است.</td></tr><?php endif; ?></tbody></table></div>
  <?php
 }
 public static function post_sales_return(): void {
  self::guard();$saleId=absint($_POST['sale_id']??0);check_admin_referer('mda_post_sales_return_'.$saleId);
  try{$raw=(array)wp_unslash($_POST['return_lines']??array());$lines=array();foreach($raw as $lineId=>$row){$qty=trim((string)($row['quantity']??''));if($qty==='')continue;$lines[]=array('sales_line_id'=>absint($lineId),'quantity_scaled'=>self::parse_quantity($qty));}
   if(!$lines)throw new InvalidArgumentException('مقدار حداقل یک کالا را برای مرجوعی وارد کنید.');
   $requestKey=sanitize_text_field(wp_unslash($_POST['request_key']??''));if(strlen($requestKey)<16||strlen($requestKey)>160)throw new InvalidArgumentException('کلید یکتای درخواست معتبر نیست.');
   $date=sanitize_text_field(wp_unslash($_POST['return_date']??''));$result=MDA_Sales_Return_Service::post($saleId,$lines,'sales-return-'.$requestKey,$date);
   $url=add_query_arg(array('page'=>'mda-sales-returns','sale_id'=>$saleId,'return_id'=>$result['return_id'],'mda_notice'=>$result['duplicate']?'sales_return_duplicate':'sales_return_posted'),admin_url('admin.php'));
  }catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-sales-returns','sale_id'=>$saleId,'mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));}
  wp_safe_redirect($url);exit;
 }
 public static function record_sales_refund(): void {
  self::guard();$returnId=absint($_POST['return_id']??0);check_admin_referer('mda_record_sales_refund_'.$returnId);
  try{$amount=filter_var((string)($_POST['amount_minor']??''),FILTER_VALIDATE_INT);if(false===$amount||$amount<1)throw new InvalidArgumentException('مبلغ استرداد باید عدد صحیح مثبت باشد.');
   $requestKey=sanitize_text_field(wp_unslash($_POST['request_key']??''));if(strlen($requestKey)<16||strlen($requestKey)>160)throw new InvalidArgumentException('کلید یکتای درخواست معتبر نیست.');
   $date=sanitize_text_field(wp_unslash($_POST['payment_date']??''));$method=sanitize_key(wp_unslash($_POST['payment_method']??'bank'));
   $result=MDA_Sales_Refund_Service::record($returnId,(int)$amount,absint($_POST['cash_account_id']??0),$method,'sales-refund-'.$requestKey,$date);
   $return=MDA_Sales_Return_Service::return_record($returnId);$url=add_query_arg(array('page'=>'mda-sales-returns','sale_id'=>(int)$return['sales_document_id'],'return_id'=>$returnId,'mda_notice'=>$result['duplicate']?'sales_refund_duplicate':'sales_refund_posted'),admin_url('admin.php'));
  }catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-sales-returns','return_id'=>$returnId,'mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));}
  wp_safe_redirect($url);exit;
 }
 public static function render_sales_returns(): void {
  self::guard();if(!MDA_Database::is_available()){echo '<div class="wrap" dir="rtl"><h1>مرجوعی فروش و استرداد</h1><div class="notice notice-error"><p>PDO_SQLite فعال نیست.</p></div></div>';return;}
  $saleId=absint($_GET['sale_id']??0);$returnId=absint($_GET['return_id']??0);$sale=$saleId?MDA_Sales_Return_Service::sale($saleId):null;$lines=$sale?MDA_Sales_Return_Service::sale_lines($saleId):array();$returns=$sale?MDA_Sales_Return_Service::returns($saleId):array();
  $return=$returnId?MDA_Sales_Return_Service::return_record($returnId):null;$refundSummary=$return?MDA_Sales_Refund_Service::summary($returnId):null;$refunds=$return?MDA_Sales_Refund_Service::refunds($returnId):array();
  $cash=MDA_Database::connection()->query("SELECT id,account_code,account_name FROM mda_accounts WHERE account_code GLOB '11*' AND account_type='asset' AND is_control=0 AND allow_manual_entry=1 AND is_active=1 AND archived_at IS NULL ORDER BY account_code")->fetchAll();
  $sales=MDA_Sales_Return_Service::sales(200);$fmt=static function($v):string{return number_format((int)$v,0,'.',',');};
  ?>
  <div class="wrap" dir="rtl"><h1>مرجوعی فروش و استرداد وجه</h1><p>مرجوعی کالا با بهای تمام‌شده snapshot فروش به موجودی برمی‌گردد. استرداد نقدی/بانکی فقط از محل بستانکاری واقعی مشتری انجام می‌شود؛ اتصال خودکار به درگاه در این مرحله وجود ندارد.</p><?php self::notices(); ?>
  <?php if($sale): ?><div class="mda-card"><h2>فروش <?php echo esc_html($sale['document_number']); ?> — سفارش #<?php echo (int)$sale['order_id']; ?></h2>
   <p>مشتری: <strong><?php echo esc_html($sale['display_name']); ?></strong> | مبلغ فروش: <strong><?php echo esc_html($fmt($sale['total_minor']).' '.$sale['currency_code']); ?></strong> | مالیات ردیفی قابل برگشت: <?php echo esc_html($fmt(array_sum(array_map(static fn($l)=>(int)$l['tax_minor'],$lines)))); ?></p>
   <h3 class="mda-section-title">ثبت مرجوعی کالا</h3>
   <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_post_sales_return"><input type="hidden" name="sale_id" value="<?php echo (int)$saleId; ?>"><?php wp_nonce_field('mda_post_sales_return_'.$saleId); ?><input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
   <div class="mda-table-wrap"><table class="widefat striped"><thead><tr><th>کالا</th><th>تعداد فروش</th><th>مرجوع‌شده</th><th>قابل مرجوعی</th><th>تعداد مرجوعی جدید</th></tr></thead><tbody>
   <?php foreach($lines as $line): $remaining=(int)$line['quantity_scaled']-(int)$line['returned_quantity']; ?><tr><td><?php echo esc_html($line['product_name_snapshot']); ?> <small><?php echo esc_html($line['sku_snapshot']??''); ?></small></td><td><?php echo esc_html(number_format((int)$line['quantity_scaled']/(int)$line['quantity_scale'],6)); ?></td><td><?php echo esc_html(number_format((int)$line['returned_quantity']/(int)$line['quantity_scale'],6)); ?></td><td><?php echo esc_html(number_format($remaining/(int)$line['quantity_scale'],6)); ?></td><td><?php if($remaining>0): ?><input type="text" name="return_lines[<?php echo (int)$line['id']; ?>][quantity]" placeholder="خالی = بدون مرجوعی"><?php else: ?><span class="mda-chip">تکمیل‌شده</span><?php endif; ?></td></tr><?php endforeach; ?>
   </tbody></table></div>
   <p><label>تاریخ مرجوعی <input type="date" name="return_date" required value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></label> <button class="button button-primary" type="submit">ثبت مرجوعی و سند اصلاحی</button></p>
   </form>
   <h3 class="mda-section-title">سوابق مرجوعی</h3><table class="widefat striped"><thead><tr><th>شماره مرجوعی</th><th>تاریخ</th><th>مبلغ کالا</th><th>مالیات</th><th>مجموع</th><th>COGS برگشتی</th><th>استردادشده</th><th>جزئیات</th></tr></thead><tbody>
   <?php foreach($returns as $r): ?><tr><td><?php echo esc_html($r['return_number']); ?></td><td><?php echo esc_html($r['document_date']); ?></td><td><?php echo esc_html($fmt($r['product_total_minor'])); ?></td><td><?php echo esc_html($fmt($r['tax_total_minor'])); ?></td><td><?php echo esc_html($fmt($r['total_minor'])); ?></td><td><?php echo esc_html($fmt($r['cogs_reversed_minor'])); ?></td><td><?php echo esc_html($fmt($r['refunded_minor'])); ?></td><td><a href="<?php echo esc_url(add_query_arg(array('page'=>'mda-sales-returns','sale_id'=>$saleId,'return_id'=>(int)$r['id']),admin_url('admin.php'))); ?>">مدیریت استرداد</a></td></tr><?php endforeach; ?>
   <?php if(!$returns): ?><tr><td colspan="8">هنوز مرجوعی ثبت نشده است.</td></tr><?php endif; ?></tbody></table></div>
  <?php endif; ?>
  <?php if($return&&$refundSummary): ?><div class="mda-card" style="margin-top:18px"><h2>استرداد بابت <?php echo esc_html($return['return_number']); ?></h2><p>مشتری: <?php echo esc_html($return['display_name']); ?> | مبلغ مرجوعی: <?php echo esc_html($fmt($return['total_minor'])); ?> | استرداد قبلی: <?php echo esc_html($fmt($refundSummary['refunded_minor'])); ?> | مانده سند مرجوعی: <strong><?php echo esc_html($fmt($refundSummary['remaining_minor'])); ?></strong> | بستانکاری قابل استرداد مشتری: <?php echo esc_html($fmt($refundSummary['customer_credit_minor'])); ?></p>
   <?php if($refundSummary['available_minor']>0): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_record_sales_refund"><input type="hidden" name="return_id" value="<?php echo (int)$returnId; ?>"><?php wp_nonce_field('mda_record_sales_refund_'.$returnId); ?><input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
   <p><label>مبلغ استرداد (<?php echo esc_html($return['currency_code']); ?>) <input type="number" min="1" max="<?php echo (int)$refundSummary['available_minor']; ?>" name="amount_minor" required></label>
   <label>صندوق/بانک <select name="cash_account_id" required><option value="">انتخاب حساب</option><?php foreach($cash as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo esc_html($a['account_code'].' — '.$a['account_name']); ?></option><?php endforeach; ?></select></label>
   <label>روش استرداد <select name="payment_method"><option value="bank">بانک</option><option value="cash">نقد</option><option value="pos">کارتخوان</option><option value="transfer">انتقال</option></select></label>
   <label>تاریخ <input type="date" name="payment_date" required value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></label></p><button class="button button-primary">ثبت استرداد وجه</button></form>
   <?php else: ?><p>در حال حاضر بستانکاری قابل استرداد وجود ندارد یا این مرجوعی به‌طور کامل تسویه شده است.</p><?php endif; ?>
   <h3 class="mda-section-title">سوابق استرداد وجه</h3><table class="widefat striped"><thead><tr><th>تاریخ</th><th>مبلغ</th><th>روش</th><th>صندوق/بانک</th><th>سند حسابداری</th></tr></thead><tbody><?php foreach($refunds as $r): ?><tr><td><?php echo esc_html($r['payment_date']); ?></td><td><?php echo esc_html($fmt($r['amount_minor'])); ?></td><td><?php echo esc_html($r['payment_method']); ?></td><td><?php echo esc_html($r['account_code'].' — '.$r['account_name']); ?></td><td><?php echo esc_html($r['accounting_document_number']); ?></td></tr><?php endforeach; ?><?php if(!$refunds): ?><tr><td colspan="5">استردادی ثبت نشده است.</td></tr><?php endif; ?></tbody></table></div><?php endif; ?>
  <h2 class="mda-section-title">فروش‌های قابل بررسی</h2><table class="widefat striped"><thead><tr><th>سند فروش</th><th>سفارش</th><th>مشتری</th><th>تاریخ</th><th>مبلغ</th><th>مرجوعی</th><th>عملیات</th></tr></thead><tbody><?php foreach($sales as $row): ?><tr><td><?php echo esc_html($row['document_number']); ?></td><td>#<?php echo (int)$row['order_id']; ?></td><td><?php echo esc_html($row['display_name']); ?></td><td><?php echo esc_html($row['document_date']); ?></td><td><?php echo esc_html($fmt($row['total_minor'])); ?></td><td><?php echo esc_html($fmt($row['returned_minor'])); ?></td><td><a href="<?php echo esc_url(add_query_arg(array('page'=>'mda-sales-returns','sale_id'=>(int)$row['id']),admin_url('admin.php'))); ?>">انتخاب فروش</a></td></tr><?php endforeach; ?><?php if(!$sales): ?><tr><td colspan="7">فروشی ثبت نشده است.</td></tr><?php endif; ?></tbody></table></div>
  <?php
 }
 public static function run_woocommerce_reconciliation(): void {
  self::guard();check_admin_referer('mda_run_woocommerce_reconciliation');
  try{$result=MDA_Reconciliation_Service::run_batch(100);$url=add_query_arg(array('page'=>'mda-reconciliation','mda_notice'=>'reconciliation_run','queued'=>$result['queued'],'scanned'=>$result['scanned'],'pending'=>$result['pending'],'failed'=>$result['failed'],'skipped'=>$result['skipped']),admin_url('admin.php'));}
  catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-reconciliation','mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));}
  wp_safe_redirect($url);exit;
 }
 public static function render_reconciliation(): void {
  self::guard();if(!MDA_Database::is_available()){echo '<div class="wrap" dir="rtl"><h1>تطبیق سفارش‌های ووکامرس</h1><div class="notice notice-error"><p>PDO_SQLite فعال نیست.</p></div></div>';return;}
  $state=MDA_Reconciliation_Service::state();$runs=MDA_Reconciliation_Service::runs(30);$latest=$runs[0]??null;
  ?>
  <div class="wrap" dir="rtl"><h1>تطبیق سفارش‌های ووکامرس</h1><p>این ابزار سفارش‌های ۹۰ روز اخیر با وضعیت در حال انجام/تکمیل‌شده را با اسناد و صف SQLite تطبیق می‌دهد. سفارش‌های موجود دوباره ثبت نمی‌شوند و رویدادهای ناموفق بدون بررسی دستی بازنویسی نمی‌شوند.</p><?php self::notices(); ?>
  <?php if(!class_exists('WooCommerce')): ?><div class="notice notice-error"><p>برای اجرای تطبیق، WooCommerce باید فعال باشد.</p></div><?php else: ?>
  <div class="mda-card" style="margin:16px 0"><div class="mda-page-toolbar"><div><h2 style="margin:0">اجرای تطبیق</h2><p>بازه بررسی: ۹۰ روز اخیر | اندازه هر دسته: ۱۰۰ سفارش | صفحه بعدی: <?php echo (int)$state['next_page']; ?></p></div>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_run_woocommerce_reconciliation"><?php wp_nonce_field('mda_run_woocommerce_reconciliation'); ?><button class="button button-primary" type="submit">اجرای دسته بعدی</button></form></div>
  <div class="mda-grid"><div class="mda-card mda-stat"><span class="mda-stat-icon">🧭</span><div><small>آخرین صفحه بررسی‌شده</small><strong><?php echo (int)($latest['page_number']??0); ?></strong></div></div><div class="mda-card mda-stat"><span class="mda-stat-icon">📨</span><div><small>آخرین رویدادهای صف‌شده</small><strong><?php echo (int)($latest['queued_count']??0); ?></strong></div></div><div class="mda-card mda-stat"><span class="mda-stat-icon">⚠️</span><div><small>موارد نیازمند بررسی</small><strong><?php echo (int)($latest['failed_count']??0)+(int)($latest['skipped_count']??0); ?></strong></div></div></div></div>
  <?php endif; ?>
  <h2 class="mda-section-title">تاریخچه تطبیق</h2><table class="widefat striped"><thead><tr><th>زمان</th><th>صفحه</th><th>بررسی‌شده</th><th>ثبت‌شده</th><th>صف‌شده</th><th>در انتظار</th><th>ناموفق</th><th>ردشده</th><th>وضعیت / جزئیات</th></tr></thead><tbody>
  <?php foreach($runs as $run): $errors=json_decode((string)$run['errors_json'],true); ?><tr><td><?php echo esc_html($run['created_at']); ?></td><td><?php echo (int)$run['page_number'].' / '.(int)$run['total_pages']; ?></td><td><?php echo (int)$run['scanned_count']; ?></td><td><?php echo (int)$run['already_posted_count']; ?></td><td><?php echo (int)$run['queued_count']; ?></td><td><?php echo (int)$run['pending_count']; ?></td><td><?php echo (int)$run['failed_count']; ?></td><td><?php echo (int)$run['skipped_count']; ?></td><td><span class="mda-chip"><?php echo esc_html($run['status']); ?></span><?php if(is_array($errors)&&$errors): ?><details><summary>مشاهده هشدارها (<?php echo count($errors); ?>)</summary><ul><?php foreach($errors as $error): ?><li>سفارش #<?php echo (int)($error['order_id']??0); ?>: <?php echo esc_html($error['reason']??''); ?></li><?php endforeach; ?></ul></details><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if(!$runs): ?><tr><td colspan="9">هنوز تطبیقی اجرا نشده است.</td></tr><?php endif; ?></tbody></table></div>
  <?php
 }
 public static function run_woocommerce_stock_sync(): void {
  self::guard();check_admin_referer('mda_run_woocommerce_stock_sync');
  $warehouseId=MDA_Settings::online_warehouse_id();
  try{$result=MDA_Stock_Sync_Service::run_batch($warehouseId,100);$url=add_query_arg(array('page'=>'mda-stock-sync','warehouse_id'=>$warehouseId,'run_id'=>$result['run_id'],'mda_notice'=>'stock_sync_run'),admin_url('admin.php'));}
  catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-stock-sync','warehouse_id'=>$warehouseId,'mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));}
  wp_safe_redirect($url);exit;
 }
 public static function render_stock_sync(): void {
  self::guard();if(!MDA_Database::is_available()){echo '<div class="wrap" dir="rtl"><h1>همگام‌سازی موجودی</h1><div class="notice notice-error"><p>PDO_SQLite فعال نیست.</p></div></div>';return;}
  $onlineId=MDA_Settings::online_warehouse_id();$warehouses=array_values(array_filter(MDA_Stock_Sync_Service::warehouses(),static fn($w)=>(int)$w['is_active']===1&&(int)$w['id']===$onlineId));$warehouseId=$onlineId;
  $runs=MDA_Stock_Sync_Service::runs(30);$runId=absint($_GET['run_id']??0);$items=$runId?MDA_Stock_Sync_Service::items($runId):array();
  $pending=(int)MDA_Database::connection()->query("SELECT COUNT(*) FROM mda_integration_outbox WHERE status IN ('pending','processing','failed')")->fetchColumn();
  ?>
  <div class="wrap" dir="rtl"><h1>همگام‌سازی موجودی ووکامرس</h1><p>جهت همگام‌سازی فقط از SQLite به WooCommerce است. موجودی حسابداری مرجع است؛ این ابزار از WooCommerce CRUD استفاده می‌کند و هیچ SQL مستقیمی روی جداول ووکامرس اجرا نمی‌کند.</p><?php self::notices(); ?>
  <div class="mda-card" style="margin:16px 0"><h2>پیش از اجرا</h2><ul><li>ابتدا تطبیق سفارش‌های ووکامرس را اجرا کنید.</li><li>رویدادهای صف فروش باید تعیین تکلیف شده باشند؛ در غیر این صورت عملیات به‌صورت خودکار متوقف می‌شود.</li><li>فقط یک انبار به موجودی واحد WooCommerce نگاشت می‌شود؛ انبارهای دیگر به‌صورت مستقل در SQLite باقی می‌مانند.</li><li>محصولاتی که مدیریت موجودی ندارند یا تنوع آن‌ها موجودی را از والد به ارث می‌برد، رد می‌شوند.</li></ul>
   <p><strong>رویدادهای باز/ناموفق صف:</strong> <?php echo (int)$pending; ?></p>
   <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_run_woocommerce_stock_sync"><?php wp_nonce_field('mda_run_woocommerce_stock_sync'); ?>
   <label>انبار مرجع <select name="warehouse_id" required><?php foreach($warehouses as $w): ?><option value="<?php echo (int)$w['id']; ?>" <?php selected($warehouseId,(int)$w['id']); ?>><?php echo esc_html($w['warehouse_name'].' ('.$w['warehouse_code'].')'); ?></option><?php endforeach; ?></select></label>
   <button class="button button-primary" type="submit" <?php disabled(!$warehouses); ?>>اجرای دسته همگام‌سازی (حداکثر ۱۰۰ کالا)</button></form></div>
  <?php if($runId): ?><div class="mda-card"><h2>جزئیات اجرای #<?php echo (int)$runId; ?></h2><table class="widefat striped"><thead><tr><th>محصول</th><th>تنوع</th><th>موجودی SQLite</th><th>موجودی قبلی Woo</th><th>موجودی جدید Woo</th><th>وضعیت</th><th>شرح</th></tr></thead><tbody><?php foreach($items as $item): ?><tr><td><?php echo (int)$item['product_id']; ?></td><td><?php echo (int)$item['variation_id']; ?></td><td><?php echo esc_html($item['sqlite_quantity']); ?></td><td><?php echo esc_html($item['woo_quantity_before']??'—'); ?></td><td><?php echo esc_html($item['woo_quantity_after']??'—'); ?></td><td><?php echo esc_html($item['status']); ?></td><td><?php echo esc_html($item['message']); ?></td></tr><?php endforeach; if(!$items): ?><tr><td colspan="7">برای این اجرا جزئیات محصولی ثبت نشده است.</td></tr><?php endif; ?></tbody></table></div><?php endif; ?>
  <h2 class="mda-section-title">تاریخچه همگام‌سازی</h2><table class="widefat striped"><thead><tr><th>زمان</th><th>انبار</th><th>بررسی‌شده</th><th>همگام‌شده</th><th>ردشده</th><th>ناموفق</th><th>صف باز</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody><?php foreach($runs as $run): ?><tr><td><?php echo esc_html($run['created_at']); ?></td><td><?php echo esc_html($run['warehouse_name']); ?></td><td><?php echo (int)$run['scanned_count']; ?></td><td><?php echo (int)$run['synced_count']; ?></td><td><?php echo (int)$run['skipped_count']; ?></td><td><?php echo (int)$run['failed_count']; ?></td><td><?php echo (int)$run['pending_events_count']; ?></td><td><span class="mda-chip"><?php echo esc_html($run['status']); ?></span><br><?php echo esc_html($run['message']); ?></td><td><a href="<?php echo esc_url(add_query_arg(array('page'=>'mda-stock-sync','warehouse_id'=>(int)$run['warehouse_id'],'run_id'=>(int)$run['id']),admin_url('admin.php'))); ?>">نمایش</a></td></tr><?php endforeach; if(!$runs): ?><tr><td colspan="9">هنوز همگام‌سازی اجرا نشده است.</td></tr><?php endif; ?></tbody></table></div>
  <?php
 }
 public static function render_reports(): void {
  self::guard();if(!MDA_Database::is_available()){echo '<div class="wrap" dir="rtl"><h1>گزارش‌های مالی</h1><div class="notice notice-error"><p>PDO_SQLite فعال نیست.</p></div></div>';return;}
  $to=sanitize_text_field(wp_unslash($_GET['to']??current_time('Y-m-d')));$from=sanitize_text_field(wp_unslash($_GET['from']??(substr($to,0,7).'-01')));$warehouseId=absint($_GET['warehouse_id']??0);$warehouses=MDA_Inventory_Service::warehouses();
  $pl=array('rows'=>array(),'totals'=>array());$orders=array();$products=array();$channels=array();$inventory=array('rows'=>array(),'totals'=>array());$error='';
  try{$pl=MDA_Report_Service::profit_and_loss($from,$to);$orders=MDA_Report_Service::order_profitability($from,$to,100);$products=MDA_Report_Service::product_profitability($from,$to,100);$channels=MDA_Report_Service::channel_profitability($from,$to);$inventory=MDA_Report_Service::inventory_valuation($warehouseId);}catch(Throwable $e){$error=$e->getMessage();}
  $fmt=static function($v):string{return number_format((int)$v,0,'.',',');};$sourceLabel=static function($v):string{return array('woocommerce'=>'سایت','phone'=>'تلفنی','in_person'=>'حضوری')[$v]??$v;};
  ?>
  <div class="wrap" dir="rtl"><h1>گزارش‌های مالی و سودآوری</h1><p>گزارش‌ها از اسناد قطعی، دفتر دوبل، مرجوعی‌ها و snapshot بهای تمام‌شده در SQLite محاسبه می‌شوند. مالیات از درآمد خالص کالا جدا نگه داشته می‌شود.</p>
  <div class="mda-card" style="margin:16px 0"><form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"><input type="hidden" name="page" value="mda-reports">
   <label>از تاریخ <input type="date" name="from" value="<?php echo esc_attr($from); ?>" required></label>
   <label>تا تاریخ <input type="date" name="to" value="<?php echo esc_attr($to); ?>" required></label>
   <label>انبار ارزش‌گذاری <select name="warehouse_id"><option value="0" <?php selected($warehouseId,0); ?>>همه انبارها</option><?php foreach($warehouses as $w): ?><option value="<?php echo (int)$w['id']; ?>" <?php selected($warehouseId,(int)$w['id']); ?>><?php echo esc_html($w['warehouse_name']); ?></option><?php endforeach; ?></select></label>
   <button class="button button-primary" type="submit">به‌روزرسانی گزارش</button></form></div>
  <?php if($error): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php else: ?>
  <h2 class="mda-section-title">خلاصه سود و زیان · <?php echo esc_html($from.' تا '.$to); ?></h2>
  <div class="mda-grid"><?php foreach($pl['totals'] as $currency=>$total): ?><div class="mda-card mda-stat"><span class="mda-stat-icon">📈</span><div><small>سود/زیان خالص (<?php echo esc_html($currency); ?>)</small><strong><?php echo esc_html($fmt($total['net_profit_minor'])); ?></strong><small>درآمد: <?php echo esc_html($fmt($total['income_minor'])); ?> | هزینه: <?php echo esc_html($fmt($total['expense_minor'])); ?></small></div></div><?php endforeach; ?><?php if(!$pl['totals']): ?><div class="mda-card"><p>برای این بازه سند درآمد یا هزینه قطعی وجود ندارد.</p></div><?php endif; ?></div>
  <h2 class="mda-section-title">جزئیات سود و زیان</h2><table class="widefat striped"><thead><tr><th>کد حساب</th><th>عنوان حساب</th><th>نوع</th><th>ارز</th><th>بدهکار</th><th>بستانکار</th><th>مانده مؤثر</th></tr></thead><tbody><?php foreach($pl['rows'] as $row): ?><tr><td><?php echo esc_html($row['account_code']); ?></td><td><?php echo esc_html($row['account_name']); ?></td><td><?php echo esc_html($row['account_type']==='income'?'درآمد':'هزینه'); ?></td><td><?php echo esc_html($row['currency_code']); ?></td><td><?php echo esc_html($fmt($row['debit_minor'])); ?></td><td><?php echo esc_html($fmt($row['credit_minor'])); ?></td><td><strong><?php echo esc_html($fmt($row['amount_minor'])); ?></strong></td></tr><?php endforeach; ?><?php if(!$pl['rows']): ?><tr><td colspan="7">داده‌ای وجود ندارد.</td></tr><?php endif; ?></tbody></table>
  <h2 class="mda-section-title">سودآوری کانال فروش</h2><table class="widefat striped"><thead><tr><th>کانال</th><th>تعداد سفارش</th><th>ارز</th><th>فروش خالص کالا</th><th>ارسال و کارمزد</th><th>بهای تمام‌شده خالص</th><th>سود ناخالص کالا</th></tr></thead><tbody><?php foreach($channels as $row): ?><tr><td><?php echo esc_html($sourceLabel($row['source_type'])); ?></td><td><?php echo (int)$row['order_count']; ?></td><td><?php echo esc_html($row['currency_code']); ?></td><td><?php echo esc_html($fmt($row['net_product_minor'])); ?></td><td><?php echo esc_html($fmt((int)$row['shipping_minor']+(int)$row['fees_minor'])); ?></td><td><?php echo esc_html($fmt($row['net_cogs_minor'])); ?></td><td><strong><?php echo esc_html($fmt($row['gross_profit_minor'])); ?></strong></td></tr><?php endforeach; ?><?php if(!$channels): ?><tr><td colspan="7">فروشی در این بازه وجود ندارد.</td></tr><?php endif; ?></tbody></table>
  <h2 class="mda-section-title">سودآوری محصولات</h2><table class="widefat striped"><thead><tr><th>محصول</th><th>SKU</th><th>ارز</th><th>تعداد خالص</th><th>فروش خالص</th><th>بهای تمام‌شده</th><th>سود ناخالص</th></tr></thead><tbody><?php foreach($products as $row): ?><tr><td><?php echo esc_html($row['product_name_snapshot']); ?> <small>#<?php echo (int)$row['product_id']; ?><?php if((int)$row['variation_id']): ?> / <?php echo (int)$row['variation_id']; ?><?php endif; ?></small></td><td><?php echo esc_html($row['sku_snapshot']??''); ?></td><td><?php echo esc_html($row['currency_code']); ?></td><td><?php echo esc_html(number_format((int)$row['net_quantity_scaled']/(int)$row['quantity_scale'],4)); ?></td><td><?php echo esc_html($fmt($row['net_revenue_minor'])); ?></td><td><?php echo esc_html($fmt($row['net_cogs_minor'])); ?></td><td><strong><?php echo esc_html($fmt($row['gross_profit_minor'])); ?></strong></td></tr><?php endforeach; ?><?php if(!$products): ?><tr><td colspan="7">داده‌ای وجود ندارد.</td></tr><?php endif; ?></tbody></table>
  <h2 class="mda-section-title">سود هر سفارش</h2><table class="widefat striped"><thead><tr><th>سند</th><th>سفارش</th><th>تاریخ</th><th>کانال</th><th>ارز</th><th>فروش خالص کالا</th><th>COGS خالص</th><th>سود ناخالص</th></tr></thead><tbody><?php foreach($orders as $row): ?><tr><td><?php echo esc_html($row['document_number']); ?></td><td>#<?php echo (int)$row['order_id']; ?></td><td><?php echo esc_html($row['document_date']); ?></td><td><?php echo esc_html($sourceLabel($row['source_type'])); ?></td><td><?php echo esc_html($row['currency_code']); ?></td><td><?php echo esc_html($fmt($row['net_product_minor'])); ?></td><td><?php echo esc_html($fmt($row['net_cogs_minor'])); ?></td><td><strong><?php echo esc_html($fmt($row['gross_profit_minor'])); ?></strong></td></tr><?php endforeach; ?><?php if(!$orders): ?><tr><td colspan="8">سفروشی در این بازه وجود ندارد.</td></tr><?php endif; ?></tbody></table>
  <h2 class="mda-section-title">ارزش موجودی انبار</h2><div class="mda-grid"><?php foreach($inventory['totals'] as $currency=>$total): ?><div class="mda-card mda-stat"><span class="mda-stat-icon">📦</span><div><small>ارزش موجودی (<?php echo esc_html($currency); ?>)</small><strong><?php echo esc_html($fmt($total)); ?></strong></div></div><?php endforeach; ?></div>
  <table class="widefat striped"><thead><tr><th>انبار</th><th>محصول</th><th>تنوع</th><th>مقدار</th><th>میانگین بهای واحد</th><th>ارز</th><th>ارزش موجودی</th></tr></thead><tbody><?php foreach($inventory['rows'] as $row): ?><tr><td><?php echo esc_html($row['warehouse_name']); ?></td><td><?php echo (int)$row['product_id']; ?></td><td><?php echo (int)$row['variation_id']; ?></td><td><?php echo esc_html(number_format((int)$row['quantity_scaled']/(int)$row['quantity_scale'],4)); ?></td><td><?php echo esc_html($fmt($row['average_cost_minor'])); ?></td><td><?php echo esc_html($row['currency_code']); ?></td><td><strong><?php echo esc_html($fmt($row['inventory_value_minor'])); ?></strong></td></tr><?php endforeach; ?><?php if(!$inventory['rows']): ?><tr><td colspan="7">موجودی ارزش‌گذاری‌شده‌ای وجود ندارد.</td></tr><?php endif; ?></tbody></table>
  <?php endif; ?></div>
  <?php
 }
 public static function record_cash_transaction(): void {
  self::guard();check_admin_referer('mda_record_cash_transaction');
  try{$type=sanitize_key(wp_unslash($_POST['transaction_type']??''));$amount=filter_var((string)($_POST['amount_minor']??''),FILTER_VALIDATE_INT);if(false===$amount||$amount<1)throw new InvalidArgumentException('مبلغ تراکنش باید عدد صحیح مثبت باشد.');
   $key=sanitize_text_field(wp_unslash($_POST['request_key']??''));$date=sanitize_text_field(wp_unslash($_POST['transaction_date']??''));$currency=sanitize_text_field(wp_unslash($_POST['currency_code']??'IRR'));$method=sanitize_key(wp_unslash($_POST['payment_method']??'cash'));
   $description=sanitize_text_field(wp_unslash($_POST['description']??''));$reference=sanitize_text_field(wp_unslash($_POST['reference_number']??''));
   $contraId=$type==='receipt'?absint($_POST['income_account_id']??0):($type==='payment'?absint($_POST['expense_account_id']??0):0);$result=MDA_Cash_Service::record($type,(int)$amount,absint($_POST['cash_account_id']??0),$contraId,absint($_POST['destination_cash_account_id']??0),$currency,$method,$key,$date,$description,$reference);
   $url=add_query_arg(array('page'=>'mda-cashbook','mda_notice'=>$result['duplicate']?'cash_duplicate':'cash_recorded'),admin_url('admin.php'));
  }catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-cashbook','mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));}
  wp_safe_redirect($url);exit;
 }
 public static function import_bank_statement(): void {
  self::guard();check_admin_referer('mda_import_bank_statement');
  try{$account=absint($_POST['bank_account_id']??0);$currency=sanitize_text_field(wp_unslash($_POST['currency_code']??'IRR'));$reference=sanitize_text_field(wp_unslash($_POST['statement_reference']??''));$opening=filter_var((string)($_POST['opening_balance_minor']??''),FILTER_VALIDATE_INT);$closing=filter_var((string)($_POST['closing_balance_minor']??''),FILTER_VALIDATE_INT);if(false===$opening||false===$closing)throw new InvalidArgumentException('مانده اول و پایان صورت‌حساب باید عدد صحیح باشند.');$csv=(string)wp_unslash($_POST['statement_csv']??'');$key=sanitize_text_field(wp_unslash($_POST['request_key']??''));$result=MDA_Bank_Reconciliation_Service::import_csv($account,$currency,$reference,(int)$opening,(int)$closing,$csv,$key);$url=add_query_arg(array('page'=>'mda-bank-reconciliation','import_id'=>$result['import_id'],'mda_notice'=>$result['duplicate']?'bank_statement_duplicate':'bank_statement_imported'),admin_url('admin.php'));}
  catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-bank-reconciliation','mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));}wp_safe_redirect($url);exit;
 }
 public static function match_bank_statement_line(): void {
  self::guard();$line=absint($_POST['line_id']??0);$import=absint($_POST['import_id']??0);check_admin_referer('mda_match_bank_statement_line_'.$line);
  try{$journal=absint($_POST['journal_entry_id']??0);MDA_Bank_Reconciliation_Service::match($line,$journal);$notice='bank_line_matched';}
  catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-bank-reconciliation','import_id'=>$import,'line_id'=>$line,'mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));wp_safe_redirect($url);exit;}
  wp_safe_redirect(add_query_arg(array('page'=>'mda-bank-reconciliation','import_id'=>$import,'mda_notice'=>$notice),admin_url('admin.php')));exit;
 }
 public static function unmatch_bank_statement_line(): void {
  self::guard();$line=absint($_POST['line_id']??0);$import=absint($_POST['import_id']??0);check_admin_referer('mda_unmatch_bank_statement_line_'.$line);
  try{MDA_Bank_Reconciliation_Service::unmatch($line);$notice='bank_line_unmatched';}
  catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-bank-reconciliation','import_id'=>$import,'mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));wp_safe_redirect($url);exit;}
  wp_safe_redirect(add_query_arg(array('page'=>'mda-bank-reconciliation','import_id'=>$import,'mda_notice'=>$notice),admin_url('admin.php')));exit;
 }
 public static function ignore_bank_statement_line(): void {
  self::guard();$line=absint($_POST['line_id']??0);$import=absint($_POST['import_id']??0);check_admin_referer('mda_ignore_bank_statement_line_'.$line);
  try{MDA_Bank_Reconciliation_Service::ignore($line);$notice='bank_line_ignored';}
  catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-bank-reconciliation','import_id'=>$import,'mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));wp_safe_redirect($url);exit;}
  wp_safe_redirect(add_query_arg(array('page'=>'mda-bank-reconciliation','import_id'=>$import,'mda_notice'=>$notice),admin_url('admin.php')));exit;
 }
 public static function unignore_bank_statement_line(): void {
  self::guard();$line=absint($_POST['line_id']??0);$import=absint($_POST['import_id']??0);check_admin_referer('mda_unignore_bank_statement_line_'.$line);
  try{MDA_Bank_Reconciliation_Service::unignore($line);$notice='bank_line_unignored';}
  catch(Throwable $e){$url=add_query_arg(array('page'=>'mda-bank-reconciliation','import_id'=>$import,'mda_error'=>rawurlencode($e->getMessage())),admin_url('admin.php'));wp_safe_redirect($url);exit;}
  wp_safe_redirect(add_query_arg(array('page'=>'mda-bank-reconciliation','import_id'=>$import,'mda_notice'=>$notice),admin_url('admin.php')));exit;
 }
 public static function render_bank_reconciliation(): void {
  self::guard();if(!MDA_Database::is_available()){echo '<div class="wrap" dir="rtl"><h1>مغایرت‌گیری بانکی</h1><div class="notice notice-error"><p>PDO_SQLite فعال نیست.</p></div></div>';return;}
  $accounts=MDA_Bank_Reconciliation_Service::bank_accounts();$imports=MDA_Bank_Reconciliation_Service::imports(50);$importId=absint($_GET['import_id']??0);$selected=$importId?MDA_Bank_Reconciliation_Service::get_import($importId):null;$summary=$selected?MDA_Bank_Reconciliation_Service::summary($importId):null;$lines=$selected?MDA_Bank_Reconciliation_Service::lines($importId,2000):array();$lineId=absint($_GET['line_id']??0);$selectedLine=null;$candidates=array();
  foreach($lines as $line){if((int)$line['id']===$lineId){$selectedLine=$line;break;}}
  if($selectedLine&&(!in_array($selectedLine['current_action'],array('matched','ignored'),true)))$candidates=MDA_Bank_Reconciliation_Service::candidates($lineId,30);
  $fmt=static function($v):string{return number_format((int)$v,0,'.',',');};
  ?>
  <div class="wrap" dir="rtl"><h1>مغایرت‌گیری بانکی</h1><p>صورت‌حساب را به‌صورت CSV وارد کنید و هر ردیف را با گردش متناظر دفتر کل تطبیق دهید. هیچ تراکنش بانکی یا سند حسابداری به‌صورت خودکار ساخته یا حذف نمی‌شود.</p><?php self::notices(); ?>
  <div class="mda-card" style="margin:16px 0"><h2>ورود صورت‌حساب</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_import_bank_statement"><?php wp_nonce_field('mda_import_bank_statement'); ?><input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
  <div class="mda-grid"><label>حساب بانکی<select name="bank_account_id" required><option value="">انتخاب حساب</option><?php foreach($accounts as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo esc_html($a['account_code'].' — '.$a['account_name']); ?></option><?php endforeach; ?></select></label>
  <label>ارز<select name="currency_code"><option value="IRR">ریال (IRR)</option><option value="IRT">تومان (IRT)</option></select></label>
  <label>شماره صورت‌حساب / دوره<input type="text" name="statement_reference" maxlength="120" required></label>
  <label>مانده اول دوره (عدد صحیح)<input type="number" name="opening_balance_minor" required></label><label>مانده پایان دوره (عدد صحیح)<input type="number" name="closing_balance_minor" required></label></div>
  <label>محتوای CSV <textarea name="statement_csv" rows="7" required style="width:100%;font-family:monospace" placeholder="date,direction,amount,reference,description&#10;2026-11-16,credit,100000,REF-001,واریز مشتری&#10;2026-11-16,debit,25000,REF-002,کارمزد"></textarea></label>
  <p class="description">ترتیب ستون‌ها: تاریخ میلادی YYYY-MM-DD، نوع credit/debit یا واریز/برداشت، مبلغ صحیح در واحد خرد، شماره مرجع و شرح. جداکننده ویرگول، نقطه‌ویرگول یا Tab پشتیبانی می‌شود. مانده پایان باید برابر مانده اول + واریزها − برداشت‌ها باشد.</p>
  <button class="button button-primary" type="submit" <?php disabled(!$accounts); ?>>وارد کردن و ذخیره در SQLite</button></form></div>
  <?php if($selected&&$summary): ?><div class="mda-card" style="margin:16px 0"><h2>صورت‌حساب <?php echo esc_html($selected['statement_reference']); ?></h2><p><?php echo esc_html($selected['account_code'].' — '.$selected['account_name']); ?> | <?php echo esc_html($selected['currency_code']); ?> | <?php echo esc_html($selected['period_start'].' تا '.$selected['period_end']); ?></p>
  <div class="mda-grid"><div class="mda-stat"><span>کل ردیف‌ها</span><strong><?php echo (int)$summary['total_lines']; ?></strong></div><div class="mda-stat"><span>تطبیق‌شده</span><strong><?php echo (int)$summary['matched_lines']; ?></strong></div><div class="mda-stat"><span>تطبیق‌نشده</span><strong><?php echo (int)$summary['unmatched_lines']; ?></strong></div><div class="mda-stat"><span>نادیده‌گرفته‌شده</span><strong><?php echo (int)$summary['ignored_lines']; ?></strong></div></div>
  <?php if($selectedLine): ?><div class="mda-card" style="margin:16px 0"><h3>تطبیق ردیف <?php echo (int)$selectedLine['line_number']; ?></h3><p><?php echo esc_html($selectedLine['transaction_date'].' | '.($selectedLine['direction']==='credit'?'واریز':'برداشت').' | '.$fmt($selectedLine['amount_minor']).' '.$selected['currency_code'].' | '.$selectedLine['description']); ?></p>
  <?php if($candidates): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_match_bank_statement_line"><input type="hidden" name="line_id" value="<?php echo (int)$lineId; ?>"><input type="hidden" name="import_id" value="<?php echo (int)$importId; ?>"><?php wp_nonce_field('mda_match_bank_statement_line_'.$lineId); ?><label>سند متناظر دفتر کل<select name="journal_entry_id" required><option value="">انتخاب سند</option><?php foreach($candidates as $candidate): ?><option value="<?php echo (int)$candidate['journal_entry_id']; ?>"><?php echo esc_html($candidate['document_number'].' | '.$candidate['document_date'].' | '.$fmt($candidate['debit_minor']?:$candidate['credit_minor']).' | '.($candidate['memo']?:$candidate['document_description'])); ?></option><?php endforeach; ?></select></label> <button class="button button-primary">ثبت تطبیق</button></form><?php else: ?><p>سند دقیق با همان حساب، ارز، مبلغ و جهت در بازه سه‌روزه پیدا نشد؛ ابتدا سند دفتر کل را بررسی کنید.</p><?php endif; ?></div><?php endif; ?>
  <h3>ردیف‌های صورت‌حساب</h3><table class="widefat striped"><thead><tr><th>#</th><th>تاریخ</th><th>نوع</th><th>مبلغ</th><th>مرجع</th><th>شرح</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
  <?php foreach($lines as $row): $state=$row['current_action']?:'unmatched'; ?><tr><td><?php echo (int)$row['line_number']; ?></td><td><?php echo esc_html($row['transaction_date']); ?></td><td><?php echo esc_html($row['direction']==='credit'?'واریز':'برداشت'); ?></td><td><?php echo esc_html($fmt($row['amount_minor'])); ?></td><td><?php echo esc_html($row['reference_number']??'—'); ?></td><td><?php echo esc_html($row['description']); ?></td><td><?php echo esc_html(array('matched'=>'تطبیق‌شده','ignored'=>'نادیده‌گرفته‌شده','unmatched'=>'تطبیق‌نشده','unignored'=>'تطبیق‌نشده')[$state]??'تطبیق‌نشده'); ?><?php if($state==='matched'&&$row['document_number']): ?> (<?php echo esc_html($row['document_number']); ?>)<?php endif; ?></td><td>
  <?php if($state==='matched'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_unmatch_bank_statement_line"><input type="hidden" name="line_id" value="<?php echo (int)$row['id']; ?>"><input type="hidden" name="import_id" value="<?php echo (int)$importId; ?>"><?php wp_nonce_field('mda_unmatch_bank_statement_line_'.(int)$row['id']); ?><button class="button">لغو تطبیق</button></form>
  <?php elseif($state==='ignored'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_unignore_bank_statement_line"><input type="hidden" name="line_id" value="<?php echo (int)$row['id']; ?>"><input type="hidden" name="import_id" value="<?php echo (int)$importId; ?>"><?php wp_nonce_field('mda_unignore_bank_statement_line_'.(int)$row['id']); ?><button class="button">بازگرداندن</button></form>
  <?php else: ?><a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>'mda-bank-reconciliation','import_id'=>$importId,'line_id'=>(int)$row['id']),admin_url('admin.php'))); ?>">پیشنهاد تطبیق</a> <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"><input type="hidden" name="action" value="mda_ignore_bank_statement_line"><input type="hidden" name="line_id" value="<?php echo (int)$row['id']; ?>"><input type="hidden" name="import_id" value="<?php echo (int)$importId; ?>"><?php wp_nonce_field('mda_ignore_bank_statement_line_'.(int)$row['id']); ?><button class="button">نادیده گرفتن</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  <h2 class="mda-section-title">صورت‌حساب‌های واردشده</h2><table class="widefat striped"><thead><tr><th>شماره</th><th>حساب</th><th>ارز</th><th>بازه</th><th>ردیف‌ها</th><th>تطبیق‌شده</th><th>نادیده</th><th>عملیات</th></tr></thead><tbody><?php foreach($imports as $item): ?><tr><td><?php echo esc_html($item['statement_reference']); ?></td><td><?php echo esc_html($item['account_code'].' — '.$item['account_name']); ?></td><td><?php echo esc_html($item['currency_code']); ?></td><td><?php echo esc_html($item['period_start'].' تا '.$item['period_end']); ?></td><td><?php echo (int)$item['total_lines']; ?></td><td><?php echo (int)$item['matched_lines']; ?></td><td><?php echo (int)$item['ignored_lines']; ?></td><td><a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>'mda-bank-reconciliation','import_id'=>(int)$item['id']),admin_url('admin.php'))); ?>">مشاهده / ادامه</a></td></tr><?php endforeach; ?><?php if(!$imports): ?><tr><td colspan="8">صورت‌حسابی وارد نشده است.</td></tr><?php endif; ?></tbody></table></div>
  <?php
 }
 public static function render_cashbook(): void {
  self::guard();if(!MDA_Database::is_available()){echo '<div class="wrap" dir="rtl"><h1>صندوق و بانک</h1><div class="notice notice-error"><p>PDO_SQLite فعال نیست.</p></div></div>';return;}
  $cash=MDA_Cash_Service::cash_accounts();$income=MDA_Cash_Service::contra_accounts('income');$expense=MDA_Cash_Service::contra_accounts('expense');$balances=MDA_Cash_Service::balances();$transactions=MDA_Cash_Service::transactions(100);$fmt=static function($v):string{return number_format((int)$v,0,'.',',');};
  ?>
  <div class="wrap" dir="rtl"><h1>صندوق، بانک و هزینه‌ها</h1><p>ثبت دریافت و پرداخت عمومی، انتقال بین صندوق/بانک و هزینه‌های عملیاتی. تسویه فاکتور خرید و دریافت فاکتور فروش همچنان از گردش اختصاصی همان فاکتور انجام می‌شود.</p><?php self::notices(); ?>
  <div class="mda-card" style="margin:16px 0"><h2>ثبت تراکنش جدید</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="mda_record_cash_transaction"><?php wp_nonce_field('mda_record_cash_transaction'); ?><input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
   <div class="mda-grid">
    <label>نوع تراکنش<select name="transaction_type" data-mda-cash-type required><option value="receipt">دریافت متفرقه</option><option value="payment">پرداخت / هزینه</option><option value="transfer">انتقال بین حساب‌ها</option></select></label>
    <label>مبلغ<input type="number" name="amount_minor" min="1" required></label>
    <label>واحد پول<select name="currency_code"><option value="IRR">ریال (IRR)</option><option value="IRT">تومان (IRT)</option></select></label>
    <label>حساب صندوق/بانک<select name="cash_account_id" required><option value="">انتخاب حساب</option><?php foreach($cash as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo esc_html($a['account_code'].' — '.$a['account_name']); ?></option><?php endforeach; ?></select></label>
    <label class="mda-cash-income">حساب درآمد<select name="income_account_id"><option value="">انتخاب درآمد</option><?php foreach($income as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo esc_html($a['account_code'].' — '.$a['account_name']); ?></option><?php endforeach; ?></select></label>
    <label class="mda-cash-expense" style="display:none">سرفصل هزینه<select name="expense_account_id"><option value="">انتخاب هزینه</option><?php foreach($expense as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo esc_html($a['account_code'].' — '.$a['account_name']); ?></option><?php endforeach; ?></select></label>
    <label class="mda-cash-destination" style="display:none">حساب مقصد انتقال<select name="destination_cash_account_id"><option value="">انتخاب حساب مقصد</option><?php foreach($cash as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo esc_html($a['account_code'].' — '.$a['account_name']); ?></option><?php endforeach; ?></select></label>
    <label>روش پرداخت<select name="payment_method"><option value="cash">نقد</option><option value="bank">بانک</option><option value="pos">کارتخوان</option><option value="transfer">انتقال</option></select></label>
    <label>تاریخ<input type="date" name="transaction_date" required value="<?php echo esc_attr(current_time('Y-m-d')); ?>"></label>
    <label>شماره پیگیری / مرجع<input type="text" name="reference_number" maxlength="120"></label>
   </div>
   <p><label>شرح تراکنش<textarea name="description" rows="2" required maxlength="500" style="width:100%"></textarea></label></p>
   <button class="button button-primary" type="submit" <?php disabled(!$cash); ?>>ثبت تراکنش و سند حسابداری</button>
  </form></div>
  <h2 class="mda-section-title">مانده صندوق و بانک</h2><table class="widefat striped"><thead><tr><th>کد حساب</th><th>حساب</th><th>ارز</th><th>مانده</th></tr></thead><tbody><?php foreach($balances as $row): ?><tr><td><?php echo esc_html($row['account_code']); ?></td><td><?php echo esc_html($row['account_name']); ?></td><td><?php echo esc_html($row['currency_code']); ?></td><td><strong><?php echo esc_html($fmt($row['balance_minor'])); ?></strong></td></tr><?php endforeach; ?><?php if(!$balances): ?><tr><td colspan="4">برای حساب‌های نقدی گردش ثبت نشده است.</td></tr><?php endif; ?></tbody></table>
  <h2 class="mda-section-title">آخرین تراکنش‌ها</h2><table class="widefat striped"><thead><tr><th>شماره سند</th><th>تاریخ</th><th>نوع</th><th>حساب مبدأ</th><th>حساب مقابل/مقصد</th><th>مبلغ</th><th>ارز</th><th>روش</th><th>شرح</th><th>مرجع</th></tr></thead><tbody><?php foreach($transactions as $row): ?><tr><td><?php echo esc_html($row['document_number']); ?></td><td><?php echo esc_html($row['transaction_date']); ?></td><td><?php echo esc_html(array('receipt'=>'دریافت','payment'=>'پرداخت','transfer'=>'انتقال')[$row['transaction_type']]??$row['transaction_type']); ?></td><td><?php echo esc_html($row['account_code'].' — '.$row['account_name']); ?></td><td><?php echo esc_html(($row['destination_code']?($row['destination_code'].' — '.$row['destination_name']):($row['contra_code']?($row['contra_code'].' — '.$row['contra_name']):'—'))); ?></td><td><?php echo esc_html($fmt($row['amount_minor'])); ?></td><td><?php echo esc_html($row['currency_code']); ?></td><td><?php echo esc_html($row['payment_method']); ?></td><td><?php echo esc_html($row['description']); ?></td><td><?php echo esc_html($row['reference_number']??'—'); ?></td></tr><?php endforeach; ?><?php if(!$transactions): ?><tr><td colspan="10">تراکنشی ثبت نشده است.</td></tr><?php endif; ?></tbody></table></div>
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
   $messages=array('party_saved'=>'طرف‌حساب ذخیره شد.','party_archived'=>'طرف‌حساب بایگانی شد.','account_saved'=>'حساب ذخیره شد.','purchase_draft_created'=>'پیش‌نویس خرید ایجاد شد.','purchase_posted'=>'فاکتور قطعی و موجودی ثبت شد.','purchase_already_posted'=>'این فاکتور قبلاً قطعی شده است.','payment_recorded'=>'پرداخت مرحله‌ای ثبت شد.','payment_duplicate'=>'این پرداخت قبلاً ثبت شده است.','purchase_return_posted'=>'مرجوعی و سند اصلاحی ثبت شد.','purchase_return_duplicate'=>'این مرجوعی قبلاً ثبت شده است.','sales_receipt_recorded'=>'دریافت مشتری و سند حسابداری ثبت شد.','sales_receipt_duplicate'=>'این دریافت قبلاً ثبت شده است.','sales_return_posted'=>'مرجوعی فروش و سند اصلاحی ثبت شد.','sales_return_duplicate'=>'این مرجوعی قبلاً ثبت شده است.','sales_refund_posted'=>'استرداد وجه و سند حسابداری ثبت شد.','sales_refund_duplicate'=>'این استرداد قبلاً ثبت شده است.','reconciliation_run'=>'دسته تطبیق سفارش‌ها اجرا شد.','stock_sync_run'=>'دسته همگام‌سازی موجودی اجرا شد.','cash_recorded'=>'تراکنش صندوق/بانک و سند حسابداری ثبت شد.','cash_duplicate'=>'این تراکنش قبلاً ثبت شده است.','bank_statement_imported'=>'صورت‌حساب بانکی در SQLite وارد شد.','bank_statement_duplicate'=>'این صورت‌حساب قبلاً وارد شده است.','bank_line_matched'=>'ردیف صورت‌حساب با سند دفتر کل تطبیق داده شد.','bank_line_unmatched'=>'تطبیق ردیف لغو شد.','bank_line_ignored'=>'ردیف صورت‌حساب نادیده گرفته شد.','bank_line_unignored'=>'ردیف دوباره فعال شد.');
   $key=sanitize_key(wp_unslash($_GET['mda_notice']));$message=$messages[$key]??'عملیات با موفقیت انجام شد.';
   echo '<div class="notice notice-success"><p>'.esc_html($message).'</p></div>';
  }
  if(!empty($_GET['mda_error'])) echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['mda_error']))).'</p></div>';
 }
}

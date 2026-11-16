<?php
defined('ABSPATH') || exit;

final class MDA_Gateway_Admin {
 public static function init():void {
  add_action('admin_menu',array(__CLASS__,'menu'));
  add_action('admin_post_mda_gateway_configure',array(__CLASS__,'configure'));
  add_action('admin_post_mda_gateway_capture',array(__CLASS__,'capture'));
  add_action('admin_post_mda_gateway_import',array(__CLASS__,'import'));
  add_action('admin_post_mda_gateway_post',array(__CLASS__,'post'));
 }
 public static function menu():void {
  add_submenu_page('mda-accounting','تسویه درگاه‌ها','تسویه درگاه‌ها',MDA_Capabilities::for_page('mda-gateway-settlements'),'mda-gateway-settlements',array(__CLASS__,'render'));
 }
 private static function guard():void {if(!MDA_Capabilities::user_can_action())wp_die(esc_html__('دسترسی غیرمجاز','malidev-accounting'));}
 private static function redirect(string $error=''):void {$url=admin_url('admin.php?page=mda-gateway-settlements');wp_safe_redirect($error?add_query_arg('mda_error',rawurlencode($error),$url):add_query_arg('mda_notice','saved',$url));exit;}
 public static function configure():void {
  self::guard();check_admin_referer('mda_gateway_configure');
  try{MDA_Gateway_Settlement_Service::configure(wp_unslash($_POST['gateway_key']??''),wp_unslash($_POST['gateway_name']??''));self::redirect();}
  catch(Throwable $e){self::redirect($e->getMessage());}
 }
 public static function capture():void {
  self::guard();check_admin_referer('mda_gateway_capture');
  try{$amount=filter_var((string)($_POST['gross_minor']??''),FILTER_VALIDATE_INT);if(false===$amount||$amount<1)throw new InvalidArgumentException('مبلغ ناخالص معتبر نیست.');MDA_Gateway_Settlement_Service::capture(absint($_POST['order_id']??0),sanitize_key(wp_unslash($_POST['gateway_key']??'')),(int)$amount,sanitize_text_field(wp_unslash($_POST['reference']??'')),'gateway-capture-'.wp_generate_uuid4());self::redirect();}
  catch(Throwable $e){self::redirect($e->getMessage());}
 }
 public static function import():void {
  self::guard();check_admin_referer('mda_gateway_import');
  try{$csv=wp_unslash($_POST['csv_data']??'');if(!is_string($csv))throw new InvalidArgumentException('محتوای CSV معتبر نیست.');MDA_Gateway_Settlement_Service::import_csv(sanitize_key(wp_unslash($_POST['gateway_key']??'')),sanitize_text_field(wp_unslash($_POST['settlement_reference']??'')),absint($_POST['bank_account_id']??0),sanitize_key(wp_unslash($_POST['currency_code']??'IRR')),sanitize_text_field(wp_unslash($_POST['settlement_date']??'')),$csv,'gateway-import-'.wp_generate_uuid4());self::redirect();}
  catch(Throwable $e){self::redirect($e->getMessage());}
 }
 public static function post():void {
  self::guard();$id=absint($_POST['settlement_id']??0);check_admin_referer('mda_gateway_post_'.$id);
  try{MDA_Gateway_Settlement_Service::post($id,'gateway-post-'.wp_generate_uuid4());self::redirect();}
  catch(Throwable $e){self::redirect($e->getMessage());}
 }
 public static function render():void {
  self::guard();if(!MDA_Database::is_available()){echo '<div class="wrap"><div class="notice notice-error"><p>PDO_SQLite فعال نیست.</p></div></div>';return;}
  $gateways=MDA_Gateway_Settlement_Service::gateways();$banks=MDA_Cash_Service::cash_accounts();$rows=MDA_Gateway_Settlement_Service::all();
  echo '<div class="wrap" dir="rtl"><h1>تسویه درگاه‌های پرداخت</h1>';
  if(!empty($_GET['mda_error']))echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['mda_error']))).'</p></div>';
  if(!empty($_GET['mda_notice']))echo '<div class="notice notice-success"><p>عملیات ثبت شد.</p></div>';
  echo '<p>ابتدا حساب واسط درگاه را تعریف کنید. سپس دریافت تأییدشده سفارش را ثبت و گزارش تسویه CSV را وارد کنید. ساخت سند واریز بانکی فقط پس از بررسی و تأیید دستی انجام می‌شود.</p>';
  echo '<div style="background:#fff;padding:18px;max-width:1000px;margin:16px 0"><h2>تعریف درگاه عمومی</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mda_gateway_configure">';wp_nonce_field('mda_gateway_configure');echo '<label>کلید انگلیسی <input name="gateway_key" required pattern="[a-zA-Z0-9_-]+" maxlength="64" placeholder="my_gateway"></label> <label>نام نمایشی <input name="gateway_name" required maxlength="120"></label> <button class="button button-primary">ایجاد حساب واسط</button></form>';
  echo '<p>درگاه‌های تعریف‌شده: ';foreach($gateways as $g)echo esc_html($g['gateway_key'].' ('.$g['account_code'].') — '.$g['gateway_name'].'؛ ');echo '</p></div>';
  echo '<div style="background:#fff;padding:18px;max-width:1000px;margin:16px 0"><h2>ثبت دریافت تأییدشده سفارش</h2><p>این فرم فقط برای ثبت تراکنشی است که موفقیت آن در درگاه تأیید شده باشد؛ سفارش باید قبلاً سند فروش قطعی داشته باشد.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mda_gateway_capture">';wp_nonce_field('mda_gateway_capture');echo '<label>درگاه <select name="gateway_key" required>';foreach($gateways as $g)echo '<option value="'.esc_attr($g['gateway_key']).'">'.esc_html($g['gateway_name']).'</option>';echo '</select></label> <label>شماره سفارش <input type="number" min="1" name="order_id" required></label> <label>مبلغ ناخالص به واحد خرد <input type="number" min="1" name="gross_minor" required></label> <label>مرجع تراکنش <input name="reference" maxlength="120"></label> <button class="button">ثبت دریافت درگاه</button></form></div>';
  echo '<div style="background:#fff;padding:18px;max-width:1000px;margin:16px 0"><h2>ورود CSV تسویه</h2><p>ستون‌های ضروری: order_id,gross_minor,fee_minor,net_minor و ستون اختیاری reference. ارقام فارسی و انگلیسی، جداکننده comma یا semicolon پذیرفته می‌شود. مبلغ‌ها باید به واحد خرد و با رابطه ناخالص = کارمزد + خالص باشند.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mda_gateway_import">';wp_nonce_field('mda_gateway_import');echo '<p><label>درگاه <select name="gateway_key" required>';foreach($gateways as $g)echo '<option value="'.esc_attr($g['gateway_key']).'">'.esc_html($g['gateway_name']).'</option>';echo '</select></label> <label>مرجع تسویه <input name="settlement_reference" required maxlength="120"></label> <label>تاریخ تسویه <input type="date" name="settlement_date" value="'.esc_attr(current_time('Y-m-d')).'" required></label></p><p><label>حساب بانکی مقصد <select name="bank_account_id" required>';foreach($banks as $b)echo '<option value="'.(int)$b['id'].'">'.esc_html($b['account_code'].' — '.$b['account_name']).'</option>';echo '</select></label> <label>ارز <select name="currency_code"><option value="IRR">ریال</option><option value="IRT">تومان</option></select></label></p><textarea name="csv_data" rows="8" class="large-text code" required placeholder="order_id,gross_minor,fee_minor,net_minor,reference&#10;123,100000,2000,98000,TX-123"></textarea><p><button class="button button-primary">اعتبارسنجی و ورود CSV</button></p></form></div>';
  echo '<h2>سوابق تسویه</h2><table class="widefat striped"><thead><tr><th>درگاه</th><th>مرجع</th><th>تاریخ</th><th>ارز</th><th>تعداد ردیف</th><th>بانک مقصد</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
  foreach($rows as $row){$posted=!empty($row['accounting_document_id']);echo '<tr><td>'.esc_html($row['gateway_key']).'</td><td>'.esc_html($row['settlement_reference']).'</td><td>'.esc_html($row['settlement_date']).'</td><td>'.esc_html($row['currency_code']).'</td><td>'.(int)$row['row_count'].'</td><td>'.esc_html($row['account_code'].' — '.$row['account_name']).'</td><td>'.($posted?'سند قطعی ثبت شده':'در انتظار تأیید').'</td><td>';if(!$posted){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mda_gateway_post"><input type="hidden" name="settlement_id" value="'.(int)$row['id'].'">';wp_nonce_field('mda_gateway_post_'.(int)$row['id']);echo '<button class="button button-primary" onclick="return confirm(\'سند تسویه و کارمزد ثبت شود؟\')">قطعی‌سازی تسویه</button></form>';}else echo 'سند #'.(int)$row['accounting_document_id'];echo '</td></tr>';}
  if(!$rows)echo '<tr><td colspan="8">تسویه‌ای ثبت نشده است.</td></tr>';echo '</tbody></table></div>';
 }
}

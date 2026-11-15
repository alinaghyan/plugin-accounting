<?php
defined( 'ABSPATH' ) || exit;

final class MDA_Admin {
 public static function init(): void {
  add_action('admin_menu',array(__CLASS__,'menu'));
  add_action('admin_post_mda_save_party',array(__CLASS__,'save_party'));
  add_action('admin_post_mda_archive_party',array(__CLASS__,'archive_party'));
  add_action('admin_post_mda_save_account',array(__CLASS__,'save_account'));
 }

 public static function menu(): void {
  add_menu_page(__('حسابداری MaliDev','malidev-accounting'),__('حسابداری','malidev-accounting'),MDA_Capabilities::MANAGE,'mda-accounting',array(__CLASS__,'render'),'dashicons-chart-area',56);
  add_submenu_page('mda-accounting','طرف‌حساب‌ها','طرف‌حساب‌ها',MDA_Capabilities::MANAGE,'mda-parties',array(__CLASS__,'render_parties'));
  add_submenu_page('mda-accounting','کدینگ حساب‌ها','کدینگ حساب‌ها',MDA_Capabilities::MANAGE,'mda-accounts',array(__CLASS__,'render_accounts'));
  add_submenu_page('mda-accounting','گردش حساب اشخاص','گردش حساب اشخاص',MDA_Capabilities::MANAGE,'mda-party-ledger',array(__CLASS__,'render_party_ledger'));
 }

 private static function guard(): void {
  if(!current_user_can(MDA_Capabilities::MANAGE)) wp_die(esc_html__('شما اجازه دسترسی به این بخش را ندارید.','malidev-accounting'));
 }
 public static function render(): void {
  self::guard();$available=MDA_Database::is_available();$connected=false;$error='';$integrity='';
  if($available){try{$pdo=MDA_Database::connection();$connected=true;$integrity=(string)$pdo->query('PRAGMA quick_check')->fetchColumn();}catch(Throwable $e){$error=$e->getMessage();}}
  ?>
  <div class="wrap" dir="rtl"><h1>حسابداری MaliDev</h1><p>نسخه توسعه‌ای هسته حسابداری؛ هنوز ثبت فروش و خرید عملیاتی فعال نشده است.</p>
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
  $party_id=absint($_GET['party_id']??0);$from=sanitize_text_field(wp_unslash($_GET['from']??date('Y-m-01')));$to=sanitize_text_field(wp_unslash($_GET['to']??current_time('Y-m-d')));$statement=null;$error='';
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
  <?php endif; ?></div>
  <?php
 }

 private static function notices(): void {
  if(!empty($_GET['mda_notice'])) echo '<div class="notice notice-success"><p>'.esc_html('عملیات با موفقیت انجام شد.').'</p></div>';
  if(!empty($_GET['mda_error'])) echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['mda_error']))).'</p></div>';
 }
}

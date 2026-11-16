<?php
defined( 'ABSPATH' ) || exit;

final class MDA_Installer {
 private const SCHEMA_VERSION=15;
 public static function activate(): void {
  if(!MDA_Database::is_available()) wp_die(esc_html__('برای نصب MaliDev Accounting باید افزونه PHP با نام PDO_SQLite فعال باشد.','malidev-accounting'),esc_html__('پیش‌نیاز نصب موجود نیست','malidev-accounting'),array('back_link'=>true));
  try { self::migrate(); MDA_Capabilities::add(); }
  catch(Throwable $exception){ wp_die(esc_html('نصب افزونه انجام نشد: '.$exception->getMessage()),esc_html__('خطا در نصب حسابداری','malidev-accounting'),array('back_link'=>true)); }
 }
 public static function migrate(): void {
  $pdo=MDA_Database::connection();
  $exists=(bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='mda_settings'")->fetchColumn();
  $version=0;
  if($exists){$q=$pdo->prepare("SELECT setting_value FROM mda_settings WHERE setting_key=:key");$q->execute(array(':key'=>'schema_version'));$version=(int)$q->fetchColumn();}
  $migrations=array(
   1=>array('001_initial.sql','فایل مهاجرت اولیه پیدا نشد.'),
   2=>array('002_parties-and-chart-of-accounts.sql','فایل مهاجرت طرف‌حساب و کدینگ پیدا نشد.'),
   3=>array('003-party-subledger.sql','فایل مهاجرت تفصیلی اشخاص پیدا نشد.'),
   4=>array('004-purchasing-inventory-foundation.sql','فایل مهاجرت خرید و انبار پیدا نشد.'),
   5=>array('005-purchase-posting-costing.sql','فایل مهاجرت قطعی‌سازی خرید و بهای تمام‌شده پیدا نشد.'),
   6=>array('006-immutable-purchase-history.sql','فایل محافظت از سوابق خرید پیدا نشد.'),
   7=>array('007-purchase-settlements-and-returns.sql','فایل پرداخت‌های مرحله‌ای و مرجوعی خرید پیدا نشد.'),
   8=>array('008-sales-outbox-and-cogs.sql','فایل مهاجرت فروش، outbox و بهای تمام‌شده فروش پیدا نشد.'),
   9=>array('009-customer-receipts.sql','فایل مهاجرت دریافت‌های مشتری پیدا نشد.'),
   10=>array('010-sales-returns-and-refunds.sql','فایل مهاجرت مرجوعی فروش و استرداد مشتری پیدا نشد.'),
   11=>array('011-woocommerce-reconciliation.sql','فایل مهاجرت گزارش تطبیق ووکامرس پیدا نشد.'),
   12=>array('012-woocommerce-stock-sync.sql','فایل مهاجرت همگام‌سازی موجودی ووکامرس پیدا نشد.'),
   13=>array('013-cash-bank-and-expenses.sql','فایل مهاجرت صندوق، بانک و هزینه‌ها پیدا نشد.'),
   14=>array('014-bank-statement-reconciliation.sql','فایل مهاجرت تطبیق صورت‌حساب بانکی پیدا نشد.'),
   15=>array('015-cheque-management.sql','فایل مهاجرت دفتر چک پیدا نشد.')
  );
  foreach($migrations as $target=>$migration){
   if($version >= $target) continue;
   $sql=file_get_contents(MDA_PATH.'database/migrations/'.$migration[0]);
   if(false===$sql) throw new RuntimeException($migration[1]);
   MDA_Database::transaction(static function(PDO $db) use($sql,$target): void {
    $db->exec($sql);
    $q=$db->prepare("INSERT OR REPLACE INTO mda_settings (setting_key,setting_value,updated_at) VALUES ('schema_version',:version,:now)");
    $q->execute(array(':version'=>(string)$target,':now'=>gmdate('Y-m-d H:i:s')));
   });
   $version=$target;
  }
 }
}

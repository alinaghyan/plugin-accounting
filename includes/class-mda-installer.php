<?php
defined( 'ABSPATH' ) || exit;

final class MDA_Installer {
 private const SCHEMA_VERSION=3;

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
  if($version<1){
   $sql=file_get_contents(MDA_PATH.'database/migrations/001_initial.sql');if(false===$sql)throw new RuntimeException('فایل مهاجرت اولیه پیدا نشد.');
   MDA_Database::transaction(static function(PDO $db) use($sql): void {$db->exec($sql);$q=$db->prepare("INSERT OR REPLACE INTO mda_settings (setting_key,setting_value,updated_at) VALUES ('schema_version','1',:now)");$q->execute(array(':now'=>gmdate('Y-m-d H:i:s')));});
   $version=1;
  }
  if($version<2){
   $sql=file_get_contents(MDA_PATH.'database/migrations/002_parties-and-chart-of-accounts.sql');if(false===$sql)throw new RuntimeException('فایل مهاجرت طرف‌حساب و کدینگ پیدا نشد.');
   MDA_Database::transaction(static function(PDO $db) use($sql): void {$db->exec($sql);$q=$db->prepare("UPDATE mda_settings SET setting_value='2',updated_at=:now WHERE setting_key='schema_version'");$q->execute(array(':now'=>gmdate('Y-m-d H:i:s')));});
  }
  if($version<3){
   $sql=file_get_contents(MDA_PATH.'database/migrations/003-party-subledger.sql');if(false===$sql)throw new RuntimeException('فایل مهاجرت تفصیلی اشخاص پیدا نشد.');
   MDA_Database::transaction(static function(PDO $db) use($sql): void {$db->exec($sql);$q=$db->prepare("UPDATE mda_settings SET setting_value='3',updated_at=:now WHERE setting_key='schema_version'");$q->execute(array(':now'=>gmdate('Y-m-d H:i:s')));});
  }
  update_option('mda_schema_version',self::SCHEMA_VERSION,false);
 }
}

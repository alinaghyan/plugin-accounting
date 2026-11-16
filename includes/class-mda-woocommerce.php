<?php
defined( 'ABSPATH' ) || exit;

/** WooCommerce adapter: hooks only enqueue snapshots; SQLite accounting runs asynchronously. */
final class MDA_WooCommerce {
 public static function init(): void {
  if(!class_exists('WooCommerce'))return;
  add_action('woocommerce_order_status_processing',array(__CLASS__,'queue_order'),20,1);
  add_action('woocommerce_order_status_completed',array(__CLASS__,'queue_order'),20,1);
  add_action('mda_process_outbox',array(__CLASS__,'process_outbox'));
  add_action('mda_process_outbox_batch',array(__CLASS__,'process_outbox'));
  if(!wp_next_scheduled('mda_process_outbox'))wp_schedule_event(time()+120,'hourly','mda_process_outbox');
 }
 public static function queue_order($orderId): void {
  try{$order=wc_get_order($orderId);if($order)MDA_Outbox_Service::enqueue_order($order);}
  catch(Throwable $e){error_log('MaliDev Accounting outbox enqueue failed for order '.absint($orderId).': '.$e->getMessage());}
 }
 public static function process_outbox(): void {
  if(!MDA_Database::is_available())return;
  try{MDA_Outbox_Service::process_batch(10);}catch(Throwable $e){error_log('MaliDev Accounting outbox worker failed: '.$e->getMessage());}
 }
}

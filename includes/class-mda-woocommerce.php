<?php
defined( 'ABSPATH' ) || exit;

/** WooCommerce adapter: hooks only enqueue snapshots; SQLite accounting runs asynchronously. */
final class MDA_WooCommerce {
 public static function init(): void {
  if(!class_exists('WooCommerce'))return;
  add_action('woocommerce_order_status_processing',array(__CLASS__,'queue_order'),20,1);
  add_action('woocommerce_order_status_completed',array(__CLASS__,'queue_order'),20,1);
  add_action('woocommerce_payment_complete',array(__CLASS__,'queue_gateway_payment'),30,1);
  add_action('woocommerce_order_status_cancelled',array(__CLASS__,'queue_cancel'),20,1);
  add_action('woocommerce_order_status_refunded',array(__CLASS__,'queue_cancel'),20,1);
  add_action('woocommerce_order_partially_refunded',array(__CLASS__,'queue_refund'),20,2);
  MDA_Product_Fields::init();
  add_action('mda_process_outbox',array(__CLASS__,'process_outbox'));
  add_action('mda_process_outbox_batch',array(__CLASS__,'process_outbox'));
  add_action('mda_reconcile_woocommerce_batch',array(__CLASS__,'reconcile_orders'));
  add_action('mda_reconcile_woocommerce_continue',array(__CLASS__,'reconcile_orders'));
  add_action('mda_woocommerce_stock_sync_continue',array(__CLASS__,'sync_stock'),10,1);
  if(!wp_next_scheduled('mda_process_outbox'))wp_schedule_event(time()+120,'hourly','mda_process_outbox');
  if(!wp_next_scheduled('mda_reconcile_woocommerce_batch'))wp_schedule_event(time()+300,'daily','mda_reconcile_woocommerce_batch');
 }
 public static function queue_cancel($orderId): void {
  try{$order=wc_get_order($orderId);if($order)MDA_Order_Adjustment_Service::enqueue($order,'cancel');}
  catch(Throwable $e){error_log('MaliDev Accounting cancel enqueue failed for order '.absint($orderId).': '.$e->getMessage());}
 }
 public static function queue_refund($orderId,$refundId): void {
  try{$order=wc_get_order($orderId);if($order)MDA_Order_Adjustment_Service::enqueue($order,'refund',absint($refundId));}
  catch(Throwable $e){error_log('MaliDev Accounting refund enqueue failed for order '.absint($orderId).': '.$e->getMessage());}
 }
 public static function queue_gateway_payment($orderId): void {
  try{$order=wc_get_order($orderId);if(!$order||!$order->is_paid())return;$gateway=sanitize_key((string)$order->get_payment_method());$reference=trim((string)$order->get_transaction_id());if($gateway===''||$reference==='')return;MDA_Outbox_Service::enqueue_gateway_payment($order,$gateway,$reference);}
  catch(Throwable $e){error_log('MaliDev Accounting gateway payment enqueue failed for order '.absint($orderId).': '.$e->getMessage());}
 }
 public static function queue_order($orderId): void {
  try{$order=wc_get_order($orderId);if($order)MDA_Outbox_Service::enqueue_order($order);}
  catch(Throwable $e){error_log('MaliDev Accounting outbox enqueue failed for order '.absint($orderId).': '.$e->getMessage());}
 }
 public static function sync_stock($warehouseId=0): void {
  if(!MDA_Database::is_available())return;
  try{if((int)$warehouseId<1)$warehouseId=MDA_Settings::online_warehouse_id();if((int)$warehouseId>0)MDA_Stock_Sync_Service::run_batch((int)$warehouseId,100);}catch(Throwable $e){error_log('MaliDev Accounting WooCommerce stock sync failed: '.$e->getMessage());}
 }
 public static function reconcile_orders(): void {
  if(!MDA_Database::is_available())return;
  try{MDA_Reconciliation_Service::run_batch(100);}catch(Throwable $e){error_log('MaliDev Accounting WooCommerce reconciliation failed: '.$e->getMessage());}
 }
 public static function process_outbox(): void {
  if(!MDA_Database::is_available())return;
  try{MDA_Outbox_Service::process_batch(10);}catch(Throwable $e){error_log('MaliDev Accounting outbox worker failed: '.$e->getMessage());}
 }
}

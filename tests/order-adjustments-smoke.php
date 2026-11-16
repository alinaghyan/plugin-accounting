<?php
/**
 * WooCommerce cancel/refund → sales return, including on-demand (backordered) lines.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
foreach ( array( 'ledger', 'supply', 'sales', 'sales-return', 'order-adjustment' ) as $name ) {
	require_once dirname( __DIR__ ) . '/includes/class-mda-' . $name . '-service.php';
}

$db = mda_test_database();
$w  = (int) $db->query( "SELECT id FROM mda_warehouses WHERE warehouse_code='MAIN'" )->fetchColumn();
$db->exec( "INSERT INTO mda_inventory_movements (product_id,variation_id,warehouse_id,movement_type,quantity_scaled,quantity_scale,unit_cost_minor,currency_code,source_type,source_id,idempotency_key,occurred_at) VALUES (700,0,$w,'opening',5000000,1000000,400,'IRR','opening','t','open-700-0001','2026-11-16')" );
$db->exec( "INSERT INTO mda_product_cost_state (product_id,variation_id,warehouse_id,quantity_scaled,quantity_scale,average_cost_minor,currency_code,updated_at) VALUES (700,0,$w,5000000,1000000,400,'IRR','2026-11-16')" );
MDA_Supply_Service::save_settings( 701, 0, MDA_Supply_Service::MODE_ON_DEMAND, 2, null );

$sale = MDA_Sales_Service::post_order_payload(
	array(
		'order_id'        => 8001,
		'currency'        => 'IRR',
		'date'            => '2026-11-16',
		'warehouse_id'    => $w,
		'channel'         => 'website',
		'idempotency_key' => 'wc-sale-8001-v1',
		'customer'        => array( 'name' => 'C', 'phone' => '09125550000' ),
		'items'           => array(
			array( 'order_item_id' => 11, 'product_id' => 700, 'variation_id' => 0, 'name' => 'Stocked', 'quantity_scaled' => 2000000, 'quantity_scale' => 1000000, 'revenue_minor' => 2000, 'tax_minor' => 0 ),
			array( 'order_item_id' => 12, 'product_id' => 701, 'variation_id' => 0, 'name' => 'On demand', 'quantity_scaled' => 1000000, 'quantity_scale' => 1000000, 'revenue_minor' => 1500, 'tax_minor' => 0 ),
		),
		'shipping_minor'  => 0,
		'fees_minor'      => 0,
		'tax_minor'       => 0,
		'total_minor'     => 3500,
	)
);
mda_assert( 800 === (int) $sale['cogs_total_minor'], 'COGS only for the stocked line' );

// Amount-only refund → needs review, nothing posted.
$r = MDA_Order_Adjustment_Service::process( array( 'order_id' => 8001, 'type' => 'refund', 'refund_id' => 55, 'items' => array(), 'amount' => 300, 'date' => '2026-11-16' ) );
mda_assert( 'needs_review' === $r['status'], 'amount-only refund flagged for review' );

// Partial refund of 1 stocked unit.
$r = MDA_Order_Adjustment_Service::process( array( 'order_id' => 8001, 'type' => 'refund', 'refund_id' => 56, 'items' => array( array( 'order_item_id' => 11, 'quantity' => '1' ) ), 'amount' => 1000, 'date' => '2026-11-16' ) );
mda_assert( 'applied' === $r['status'], 'partial refund applied' );
mda_assert( 4000000 === (int) $db->query( 'SELECT quantity_scaled FROM mda_product_cost_state WHERE product_id=700' )->fetchColumn(), 'one unit back in stock' );

// Duplicate delivery of the same event is ignored.
$r = MDA_Order_Adjustment_Service::process( array( 'order_id' => 8001, 'type' => 'refund', 'refund_id' => 56, 'items' => array( array( 'order_item_id' => 11, 'quantity' => '1' ) ), 'amount' => 1000, 'date' => '2026-11-16' ) );
mda_assert( 'duplicate' === $r['status'], 'adjustment is idempotent' );

// Cancel → returns the rest: 1 stocked unit back to stock, on-demand backorder cancelled (no stock created).
$r = MDA_Order_Adjustment_Service::process( array( 'order_id' => 8001, 'type' => 'cancel', 'refund_id' => 0, 'items' => array(), 'date' => '2026-11-16' ) );
mda_assert( 'applied' === $r['status'], 'cancel applied' );
mda_assert( 5000000 === (int) $db->query( 'SELECT quantity_scaled FROM mda_product_cost_state WHERE product_id=700' )->fetchColumn(), 'all stocked units restored' );
mda_assert( 0 === MDA_Supply_Service::open_quantity( $db, 701, 0, $w ), 'backorder cancelled' );
mda_assert( 0 === (int) $db->query( 'SELECT COUNT(*) FROM mda_inventory_movements WHERE product_id=701' )->fetchColumn(), 'no phantom stock for the on-demand item' );
$ar = (int) $db->query( "SELECT SUM(e.debit_minor)-SUM(e.credit_minor) FROM mda_journal_entries e JOIN mda_accounts a ON a.id=e.account_id WHERE a.account_code='1200'" )->fetchColumn();
mda_assert( 0 === $ar, 'customer receivable fully reversed' );
$cogs = (int) $db->query( "SELECT SUM(e.debit_minor)-SUM(e.credit_minor) FROM mda_journal_entries e JOIN mda_accounts a ON a.id=e.account_id WHERE a.account_code='5100'" )->fetchColumn();
mda_assert( 0 === $cogs, 'COGS fully reversed' );

// Sale never posted → skipped.
$r = MDA_Order_Adjustment_Service::process( array( 'order_id' => 9999, 'type' => 'cancel', 'refund_id' => 0, 'items' => array(), 'date' => '2026-11-16' ) );
mda_assert( 'skipped' === $r['status'], 'cancel of unposted order skipped' );

echo "OK: WooCommerce cancel/refund adjustments, idempotency, review queue and backorder cancellation passed.\n";
